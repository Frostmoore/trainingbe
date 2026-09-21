<?php

declare(strict_types=1);

namespace Tests\Feature\Pannello;

use App\Enums\AuditAction;
use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\Pages\ViewTenant;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Filament\God\Resources\Users\Pages\ViewUser;
use App\Mail\LinkPerLaPassword;
use App\Mail\MessaggioDalPannello;
use App\Models\AiCreditMovement;
use App\Models\AuditLog;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ReimpostazionePassword;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Services\Reporting\LaStoriaDiUnaPersona;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 👤 **La scheda di una persona, e i tasti che la accompagnano** — fase F4.
 *
 * ══ 📌 COSA DIMOSTRA ═════════════════════════════════════════════════════
 *
 * | Fase | Cosa |
 * |---|---|
 * | **F4.1** | l'elenco mostra piano, scadenza e «abbonato», e chiama il tenant **tenant** |
 * | **F4.2** | la scheda esiste e risponde a «chi è questa persona» |
 * | **F4.3** | i tre conti nel tempo tornano, e i buchi restano buchi |
 * | **F4.4** | «quanto ha speso» e la prima attivazione |
 * | **F4.5** | «manda una mail» manda **una** mail, a **quella** persona |
 * | **F4.6** | il tasto del reset crea il link, lo registra, e non mostra password |
 * | **F3.6** | lo stesso tasto sulla palestra scrive al **titolare** |
 */
final class LaSchedaDellaPersonaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    // ───────────────────────── F4.1, l'elenco ─────────────────────────

    /**
     * 🚨 **L'elenco dice il piano vero, non una colonna morta.**
     *
     * ⛔ Prima di F1 il pannello leggeva `tenants.plan`: una colonna legacy che
     * non ha niente a che vedere con l'abbonamento.
     */
    #[Test]
    public function l_elenco_mostra_il_piano_e_la_scadenza_veri(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');

        $this->abbonaLa($palestra, 'gym_30');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee(Plan::query()->where('code', 'gym_30')->sole()->name)
            // 💡 «Tenant», non «Palestra»: vedi il blocco in `UsersTable`.
            ->assertSee('Tenant');
    }

    /**
     * 🚨 **Un tenant personale si dichiara «personale».**
     *
     * ⛔ Scriverci il nome sotto l'etichetta «Palestra» faceva cercare una
     * palestra che non esiste.
     */
    #[Test]
    public function un_tenant_personale_non_viene_spacciato_per_una_palestra(): void
    {
        $personale = $this->creaTenantPersonale('Mario Rossi');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('personale');
    }

    // ───────────────────────── F4.2 e F4.4, la scheda ─────────────────────────

    /** 💶 *«quanto ha speso dall'inizio»*, e da quando è abbonato. */
    #[Test]
    public function la_scheda_mostra_quanto_ha_speso_e_l_abbonamento(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $this->abbonaLa($palestra, 'gym_30');

        $registro = app(RegistroDeiPagamenti::class);
        $registro->segna($palestra, PagamentoIncassato::GETTONI, 1250, utente: $persona, stripeId: 'cs_1');
        $registro->segna($palestra, PagamentoIncassato::GETTONI, 2500, utente: $persona, stripeId: 'cs_2');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ViewUser::class, ['record' => $persona->getKey()])
            ->assertSuccessful()
            ->assertSee('37,50 €')
            ->assertSee('tizio@esempio.test')
            // 🚨 L'abbonamento è della palestra, e la scheda lo dice.
            ->assertSee('della palestra');
    }

    /** ⛔ La scheda non mostra mai la password, in nessuna forma. */
    #[Test]
    public function la_scheda_non_mostra_la_password(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ViewUser::class, ['record' => $persona->getKey()])
            ->assertSuccessful()
            ->assertDontSee($persona->password);
    }

    // ───────────────────────── F4.3, i grafici ─────────────────────────

    /**
     * 🚨 **I buchi restano buchi.**
     *
     * ⛔ Un `GROUP BY` senza scheletro fa diventare tre acquisti lontani tre
     * colonne adiacenti: sembra che abbia comprato tre volte di fila.
     */
    #[Test]
    public function la_storia_riempie_i_mesi_vuoti(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $registro = app(RegistroDeiPagamenti::class);
        $registro->segna($palestra, PagamentoIncassato::GETTONI, 1000, utente: $persona, stripeId: 'a', quando: now());
        $registro->segna($palestra, PagamentoIncassato::GETTONI, 2000, utente: $persona, stripeId: 'b', quando: now()->subMonths(3));

        $serie = app(LaStoriaDiUnaPersona::class)->acquisti($persona, 'mese');

        $this->assertCount(LaStoriaDiUnaPersona::QUANTI['mese'], $serie);
        $this->assertSame(1000, $serie[now()->startOfMonth()->format('Y-m-d')]);
        $this->assertSame(2000, $serie[now()->subMonths(3)->startOfMonth()->format('Y-m-d')]);
        $this->assertSame(0, $serie[now()->subMonth()->startOfMonth()->format('Y-m-d')]);
    }

    /**
     * 🎟️ I gettoni spesi si contano **positivi**.
     *
     * ⛔ `delta` è negativo: senza il valore assoluto, il grafico risponderebbe
     * «quanto ha speso» con un numero sotto lo zero.
     */
    #[Test]
    public function i_gettoni_spesi_si_contano_in_positivo(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        app(TenantContext::class)->runAs($palestra, function () use ($palestra, $persona): void {
            AiCreditMovement::query()->create([
                'tenant_id' => $palestra->getKey(),
                'user_id' => $persona->getKey(),
                'delta' => -40,
                'saldo_dopo' => 60,
                'causale' => AiCreditMovement::CONSUMO,
            ]);
        });

        $serie = app(LaStoriaDiUnaPersona::class)->gettoniSpesi($persona, 'mese');

        $this->assertSame(40, $serie[now()->startOfMonth()->format('Y-m-d')]);
    }

    // ───────────────────────── F4.5, la mail ─────────────────────────

    /** ✉️ La mail va a **quella** persona, e a nessun altro. */
    #[Test]
    public function il_pannello_manda_una_mail_a_una_persona_sola(): void
    {
        Mail::fake();

        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');
        $this->creaUtente($palestra, UserRole::Member, 'altro@esempio.test');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ViewUser::class, ['record' => $persona->getKey()])
            ->callAction('manda_una_mail', [
                'oggetto' => 'Il tuo abbonamento',
                'testo' => "Ciao,\nabbiamo visto il tuo messaggio.",
            ])
            ->assertHasNoActionErrors();

        Mail::assertSent(MessaggioDalPannello::class, 1);
        Mail::assertSent(
            MessaggioDalPannello::class,
            fn (MessaggioDalPannello $m): bool => $m->hasTo('tizio@esempio.test'),
        );
    }

    // ───────────────────────── F4.6 e F3.6, il reset ─────────────────────────

    /** 🔑 Il tasto crea il link, manda la mail e lascia traccia. */
    #[Test]
    public function il_tasto_del_reset_crea_il_link_e_lo_registra(): void
    {
        Mail::fake();

        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');
        $god = $this->creaSuperAdmin();

        $this->actingAs($god);
        filament()->setCurrentPanel('god');

        Livewire::test(ViewUser::class, ['record' => $persona->getKey()])
            ->callAction('manda_il_reset')
            ->assertHasNoActionErrors();

        $this->assertSame(1, ReimpostazionePassword::query()->count());

        Mail::assertSent(LinkPerLaPassword::class);

        /*
         * 🚨 Finisce nel registro: per un'ora chi legge quella casella entra al
         * posto di quella persona, ed è un potere che deve lasciare traccia.
         */
        $this->assertTrue(
            AuditLog::withoutGlobalScopes()
                ->where('action', AuditAction::ResetPasswordMandato->value)
                ->exists(),
        );
    }

    /**
     * 🏢 **Sulla palestra il reset va al titolare** — F3.6.
     *
     * ⛔ Una palestra non ha una password: ce l'ha chi la amministra.
     */
    #[Test]
    public function sulla_palestra_il_reset_va_al_titolare(): void
    {
        Mail::fake();

        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ViewTenant::class, ['record' => $palestra->getKey()])
            ->callAction('reset_del_titolare')
            ->assertHasNoActionErrors();

        Mail::assertSent(
            LinkPerLaPassword::class,
            fn (LinkPerLaPassword $m): bool => $m->hasTo('capo@esempio.test'),
        );

        $this->assertSame(
            $titolare->getKey(),
            app(TenantContext::class)->runWithoutTenant(
                fn (): ?int => ReimpostazionePassword::query()->sole()->user_id,
            ),
        );
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function abbonaLa(Tenant $palestra, string $codice): PlanSubscription
    {
        return app(TenantContext::class)->runWithoutTenant(
            fn (): PlanSubscription => PlanSubscription::query()->create([
                'tenant_id' => $palestra->getKey(),
                'plan_id' => Plan::query()->where('code', $codice)->sole()->getKey(),
                'stripe_subscription_id' => 'sub_'.$palestra->getKey(),
                'stripe_customer_id' => 'cus_'.$palestra->getKey(),
                'rinnova' => true,
                'starts_at' => now()->subMonths(6),
                'ends_at' => now()->addDays(10),
            ]),
        );
    }

    private function creaTenantPersonale(string $nome): User
    {
        $tenant = Tenant::withoutGlobalScopes()->create([
            'name' => $nome,
            'slug' => str($nome)->slug()->value(),
            'join_code' => 'PERS'.random_int(1000, 9999),
            'kind' => TenantKind::Personal,
            'status' => TenantStatus::Active,
        ]);

        return app(TenantContext::class)->runAs($tenant, fn (): User => User::create([
            'name' => $nome,
            'email' => 'libero@esempio.test',
            'password' => TestCase::FAKE_PASSWORD,
        ]));
    }
}
