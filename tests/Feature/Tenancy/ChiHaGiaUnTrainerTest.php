<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\Exceptions\HaiGiaUnTrainerException;
use App\Services\Tenancy\InvitiDelTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 👥 Chi ha già un trainer lo sa — 3b-AH.1-ter.4 e .5, 17/09/2026.
 *
 * ══ 📌 COSA MANCAVA ═══════════════════════════════════════════════════════
 *
 * 📌 Dal piano: *«la modale della sovrapposizione, con "Lascia com'è" in
 * evidenza»* e *«ricordare la risposta, per non richiederla ogni volta»*.
 *
 * ══ ⛔ COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * **Il secondo rapporto nasceva in silenzio.** Una persona seguita da Anna
 * accettava l'invito di Marco e si ritrovava due trainer senza che nessuno
 * glielo avesse detto.
 *
 * 🚨 Da quel momento **tutti e due** vedono i suoi allenamenti, **tutti e due**
 * le scrivono, e **tutti e due** le tengono occupato uno slot. ⚠️ Tre cose che
 * una persona ha il diritto di sapere **prima**.
 */
class ChiHaGiaUnTrainerTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private function unTrainer(string $email): User
    {
        return app(CreaTenantPersonale::class)(
            ucfirst(explode('@', $email)[0]),
            $email,
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );
    }

    /** Una persona con uno spazio suo, pronta a ricevere un invito. */
    private function unaPersona(string $email = 'allievo@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)(
            'Allievo',
            $email,
            ['password' => self::FAKE_PASSWORD],
        );
    }

    /** Il rapporto uno-a-uno, com'è nella vita: nello spazio del trainer. */
    private function seguitaDa(User $persona, User $trainer): void
    {
        DB::table('trainer_member')->insert([
            'trainer_id' => $trainer->getKey(),
            'member_id' => $persona->getKey(),
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invitoDi(User $trainer): TrainerInvite
    {
        return TrainerInvite::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'trainer_id' => $trainer->getKey(),
            'token' => bin2hex(random_bytes(16)),
            'expires_at' => now()->addWeek(),
        ]);
    }

    private function riscatta(TrainerInvite $invito, User $chi, bool $affianco = false): User
    {
        return app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            $invito->token,
            $chi,
            $affianco,
        );
    }

    // ─────────────── 🚨 Non nasce più in silenzio ───────────────

    /**
     * 🚨 **Il difetto vero**: due trainer senza che nessuno l'abbia detto.
     */
    #[Test]
    public function un_secondo_trainer_non_arriva_di_nascosto(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        $invito = $this->invitoDi($marco);

        $this->expectException(HaiGiaUnTrainerException::class);

        $this->riscatta($invito, $persona->fresh());
    }

    /** ⚖️ E dicendo di sì si procede: la domanda è una domanda, non un divieto. */
    #[Test]
    public function ma_dicendo_di_si_si_affianca(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        $this->riscatta($this->invitoDi($marco), $persona->fresh(), affianco: true);

        $fresca = User::withoutGlobalScopes()->findOrFail($persona->getKey());

        $this->assertEqualsCanonicalizing(
            [$anna->getKey(), $marco->getKey()],
            $fresca->assignedTrainers()->pluck('users.id')->all(),
            'Dicendo di si non si e affiancato nessuno.',
        );
    }

    /**
     * 🚨 **La risposta si ricorda** — AH.1-ter.5, *«per non richiederla ogni
     * volta»*.
     *
     * 💡 Chi ha già detto di sì una volta sa come funziona: rifargli la domanda
     * a ogni invito sarebbe trattarlo come se non avesse capito.
     */
    #[Test]
    public function chi_ha_gia_risposto_non_se_lo_sente_richiedere(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');
        $lucia = $this->unTrainer('lucia@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        // 🎯 La prima volta risponde di sì.
        $this->riscatta($this->invitoDi($marco), $persona->fresh(), affianco: true);

        $this->assertNotNull(
            User::withoutGlobalScopes()->findOrFail($persona->getKey())->affiancamento_accettato_il,
            'La risposta non e stata ricordata.',
        );

        // 💡 La terza volta non chiede più: passa senza `affianco`.
        $this->riscatta($this->invitoDi($lucia), $persona->fresh());

        $this->assertCount(
            3,
            User::withoutGlobalScopes()->findOrFail($persona->getKey())
                ->assignedTrainers()->get(),
            'Il terzo trainer non e stato aggiunto.',
        );
    }

    // ─────────────── ⚖️ Quando NON si chiede ───────────────

    /**
     * ⛔ **Un rapporto chiuso non è un trainer che segue.**
     *
     * ⚠️ Chiedere conferma per un rapporto finito mesi fa vorrebbe dire far
     * ricordare a qualcuno una cosa che ha già chiuso.
     */
    #[Test]
    public function un_rapporto_chiuso_non_conta(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        DB::table('trainer_member')
            ->where('member_id', $persona->getKey())
            ->update(['disattivato_il' => now()]);

        // ⚖️ Nessuna eccezione: non c'è nessuna sovrapposizione da dichiarare.
        $this->riscatta($this->invitoDi($marco), $persona->fresh());

        $this->assertTrue(
            User::withoutGlobalScopes()->findOrFail($persona->getKey())
                ->assignedTrainers()->where('users.id', $marco->getKey())->exists(),
        );
    }

    /** ⛔ E chi non ha nessun trainer entra senza domande. */
    #[Test]
    public function chi_non_ne_ha_nessuno_entra_e_basta(): void
    {
        $marco = $this->unTrainer('marco@esempio.test');
        $persona = $this->unaPersona();

        $this->riscatta($this->invitoDi($marco), $persona->fresh());

        $this->assertTrue(
            User::withoutGlobalScopes()->findOrFail($persona->getKey())
                ->assignedTrainers()->where('users.id', $marco->getKey())->exists(),
        );

        // 💡 E non ha dichiarato niente: non gli è stato chiesto niente.
        $this->assertNull(
            User::withoutGlobalScopes()->findOrFail($persona->getKey())->affiancamento_accettato_il,
        );
    }

    /**
     * 🚨 **Gli iscritti di una palestra non ci finiscono dentro**, e sarebbe
     * l'errore facile.
     *
     * 💡 Loro vedono tutti i trainer della palestra **per costruzione**
     * (`ChiSiVede`), senza nessuna riga in `trainer_member`. ⚠️ Contarli come
     * «hai già un trainer» vorrebbe dire una domanda che non c'entra, fatta a
     * ogni iscritto di ogni palestra.
     */
    #[Test]
    public function gli_iscritti_di_una_palestra_non_contano(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $marco = $this->unTrainer('marco@esempio.test');

        // ⚖️ Nessuna eccezione: in palestra nessuno «lo segue» uno a uno.
        $this->riscatta($this->invitoDi($marco), $iscritto->fresh());

        $this->assertTrue(
            User::withoutGlobalScopes()->findOrFail($iscritto->getKey())
                ->assignedTrainers()->where('users.id', $marco->getKey())->exists(),
        );
    }

    // ─────────────── 📡 Cosa arriva all'app ───────────────

    /**
     * 🚨 **Un codice stabile e il nome dell'altro**, non solo una frase.
     *
     * ⚠️ L'app deve poter scrivere *«Ti segue già Anna»* dentro la modale, e da
     * sola quel nome non ce l'ha: sta guardando un invito, non l'elenco dei
     * propri trainer.
     */
    #[Test]
    public function l_app_riceve_il_codice_e_il_nome(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        $invito = $this->invitoDi($marco);

        $this->comeApp($persona->fresh())
            ->postJson("/api/v1/inviti/{$invito->token}/riscatta")
            ->assertStatus(409)
            ->assertJsonPath('code', 'hai_gia_un_trainer')
            ->assertJsonPath('chi_ti_segue', $anna->name);
    }

    /**
     * 🆕 👀 **L'anteprima, che prima non esisteva** — 17/09/2026.
     *
     * ⛔ La pagina web diceva *«apri l'app e tocca "Ho un invito"»*, e quel
     * pulsante non esisteva: i due endpoint che accettano un invito da trainer
     * non li chiamava nessuno. 🚨 L'intero giro viveva sul server senza un
     * cliente, e per un invito di trainer non c'era **nessuna** strada.
     */
    #[Test]
    public function l_anteprima_dice_chi_invita_e_niente_altro(): void
    {
        $marco = $this->unTrainer('marco@esempio.test');
        $invito = $this->invitoDi($marco);

        $risposta = $this->getJson("/api/v1/inviti/{$invito->token}")
            ->assertOk()
            ->assertJsonPath('data.trainer', $marco->name);

        // ⛔ E niente altro: il token gira nelle chat, e da un'anteprima
        // pubblica non deve uscire niente su cui costruire un profilo.
        $this->assertEqualsCanonicalizing(
            ['trainer', 'scade_il'],
            array_keys($risposta->json('data')),
        );
    }

    /** ⛔ E un invito bruciato non dice **perché**: solo che non vale piu'. */
    #[Test]
    public function un_invito_non_valido_non_dice_perche(): void
    {
        $marco = $this->unTrainer('marco@esempio.test');
        $invito = $this->invitoDi($marco);

        $invito->forceFill(['used_at' => now()])->save();

        $this->getJson("/api/v1/inviti/{$invito->token}")->assertNotFound();
        $this->getJson('/api/v1/inviti/unTokenInventatoDiSanaPianta')->assertNotFound();
    }

    /** ⚖️ E con il sì esplicito la stessa richiesta passa. */
    #[Test]
    public function e_con_il_si_la_stessa_richiesta_passa(): void
    {
        $anna = $this->unTrainer('anna@esempio.test');
        $marco = $this->unTrainer('marco@esempio.test');

        $persona = $this->unaPersona();
        $this->seguitaDa($persona, $anna);

        $invito = $this->invitoDi($marco);

        $this->comeApp($persona->fresh())
            ->postJson("/api/v1/inviti/{$invito->token}/riscatta", ['affianco' => true])
            ->assertOk();
    }
}
