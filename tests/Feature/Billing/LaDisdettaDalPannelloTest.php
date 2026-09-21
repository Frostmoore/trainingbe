<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 🛑 **Disdire dal pannello, da soli** — 25/09/2026.
 *
 * 📌 Il committente, alla proposta di un tasto «Disdici» a fine periodo con la
 * possibilità di ripensarci: *«ma certo facciamo così mi pare ovvio»*. ⛔ Prima
 * lo faceva il portale di Stripe, tolto con F8: per disdire bisognava scriverci.
 *
 * 🚨 Quello che questi test fissano: **si disdice su Stripe** (non solo da noi,
 * o la carta continuerebbe a pagare), **a fine periodo** (quello che è pagato si
 * usa), e la data che la palestra legge è **quella vera** subito.
 */
final class LaDisdettaDalPannelloTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private Tenant $palestra;

    private User $titolare;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->stripe = $this->stripeFinto();

        $this->palestra = $this->creaPalestra('Olimpia', 'olimpia');
        $this->titolare = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpia.test');
        $this->palestra->forceFill(['proprietario_id' => $this->titolare->getKey()])->save();

        filament()->setCurrentPanel('admin');
    }

    /**
     * 🚨 Il canone: `cancel_at_period_end` su Stripe, e la data **senza** i sette
     * giorni di grazia (valgono solo a rinnovo acceso) — subito, non al webhook.
     */
    #[Test]
    public function il_canone_si_disdice_su_stripe_a_fine_periodo(): void
    {
        $fine = now()->addDays(20)->startOfSecond();
        $riga = $this->canone($fine->copy()->addDays(7));

        $this->entra();

        Livewire::test(IlTuoPiano::class)
            ->assertActionVisible('disdici')
            ->assertActionHidden('ripensaci')
            ->callAction('disdici');

        $chiamata = $this->stripe->ultima('subscriptions.update');
        $this->assertSame('sub_olimpia', $chiamata[0]);
        $this->assertTrue($chiamata[1]['cancel_at_period_end']);

        $riga->refresh();
        $this->assertFalse((bool) $riga->rinnova);
        $this->assertTrue($riga->ends_at->equalTo($fine), 'la data letta dalla palestra ha ancora la grazia');
        $this->assertTrue($riga->ends_at->isFuture(), 'la disdetta ha tolto il periodo gia pagato');

        $this->assertSame(1, $this->registro(AuditAction::PianoDisdetto));
    }

    /** E ci si ripensa: il rinnovo torna acceso, su Stripe e da noi, con la grazia. */
    #[Test]
    public function ci_si_ripensa_prima_della_fine(): void
    {
        $fine = now()->addDays(20)->startOfSecond();
        $riga = $this->canone($fine, rinnova: false);

        $this->entra();

        Livewire::test(IlTuoPiano::class)
            ->assertActionHidden('disdici')
            ->assertActionVisible('ripensaci')
            ->callAction('ripensaci');

        $this->assertFalse($this->stripe->ultima('subscriptions.update')[1]['cancel_at_period_end']);

        $riga->refresh();
        $this->assertTrue((bool) $riga->rinnova);
        $this->assertTrue($riga->ends_at->equalTo($fine->copy()->addDays(7)));

        $this->assertSame(1, $this->registro(AuditAction::DisdettaAnnullata));
    }

    /**
     * 📊 Il consumo non ha un abbonamento su Stripe: si paga a mese chiuso per
     * ogni mese in cui è vivo. Disdetto, finisce **l'ultimo giorno di questo
     * mese** — non «un mese dall'inizio», che era il ramo del regalo.
     */
    #[Test]
    public function il_consumo_finisce_a_fine_mese(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');

        $riga = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_consumo')->value('id'),
            'stripe_customer_id' => 'cus_olimpia',
            'rinnova' => true,
            'starts_at' => Carbon::parse('2026-03-01'),
            'ends_at' => null,
        ]);

        $this->entra();

        Livewire::test(IlTuoPiano::class)->callAction('disdici');

        $riga->refresh();
        $this->assertFalse((bool) $riga->rinnova);
        $this->assertSame('2026-09-30', $riga->ends_at->toDateString());
        $this->assertSame([], $this->stripe->chiamateA('subscriptions.update'), 'il consumo non ha niente su Stripe');

        Livewire::test(IlTuoPiano::class)->callAction('ripensaci');

        $riga->refresh();
        $this->assertTrue((bool) $riga->rinnova);
        $this->assertNull($riga->ends_at, 'ripensandoci il consumo torna senza scadenza');
    }

    /** ⚠️ Un piano che non costa (gratuito, regalo) non ha niente da disdire. */
    #[Test]
    public function senza_un_piano_che_costa_il_tasto_non_c_e(): void
    {
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_30')->value('id'),
            'rinnova' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->entra();

        Livewire::test(IlTuoPiano::class)
            ->assertActionHidden('disdici')
            ->assertActionHidden('ripensaci');
    }

    private function canone(Carbon $fine, bool $rinnova = true): PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_30')->value('id'),
            'stripe_subscription_id' => 'sub_olimpia',
            'stripe_customer_id' => 'cus_olimpia',
            'rinnova' => $rinnova,
            'starts_at' => now()->subDays(10),
            'ends_at' => $fine,
        ]);
    }

    private function registro(AuditAction $azione): int
    {
        return AuditLog::withoutGlobalScopes()->ofAction($azione)->count();
    }

    private function entra(): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($this->titolare);

        app(TenantContext::class)->set($this->titolare->tenant);
    }
}
