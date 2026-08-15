<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\God\Resources\Users\Pages\EditUser;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * La quota AI decisa a mano dal pannello `/god` — 14/08/2026.
 *
 * ── 🚨 Perche' questa classe esiste ───────────────────────────────────────
 *
 * `ai_monthly_call_cap` e `ai_monthly_photo_call_cap` sono **fuori da
 * `$fillable`** di proposito: una concessione non si assegna in massa da una
 * richiesta HTTP.
 *
 * ⚠️ Il prezzo e' che `EditRecord`, che salva con `fill()`, li **scarterebbe in
 * silenzio**: il modulo direbbe «salvato» e il tetto resterebbe quello di prima.
 * E' un guasto che nessun test di apertura pagina vede — e' la stessa forma
 * dell'errore di `G6`, dove i test aprivano le pagine ma non le salvavano.
 *
 * 🚨 **E c'e' una convenzione controintuitiva da difendere**: `null` vuol dire
 * «non decide questo livello», **`0` vuol dire ILLIMITATO**. Un `(int)` su un
 * campo vuoto trasformerebbe «lascia come le altre» in «illimitato», e nessuno
 * se ne accorgerebbe finche' non arriva la fattura del modello.
 */
final class QuotaDalPannelloTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $god;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
        $this->god = $this->creaSuperAdmin();

        filament()->setCurrentPanel('god');
        $this->actingAs($this->god);
    }

    // ───────────────────────── il modulo ─────────────────────────

    #[Test]
    public function the_form_actually_writes_a_cap_that_is_not_fillable(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['ai_monthly_call_cap' => 42, 'ai_monthly_photo_call_cap' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresco = $this->iscritto->fresh();

        // 🚨 Senza `handleRecordUpdate()` questi due resterebbero `null` e il
        // modulo direbbe comunque «salvato».
        $this->assertSame(42, $fresco->ai_monthly_call_cap);
        $this->assertSame(7, $fresco->ai_monthly_photo_call_cap);
    }

    #[Test]
    public function zero_means_unlimited_and_the_chain_agrees(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['ai_monthly_call_cap' => 0, 'ai_monthly_photo_call_cap' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresco = $this->iscritto->fresh();

        $this->assertSame(0, $fresco->ai_monthly_call_cap);

        /*
         * 🎯 **La riga che conta davvero.** Scrivere `0` in tabella non serve a
         * niente se poi `MemberAiQuota` non lo legge come «illimitato»: qui si
         * verifica il **comportamento**, non la colonna.
         *
         * ⚠️ `capFor()` torna `null` per «illimitato» — che e' lo stesso valore
         * che in tabella significa l'opposto. Le due convenzioni si incrociano
         * esattamente qui.
         */
        $quota = app(MemberAiQuota::class);

        $this->assertNull($quota->capFor($fresco));
        $this->assertNull($quota->capFor($fresco, true));
        $this->assertNull($quota->remaining($fresco));
    }

    #[Test]
    public function an_empty_field_is_null_not_zero(): void
    {
        $this->iscritto->forceFill(['ai_monthly_call_cap' => 99])->save();

        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['ai_monthly_call_cap' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        /*
         * 🚨 **E' il difetto piu' facile da scrivere di tutta questa modifica.**
         * Un `(int)` sul campo vuoto darebbe `0`, cioe' **illimitato**: chi
         * voleva togliere un'eccezione avrebbe regalato l'AI senza tetto, e il
         * modulo avrebbe detto «salvato».
         */
        $this->assertNull($this->iscritto->fresh()->ai_monthly_call_cap);
    }

    #[Test]
    public function changing_the_quota_leaves_a_trace(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['ai_monthly_call_cap' => 0])
            ->call('save');

        $riga = AuditLog::query()
            ->where('action', AuditAction::AiQuotaChanged->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($riga, 'una concessione senza traccia non si puo\' contestare');

        // 💡 Nel registro lo `0` e' scritto per quello che **significa**: un
        // registro che dice «0» si legge come «niente», cioe' il contrario.
        $this->assertSame('ILLIMITATO', $riga->payload['dopo']['chiamate'] ?? null);
    }

    #[Test]
    public function saving_without_touching_the_quota_writes_nothing(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['name' => 'Nome Nuovo'])
            ->call('save')
            ->assertHasNoFormErrors();

        // ⚠️ Una riga a **ogni** salvataggio riempirebbe il registro di «non e'
        // cambiato niente», e un registro rumoroso non lo legge nessuno.
        $this->assertSame(
            0,
            AuditLog::query()->where('action', AuditAction::AiQuotaChanged->value)->count(),
        );
    }

    // ───────────────────── l'azione dall'elenco ─────────────────────

    #[Test]
    public function the_shortcut_grants_unlimited_in_one_touch(): void
    {
        Livewire::test(ListUsers::class)
            ->callTableAction('ai_illimitata', $this->iscritto);

        $fresco = $this->iscritto->fresh();

        $this->assertSame(0, $fresco->ai_monthly_call_cap);
        $this->assertSame(0, $fresco->ai_monthly_photo_call_cap);
        $this->assertNull(app(MemberAiQuota::class)->capFor($fresco));
    }

    #[Test]
    public function the_shortcut_is_a_switch_it_also_takes_it_away(): void
    {
        $this->iscritto->forceFill([
            'ai_monthly_call_cap' => 0,
            'ai_monthly_photo_call_cap' => 0,
        ])->save();

        Livewire::test(ListUsers::class)
            ->callTableAction('ai_illimitata', $this->iscritto);

        /*
         * 🚨 Torna a `null` — «come le altre» — e **non** a un numero inventato.
         * ⚠️ Una concessione che si da' e non si toglie dallo stesso posto e'
         * una concessione che resta accesa per sempre.
         */
        $fresco = $this->iscritto->fresh();

        $this->assertNull($fresco->ai_monthly_call_cap);
        $this->assertNull($fresco->ai_monthly_photo_call_cap);
    }

    #[Test]
    public function the_shortcut_leaves_a_trace_too(): void
    {
        Livewire::test(ListUsers::class)
            ->callTableAction('ai_illimitata', $this->iscritto);

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::AiQuotaChanged->value)->count(),
        );
    }

    // ───────────────────── quello che NON deve fare ─────────────────────

    /**
     * 🚨 **Il difetto del 14/08, e la ragione per cui questo test e' cambiato.**
     *
     * Il giorno prima questo test si chiamava
     * `unlimited_does_not_turn_the_ai_on_for_a_plan_without_it` e fissava che
     * «illimitata» toccasse **solo** la quota. Era coerente con il codice, ed
     * era **il difetto**: su dati veri l'utente #13 dello staging aveva quota
     * illimitata e `ai=no`, quindi il pulsante non serviva a niente.
     *
     * ⚠️ Un test puo' fissare con precisione un comportamento sbagliato. Questo
     * lo faceva, ed e' il motivo per cui la suite era verde mentre la funzione
     * non funzionava.
     *
     * 💡 Adesso l'azione consegna **entrambe** le cose, perche' e' quello che il
     * suo nome promette. Restano due domande distinte — e il test qui sotto
     * (`the_gate_and_the_cap_stay_two_separate_questions`) difende proprio
     * quella distinzione, che continua a valere.
     */
    /**
     * ⚠️ **Serve chi si registra da solo, non un iscritto di palestra.**
     *
     * La palestra di prova ha un piano **con** l'AI, quindi i suoi iscritti ce
     * l'hanno gia': su di loro questo test non proverebbe niente. Chi si
     * registra per conto proprio prende un tenant personale sul piano `free`,
     * che l'AI non ce l'ha — ed e' esattamente lo stato in cui si trovava
     * l'utente #13 dello staging.
     */
    private function utenteLibero(string $email = 'libero@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)(
            'Persona Libera',
            $email,
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );
    }

    #[Test]
    public function unlimited_also_turns_the_ai_on(): void
    {
        $libero = $this->utenteLibero();

        // Un piano senza AI: e' il caso di chiunque si registri da solo.
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($libero));

        Livewire::test(ListUsers::class)
            ->callTableAction('ai_illimitata', $libero);

        $fresco = $libero->fresh();

        $this->assertTrue($fresco->ai_enabled_override);
        $this->assertSame(0, $fresco->ai_monthly_call_cap);

        // 🎯 La riga che conta: il **cancello** la lascia passare.
        $this->assertTrue(app(PianoAttivo::class)->haLaAi($fresco));
    }

    #[Test]
    public function removing_it_gives_back_the_plan_and_does_not_switch_the_ai_off(): void
    {
        Livewire::test(ListUsers::class)->callTableAction('ai_illimitata', $this->iscritto);
        Livewire::test(ListUsers::class)->callTableAction('ai_illimitata', $this->iscritto->fresh());

        /*
         * 🚨 Torna a `null` — «come dice il piano» — e **non** a `false`.
         * ⚠️ Sono tre valori: `false` **spegnerebbe** l'AI anche a chi il piano
         * gliela darebbe, cioe' toglierebbe piu' di quello che era stato dato.
         */
        $this->assertNull($this->iscritto->fresh()->ai_enabled_override);
        $this->assertNull($this->iscritto->fresh()->ai_monthly_call_cap);
    }

    #[Test]
    public function the_gate_and_the_cap_stay_two_separate_questions(): void
    {
        /*
         * 💡 L'azione le accende insieme perche' e' comodo, **ma restano due
         * domande**: dal modulo si puo' dare il tetto senza il cancello, ed e'
         * giusto che si possa — e' come si prepara una persona che avra' l'AI
         * dal piano il mese prossimo.
         */
        $libero = $this->utenteLibero();

        Livewire::test(EditUser::class, ['record' => $libero->getKey()])
            ->fillForm(['ai_monthly_call_cap' => 0, 'ai_enabled_override' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresco = $libero->fresh();

        $this->assertSame(0, $fresco->ai_monthly_call_cap);
        $this->assertNull($fresco->ai_enabled_override);

        // Tetto illimitato, cancello chiuso: e' esattamente lo stato in cui si
        // trovava l'utente #13 dello staging il 14/08.
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($fresco));
    }

    #[Test]
    public function the_ai_can_also_be_switched_off_for_one_person(): void
    {
        /*
         * 🚨 **Il terzo valore, e non e' simmetria decorativa.** E' il solo modo
         * di guardare cosa vede chi **non** ha l'AI senza smontare il piano di
         * una palestra intera — e il percorso «senza AI» e' meta' del prodotto.
         */
        $conAi = $this->creaUtente($this->palestra, UserRole::Member, 'conai@alfa.test');
        $conAi->forceFill(['ai_enabled_override' => true])->save();

        $this->assertTrue(app(PianoAttivo::class)->haLaAi($conAi->fresh()));

        Livewire::test(EditUser::class, ['record' => $conAi->getKey()])
            ->fillForm(['ai_enabled_override' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($conAi->fresh()->ai_enabled_override);
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($conAi->fresh()));
    }

    #[Test]
    public function an_empty_gate_is_null_not_false(): void
    {
        $this->iscritto->forceFill(['ai_enabled_override' => true])->save();

        Livewire::test(EditUser::class, ['record' => $this->iscritto->getKey()])
            ->fillForm(['ai_enabled_override' => ''])
            ->call('save');

        /*
         * ⚠️ **Lo stesso errore di `an_empty_field_is_null_not_zero`, sull'altro
         * campo e con l'altro tipo.** Un `(bool)` sulla stringa vuota darebbe
         * `false`, cioe' **spegnerebbe** l'AI a chi voleva solo togliere
         * l'eccezione e tornare a quello che dice il piano.
         */
        $this->assertNull($this->iscritto->fresh()->ai_enabled_override);
    }

    #[Test]
    public function a_gym_admin_cannot_reach_the_god_panel(): void
    {
        $admin = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'admin@alfa.test');

        // 🚨 La concessione piu' pericolosa del pannello non deve essere
        // raggiungibile da chi amministra una palestra: alzerebbe la propria
        // quota a spese nostre.
        $this->actingAs($admin)
            ->get('/god/users')
            ->assertStatus(403);
    }
}
