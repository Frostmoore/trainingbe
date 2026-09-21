<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AuditAction;
use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Enums\UserRole;
use App\Filament\God\Resources\Plans\PlanResource;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PianoCucitoAMano;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ✂️ **I piani cuciti a mano** — 21/09/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * > *«Palestra su misura: 20 trainers (tanto c'è scrivici … e **creami la
 * > possibilità di creare nuovi abbonamenti per palestre con il numero di
 * > trainers che voglio io**)»*.
 *
 * ══ 🚨 PERCHÉ SERVIVA ═════════════════════════════════════════════════════
 *
 * Perché lo stesso giorno il su misura ha smesso di avere trainer illimitati:
 * erano il buco da cui una palestra poteva registrare i propri iscritti come
 * istruttori e pagare il minimo. ⛔ Chiuso il buco, serviva la valvola: chi ha
 * bisogno di trenta istruttori scrive, e gli si cuce un piano addosso.
 */
class PianoCucitoAManoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Un piano da palestra con i posti che decidiamo noi. */
    private function pianoSuMisura(int $trainer = 30, int $iscritti = 400): Plan
    {
        return Plan::create([
            'code' => 'su_misura_prova',
            'name' => 'Su misura — prova',
            'kind' => PlanKind::Gym,
            'ai_enabled' => true,
            'max_members' => $iscritti,
            'max_trainers' => $trainer,
            'price_cents' => 89000,
            'is_public' => false,
            'cucito_a_mano' => true,
        ]);
    }

    /**
     * 🚨 **Il piano assegnato è quello che l'app legge.**
     *
     * ⚠️ Non basta che nasca la riga: `PianoAttivo::per()` è ciò che decide
     * l'AI, i posti e il resto, e guarda l'abbonamento **attivo più recente**.
     * Se l'assegnazione non arrivasse fin lì sarebbe una riga in una tabella.
     */
    #[Test]
    public function the_hand_stitched_plan_is_the_one_the_app_reads(): void
    {
        $palestra = $this->creaPalestra();
        $piano = $this->pianoSuMisura(trainer: 30);

        app(PianoCucitoAMano::class)->assegna($palestra, $piano);

        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@demo.test');

        $letto = app(PianoAttivo::class)->per($titolare);

        $this->assertSame($piano->code, $letto->code);
        $this->assertSame(30, $letto->max_trainers, 'i posti trainer concordati non arrivano all\'app');
    }

    /**
     * 💡 **Quello che c'era prima si chiude, non si affianca.**
     *
     * ⛔ Due abbonamenti attivi sullo stesso cliente vorrebbero dire che
     * `orderByDesc('starts_at')` decide quale vale — cioè che il piano di un
     * cliente dipende da un ordinamento, e non da una scelta.
     */
    #[Test]
    public function the_previous_subscription_is_closed(): void
    {
        $palestra = $this->creaPalestra();

        $vecchio = $this->abbonaA($palestra, Plan::query()->where('code', 'gym_10')->sole());

        app(PianoCucitoAMano::class)->assegna($palestra, $this->pianoSuMisura());

        $this->assertNotNull($vecchio->refresh()->ends_at, 'il piano di prima è rimasto aperto');
        $this->assertFalse((bool) $vecchio->rinnova, 'il piano chiuso si rinnoverà stanotte');

        $attivi = app(TenantContext::class)->runWithoutTenant(
            fn (): int => PlanSubscription::query()->where('tenant_id', $palestra->getKey())->attivi()->count(),
        );

        $this->assertSame(1, $attivi, 'il cliente ha due abbonamenti attivi insieme');
    }

    /**
     * 🚨 **Su un abbonamento pagato con Stripe non si assegna niente.**
     *
     * ⛔ Chiuderlo solo da noi lo lascerebbe **vivo su Stripe**: il cliente
     * pagherebbe il piano vecchio mentre usa quello nuovo, e non se ne
     * accorgerebbe nessuno fino all'estratto conto. 💡 Il rifiuto è la cosa
     * giusta da fare: prima si disdice di là.
     */
    #[Test]
    public function it_refuses_to_overwrite_a_stripe_subscription(): void
    {
        $palestra = $this->creaPalestra();

        $this->abbonaA(
            $palestra,
            Plan::query()->where('code', 'gym_10')->sole(),
            ['stripe_subscription_id' => 'sub_finta123'],
        );

        $this->expectException(InvalidArgumentException::class);

        app(PianoCucitoAMano::class)->assegna($palestra, $this->pianoSuMisura());
    }

    /**
     * ⛔ **Un piano da palestra non va su uno spazio personale**, e viceversa:
     * darebbe posti trainer a chi non ha nessuno da invitare.
     */
    #[Test]
    public function the_plan_and_the_client_must_be_the_same_species(): void
    {
        $personale = Tenant::create([
            'name' => 'Spazio di Luca',
            'slug' => 'luca-personale',
            'join_code' => 'LUCA2345',
            'contact_email' => 'luca@esempio.test',
            'kind' => TenantKind::Personal,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PianoCucitoAMano::class)->assegna($personale, $this->pianoSuMisura());
    }

    /**
     * 🚨 **Resta scritto chi l'ha fatto.**
     *
     * ⚠️ È l'azione con più soldi attaccati del pannello — decide quanto paga
     * un cliente — e non passa da Stripe: senza la riga di registro, l'unica
     * traccia sarebbe `updated_at`.
     */
    #[Test]
    public function the_assignment_leaves_a_trace(): void
    {
        $palestra = $this->creaPalestra();

        app(PianoCucitoAMano::class)->assegna($palestra, $this->pianoSuMisura(trainer: 30));

        $riga = AuditLog::query()
            ->where('action', AuditAction::PianoSuMisuraAssegnato->value)
            ->sole();

        $this->assertSame('su_misura_prova', $riga->payload['piano']);
        $this->assertSame(30, $riga->payload['posti_trainer']);
    }

    /**
     * 🆕 F9.1 — **Tutti i piani si modificano, e il seeder non riscrive quelli
     * toccati a mano.**
     *
     * 📌 *«Pagina del piano specifico (Modifica): non esiste ma deve esistere ·
     * deve contenere il prezzo…»*. ⛔ Prima i piani di listino non si potevano
     * modificare perché `PlanSeeder` li riscriveva a ogni deploy; adesso chi li
     * modifica dal pannello li segna `modificato_a_mano`, e il seeder li salta.
     */
    #[Test]
    public function i_piani_si_modificano_e_il_seeder_non_li_riscrive(): void
    {
        $dalListino = Plan::query()->where('code', 'gym_10')->sole();

        $this->assertFalse($dalListino->cucito_a_mano, 'un piano del seeder risulta cucito a mano');
        $this->assertTrue(PlanResource::canEdit($dalListino));
        $this->assertTrue(PlanResource::canEdit($this->pianoSuMisura()));

        // ⛔ E nessun piano si cancella: `plan_subscriptions` punta a `plans.id`.
        $this->assertFalse(PlanResource::canDelete($dalListino));

        // Toccato a mano: il deploy dopo non lo rimette com'era.
        $dalListino->forceFill(['price_cents' => 7777, 'modificato_a_mano' => true])->save();
        $this->seed(PlanSeeder::class);
        $this->assertSame(7777, $dalListino->refresh()->price_cents);

        // Non toccato: il seeder resta la verità.
        $altro = Plan::query()->where('code', 'gym_30')->sole();
        $altro->forceFill(['price_cents' => 1])->save();
        $this->seed(PlanSeeder::class);
        $this->assertNotSame(1, $altro->refresh()->price_cents);
    }

    /**
     * Un abbonamento come quelli veri, per avere qualcosa da chiudere.
     *
     * @param  array<string, mixed>  $extra
     */
    private function abbonaA(Tenant $tenant, Plan $piano, array $extra = []): PlanSubscription
    {
        return app(TenantContext::class)->runWithoutTenant(
            fn (): PlanSubscription => PlanSubscription::query()->create(array_merge([
                'tenant_id' => $tenant->getKey(),
                'plan_id' => $piano->getKey(),
                'starts_at' => now()->subMonth(),
                'ends_at' => null,
                'rinnova' => true,
            ], $extra)),
        );
    }
}
