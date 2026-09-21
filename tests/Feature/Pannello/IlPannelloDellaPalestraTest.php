<?php

declare(strict_types=1);

namespace Tests\Feature\Pannello;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\Inviti\InvitoResource;
use App\Filament\Gym\Resources\Inviti\Pages\ListInviti;
use App\Filament\Gym\Resources\Members\Pages\CreateMember;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Members\Pages\ViewMember;
use App\Filament\Gym\Resources\Trainers\Pages\ListTrainers;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\ListWorkoutPlans;
use App\Filament\Gym\Widgets\IPostiDellaPalestra;
use App\Mail\InvitoInPalestraMail;
use App\Models\AiCreditMovement;
use App\Models\InvitoInPalestra;
use App\Models\PacchettoGettoni;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ReimpostazionePassword;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\InvitiInPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 🏋️ **Il pannello della palestra, dopo F5** — 23/09/2026.
 *
 * ══ 📌 COSA DIMOSTRA ═════════════════════════════════════════════════════
 *
 * | Fase | Cosa |
 * |---|---|
 * | **F5.1** | i posti si contano per intero: occupati, totali, liberi |
 * | **F5.3** | i due tasti d'invito stanno nel cruscotto |
 * | **F5.5** | l'invito genera un link **nuovo ogni volta**, e si può spedire |
 * | **F5.6** | via «codice palestra» e «seguito da» |
 * | **F5.7** | l'account nasce **senza password**, e la palestra non la sceglie |
 * | **F5.8** | 🚨 la palestra paga, i gettoni vanno **all'iscritto** (R1) |
 * | **F5.14** | l'elenco delle schede contiene **solo modelli** |
 */
final class IlPannelloDellaPalestraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private Tenant $palestra;

    private User $gestore;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PacchettiGettoniSeeder::class);

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->gestore = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@alfa.test');

        $this->abbona($this->palestra, 'gym_30');

        $this->stripe = $this->stripeFinto();

        filament()->setCurrentPanel('admin');
    }

    // ───────────────────────── F5.1, i posti ─────────────────────────

    /**
     * 🎫 **Occupati, totali e liberi vengono dallo stesso conto.**
     *
     * ⛔ Due conti separati sarebbero due occasioni di dire numeri che non
     * tornano fra loro.
     */
    #[Test]
    public function i_posti_si_contano_per_intero(): void
    {
        $this->creaUtente($this->palestra, UserRole::Member, 'uno@alfa.test');
        $this->creaUtente($this->palestra, UserRole::Member, 'due@alfa.test');

        $posti = app(InvitiInPalestra::class)->iPosti($this->palestra, UserRole::Member);

        $this->assertSame(2, $posti['occupati']);
        $this->assertSame(30, $posti['totali']);
        $this->assertSame(28, $posti['rimasti']);
    }

    /**
     * 🚨 **Un invito in mano a qualcuno è un posto già promesso.**
     *
     * ⛔ Contare solo gli iscritti lascerebbe mandare trenta link su un piano
     * da dieci, e il problema si vedrebbe addosso all'undicesimo.
     */
    #[Test]
    public function un_invito_valido_occupa_un_posto(): void
    {
        $prima = app(InvitiInPalestra::class)->iPosti($this->palestra)['occupati'];

        app(InvitiInPalestra::class)->invita($this->palestra, $this->gestore, 'tizio@esempio.test');

        $this->assertSame($prima + 1, app(InvitiInPalestra::class)->iPosti($this->palestra)['occupati']);
    }

    /** 🎫 E il cruscotto del gestore si apre, coi due tasti dentro. */
    #[Test]
    public function il_cruscotto_mostra_i_posti_e_i_due_tasti(): void
    {
        $this->entra($this->gestore);

        Livewire::test(IPostiDellaPalestra::class)
            ->assertSuccessful()
            ->assertSee('Iscritti')
            ->assertSee('Trainer')
            ->assertSee('Per ogni iscritto');
    }

    /**
     * 🚨 **I conti della palestra non li vede un trainer dipendente.**
     *
     * ⛔ Mostrarglieli vorrebbe dire dirgli il margine del suo datore di lavoro
     * su ogni cliente che segue.
     */
    #[Test]
    public function un_trainer_non_vede_i_conti_della_palestra(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'anna@alfa.test');

        $this->entra($trainer);

        $this->assertFalse(IPostiDellaPalestra::canView());
    }

    // ───────────────────────── F5.5, l'invito ─────────────────────────

    /**
     * 🔗 **Un link nuovo ogni volta.**
     *
     * 📌 Il committente: l'invito deve **generare un link nuovo ogni volta**.
     * ⚠️ Era già così, ma non lo diceva nessuno: chi premeva due volte poteva
     * credere di aver mandato lo stesso link a due persone.
     */
    #[Test]
    public function ogni_invito_e_un_link_nuovo(): void
    {
        $servizio = app(InvitiInPalestra::class);

        $uno = $servizio->invita($this->palestra, $this->gestore, 'a@esempio.test');
        $due = $servizio->invita($this->palestra, $this->gestore, 'b@esempio.test');

        $this->assertNotSame($uno->token, $due->token);
        $this->assertSame(2, InvitoInPalestra::withoutGlobalScopes()->count());
    }

    /** 📧 E si può spedire davvero (oggi nel log, ma parte). */
    #[Test]
    public function l_invito_si_manda_per_email(): void
    {
        Mail::fake();

        $servizio = app(InvitiInPalestra::class);
        $invito = $servizio->invita($this->palestra, $this->gestore, 'tizio@esempio.test');

        $this->assertTrue($servizio->mandaIlLink($invito));

        Mail::assertSent(
            InvitoInPalestraMail::class,
            fn (InvitoInPalestraMail $m): bool => $m->hasTo('tizio@esempio.test'),
        );
    }

    /** ⛔ Senza indirizzo non c'è niente da spedire, e non si finge. */
    #[Test]
    public function senza_indirizzo_la_mail_non_parte(): void
    {
        Mail::fake();

        $servizio = app(InvitiInPalestra::class);
        $invito = $servizio->invita($this->palestra, $this->gestore);

        $this->assertFalse($servizio->mandaIlLink($invito));

        Mail::assertNothingSent();
    }

    /**
     * ⛔ **Il «codice della palestra» non c'è più** — F5.6.
     *
     * 📌 *«Togliamo proprio tutto il discorso dei codici delle palestre»*.
     */
    #[Test]
    public function l_elenco_degli_iscritti_non_offre_piu_il_codice(): void
    {
        $this->entra($this->gestore);

        Livewire::test(ListMembers::class)
            ->assertSuccessful()
            ->assertDontSee('Codice della palestra')
            ->assertDontSee($this->palestra->join_code);
    }

    /** 🔗 E i due elenchi hanno il tasto d'invito. */
    #[Test]
    public function i_due_elenchi_hanno_il_tasto_d_invito(): void
    {
        $this->entra($this->gestore);

        Livewire::test(ListMembers::class)->assertActionExists('invita');
        Livewire::test(ListTrainers::class)->assertActionExists('invita');
    }

    // ───────────────────────── F5.7, niente password ─────────────────────────

    /**
     * 🚨 **La palestra non sceglie la password di nessuno.**
     *
     * ⛔ Sceglierla significa conoscerla: poter entrare nell'app al posto di
     * quella persona, e da lì leggerne le chat e gli allenamenti.
     */
    #[Test]
    public function il_modulo_dell_iscritto_non_chiede_la_password(): void
    {
        $this->entra($this->gestore);

        $campi = array_keys(Livewire::test(CreateMember::class)->instance()->form->getFlatFields());

        $this->assertNotContains('password', $campi);
        // ⛔ E nemmeno le assegnazioni: F5.6.
        $this->assertNotContains('trainers', $campi);
    }

    /** ✅ L'account nasce senza password, e a lui parte il link per sceglierla. */
    #[Test]
    public function un_iscritto_creato_a_mano_nasce_senza_password(): void
    {
        Mail::fake();

        $this->entra($this->gestore);

        Livewire::test(CreateMember::class)
            ->fillForm([
                'name' => 'Mario Rossi',
                'email' => 'mario@alfa.test',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $nato = User::withoutGlobalScopes()->where('email', 'mario@alfa.test')->sole();

        $this->assertFalse((bool) $nato->password_is_set);
        $this->assertSame(1, ReimpostazionePassword::query()->where('user_id', $nato->getKey())->count());
    }

    /** ⛔ E la pagina di modifica non esiste: la scheda si guarda. */
    #[Test]
    public function la_scheda_dell_iscritto_si_apre(): void
    {
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');

        $this->entra($this->gestore);

        Livewire::test(ViewMember::class, ['record' => $iscritto->getKey()])
            ->assertSuccessful()
            ->assertSee('mario@alfa.test')
            ->assertActionExists('manda_il_reset')
            ->assertActionExists('regala_gettoni')
            ->assertActionExists('disiscrivi');
    }

    // ───────────────────────── F5.8, i gettoni regalati ─────────────────────────

    /**
     * ══ 🎁 PAGA LA PALESTRA, I GETTONI VANNO ALL'ISCRITTO (R1) ════════════
     *
     * 📌 *«voglio che la palestra possa acquistare dei token da dare
     * all'allievo … Quelli acquistati in questo modo devono andare direttamente
     * all'allievo»*.
     */
    #[Test]
    public function la_palestra_compra_gettoni_per_un_iscritto(): void
    {
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');

        // 1️⃣ Si apre il pagamento: paga il gestore, e il beneficiario è scritto.
        $url = app(Cassa::class)->apri(
            $this->gestore,
            Cassa::GETTONI,
            gettoni: 500,
            perConto: $iscritto,
        );

        $this->assertStringStartsWith('https://checkout.stripe.test/', $url);

        $mandato = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame((string) $iscritto->getKey(), $mandato['metadata']['beneficiario_id']);

        // 🚨 Il prezzo lo mette il listino, mai la pagina.
        $this->assertSame(
            PacchettoGettoni::query()->where('gettoni', 500)->sole()->prezzo_cent,
            $mandato['line_items'][0]['price_data']['unit_amount'],
        );

        // 2️⃣ Il webhook accredita: all'iscritto, non a chi ha pagato.
        app(Cassa::class)->applica([
            'id' => 'evt_regalo',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_regalo',
                'mode' => 'payment',
                'amount_total' => 1250,
                'metadata' => $mandato['metadata'],
            ]],
        ]);

        $this->assertSame(500, $iscritto->refresh()->ai_credits);
        $this->assertSame(0, (int) $this->gestore->refresh()->ai_credits);

        // 3️⃣ E resta scritto da dove vengono.
        $movimento = AiCreditMovement::withoutGlobalScopes()
            ->where('user_id', $iscritto->getKey())
            ->sole();

        $this->assertSame(AiCreditMovement::REGALO_PALESTRA, $movimento->causale);
        $this->assertSame($this->palestra->getKey(), $movimento->pagato_da_tenant_id);
    }

    /**
     * 🚨 **Non si regala a un iscritto di un'altra palestra.**
     *
     * ⛔ Senza il controllo, un id scritto a mano accrediterebbe i gettoni a
     * chiunque sulla piattaforma — pagati da una palestra che credeva di
     * regalarli a un suo allievo.
     */
    #[Test]
    public function non_si_regalano_gettoni_a_chi_non_e_dei_nostri(): void
    {
        $altra = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $estraneo = $this->creaUtente($altra, UserRole::Member, 'clara@beta.test');

        app(Cassa::class)->apri(
            $this->gestore,
            Cassa::GETTONI,
            gettoni: 500,
            perConto: $estraneo,
        );

        $mandato = $this->stripe->ultima('checkout.sessions.create')[0];

        // ⛔ Il beneficiario è stato scartato: è un acquisto normale.
        $this->assertSame('', $mandato['metadata']['beneficiario_id']);

        app(Cassa::class)->applica([
            'id' => 'evt_estraneo',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_estraneo',
                'mode' => 'payment',
                'amount_total' => 1250,
                'metadata' => $mandato['metadata'],
            ]],
        ]);

        $this->assertSame(0, (int) $estraneo->refresh()->ai_credits);
        $this->assertSame(500, $this->gestore->refresh()->ai_credits);
    }

    // ───────────────────────── F5.14, solo modelli ─────────────────────────

    /**
     * 📄 **L'elenco delle schede contiene solo modelli**, e non ha più né
     * «assegnata a» né «stato».
     *
     * ⚠️ Serve una scheda vera: con l'elenco vuoto Filament disegna lo stato
     * vuoto e **nessuna colonna**, quindi il test passerebbe per il motivo
     * sbagliato.
     */
    #[Test]
    public function l_elenco_delle_schede_mostra_chi_le_ha_scritte(): void
    {
        $mio = app(TenantContext::class)->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'name' => 'Full body A',
            'status' => PlanStatus::Published,
            'created_by' => $this->gestore->getKey(),
        ]));

        // 🚨 Una scheda con un destinatario: è dell'iscritto, e qui non si vede.
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');

        $sua = app(TenantContext::class)->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'name' => 'La scheda di Mario',
            'status' => PlanStatus::Published,
            'member_id' => $iscritto->getKey(),
            'created_by' => $this->gestore->getKey(),
        ]));

        $this->entra($this->gestore);

        Livewire::test(ListWorkoutPlans::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mio])
            ->assertCanNotSeeTableRecords([$sua])
            ->assertDontSee('Assegnata a')
            ->assertSee('Scritta da');
    }

    // ─────────────── 🆕 la finestra dell'invito: il link prima, l'email se vuoi ───────────────

    /**
     * 🔗 **Il link nasce aprendo la finestra**, e si vede lì. 📌 *«Deve subito
     * generare un link di invito (e io lo devo vedere nella modale e poterlo
     * copiare)»*. Chiudendo senza email non parte niente.
     */
    #[Test]
    public function aprendo_l_invito_il_link_c_e_gia_e_l_email_non_serve(): void
    {
        Mail::fake();
        $this->entra($this->gestore);

        $pagina = Livewire::test(ListMembers::class)->mountAction('invita');

        $invito = app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->sole());
        $link = url("/invito-palestra/{$invito->token}");

        // ⚠️ Il campo lo riempie Livewire nel browser: si guarda lo stato della finestra aperta.
        $this->assertSame($link, $this->statoDellInvito($pagina)['link']);

        // «Fatto» senza nessun indirizzo: nessun errore, l'email non è obbligatoria.
        $pagina->callMountedAction()
            ->assertHasNoActionErrors();

        Mail::assertNothingSent();
        $this->assertSame(1, app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->count()));
    }

    /** 📧 Con un indirizzo, lo stesso link parte per email (e l'indirizzo resta sull'invito). */
    #[Test]
    public function con_un_indirizzo_lo_stesso_link_parte_per_email(): void
    {
        Mail::fake();
        $this->entra($this->gestore);

        Livewire::test(ListMembers::class)
            ->mountAction('invita')
            ->fillForm(['email' => 'mario@esempio.test'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $invito = app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->sole());

        $this->assertSame('mario@esempio.test', $invito->email);
        Mail::assertSent(InvitoInPalestraMail::class, fn (InvitoInPalestraMail $m): bool => $m->hasTo('mario@esempio.test'));
    }

    /** 🏋️ Uguale per i trainer: il link è da trainer. */
    #[Test]
    public function vale_anche_per_i_trainer(): void
    {
        Mail::fake();
        $this->entra($this->gestore);

        $pagina = Livewire::test(ListTrainers::class)->mountAction('invita');

        $invito = app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->sole());

        $this->assertSame(UserRole::Trainer->value, $invito->ruolo instanceof UserRole ? $invito->ruolo->value : $invito->ruolo);
        $this->assertSame(url("/invito-palestra/{$invito->token}"), $this->statoDellInvito($pagina)['link']);
        $pagina->callMountedAction();

        Mail::assertNothingSent();
    }

    /** ⛔ Posti finiti: niente link, e la finestra dice perché. */
    #[Test]
    public function senza_posti_niente_link_e_il_perche(): void
    {
        $posti = app(InvitiInPalestra::class)->iPosti($this->palestra, UserRole::Trainer);

        for ($i = 0; $i < $posti['totali']; $i++) {
            $this->creaUtente($this->palestra, UserRole::Trainer, "t{$i}@alfa.test");
        }

        $this->entra($this->gestore);

        $pagina = Livewire::test(ListTrainers::class)
            ->mountAction('invita');

        $stato = $this->statoDellInvito($pagina);
        $this->assertNull($stato['link']);
        $this->assertNotEmpty($stato['errore'], 'La finestra deve dire perché il link non c\'è.');

        $this->assertSame(0, app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->count()));
    }

    // ─────────────── 🆕 la pagina degli inviti, con la revoca ───────────────

    /**
     * 🔗 **La lista degli inviti fatti, e la revoca libera il posto.** 📌 *«mi
     * serve anche una pagina con la lista degli inviti fatti così li posso
     * revocare»*.
     */
    #[Test]
    public function gli_inviti_si_vedono_e_si_revocano(): void
    {
        $inviti = app(InvitiInPalestra::class);
        $mio = $inviti->invita($this->palestra, $this->gestore, 'tizio@esempio.test');

        $altra = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $capoBeta = $this->creaUtente($altra, UserRole::GymAdmin, 'capo@beta.test');
        $suo = $inviti->invita($altra, $capoBeta, 'segreto@beta.test');

        $prima = $inviti->iPosti($this->palestra)['occupati'];

        $this->entra($this->gestore);

        Livewire::test(ListInviti::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mio])
            ->assertCanNotSeeTableRecords([$suo])
            ->assertDontSee('segreto@beta.test')
            ->callTableAction('revoca', $mio)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($mio->refresh()->revoked_at);
        $this->assertSame($prima - 1, $inviti->iPosti($this->palestra)['occupati'], 'Revocato, il posto torna libero.');
        $this->assertSame('Revocato', InvitoResource::stato($mio));
    }

    /** 📧 Dalla lista un invito si rimanda per email: lo **stesso** link, non uno nuovo. */
    #[Test]
    public function dalla_lista_un_invito_si_rimanda_per_email(): void
    {
        Mail::fake();
        $invito = app(InvitiInPalestra::class)->invita($this->palestra, $this->gestore);

        $this->entra($this->gestore);

        Livewire::test(ListInviti::class)
            ->assertTableActionVisible('copia', $invito)
            ->callTableAction('manda', $invito, ['email' => 'mario@esempio.test'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('mario@esempio.test', $invito->refresh()->email);
        $this->assertSame(1, app(TenantContext::class)->runWithoutTenant(fn () => InvitoInPalestra::query()->count()), 'Nessun link nuovo.');
        Mail::assertSent(InvitoInPalestraMail::class, fn (InvitoInPalestraMail $m): bool => $m->hasTo('mario@esempio.test')
            && $m->invito->is($invito));
    }

    /** Un invito revocato non si copia e non si rimanda. */
    #[Test]
    public function un_invito_finito_non_si_copia_ne_si_rimanda(): void
    {
        $invito = app(InvitiInPalestra::class)->invita($this->palestra, $this->gestore);
        app(InvitiInPalestra::class)->revoca($invito);

        $this->entra($this->gestore);

        Livewire::test(ListInviti::class)
            ->assertTableActionHidden('copia', $invito)
            ->assertTableActionHidden('manda', $invito)
            ->assertTableActionHidden('revoca', $invito);
    }

    /** ⛔ Lo staff non gestisce gli inviti della palestra. */
    #[Test]
    public function un_trainer_non_vede_gli_inviti(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'anna@alfa.test');

        $this->entra($trainer);

        $this->assertFalse(InvitoResource::canAccess());
    }

    // ───────────────────────── aiuti ─────────────────────────

    /** @return array<string, mixed> i campi della finestra d'invito aperta */
    private function statoDellInvito(mixed $pagina): array
    {
        return (array) ($pagina->instance()->mountedActions[0]['data'] ?? []);
    }

    private function entra(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(TenantContext::class)->set($utente->tenant);
    }

    private function abbona(Tenant $palestra, string $codice): void
    {
        app(TenantContext::class)->runWithoutTenant(fn () => PlanSubscription::query()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => Plan::query()->where('code', $codice)->sole()->getKey(),
            'stripe_subscription_id' => 'sub_'.$palestra->getKey(),
            'stripe_customer_id' => 'cus_'.$palestra->getKey(),
            'rinnova' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]));
    }
}
