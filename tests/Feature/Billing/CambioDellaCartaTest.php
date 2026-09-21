<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Stripe\CambioDellaCarta;
use App\Services\Billing\Stripe\Cassa;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 💳 Cambiare il metodo di pagamento dal pannello — dopo F8, 23/09/2026.
 *
 * 📌 *«Ovviamente ci deve essere la possibilità di cambiare metodo di
 * pagamento»*. ✅ Il giro vero è stato provato nella sandbox di Stripe (carta
 * nuova sul cliente **e** sull'abbonamento, fattura rifiutata ripagata); qui si
 * fissa quello che mandiamo a Stripe, col finto.
 */
final class CambioDellaCartaTest extends TestCase
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

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_30')->value('id'),
            'stripe_subscription_id' => 'sub_olimpia',
            'stripe_customer_id' => 'cus_olimpia',
            'rinnova' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        filament()->setCurrentPanel('admin');
    }

    /** La pagina di Stripe si apre in modalità «salva la carta», per il cliente giusto. */
    #[Test]
    public function si_apre_la_pagina_di_stripe_per_la_carta(): void
    {
        $this->entra($this->titolare);

        Livewire::test(IlTuoPiano::class)
            ->assertSee('Cambia metodo di pagamento')
            ->call('cambiaCarta')
            ->assertRedirect();

        $sessione = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame('setup', $sessione['mode']);
        $this->assertSame('cus_olimpia', $sessione['customer']);
        $this->assertSame(['enabled' => false], $sessione['managed_payments']);
        $this->assertSame(CambioDellaCarta::TIPO, $sessione['metadata']['tipo']);
    }

    /**
     * 🚨 **Al ritorno la carta va sul cliente E sull'abbonamento.** Un
     * abbonamento aperto dal Checkout ha una carta sua, che vince: cambiare solo
     * quella del cliente lascerebbe il canone sulla carta vecchia.
     */
    #[Test]
    public function al_ritorno_la_carta_va_sul_cliente_e_sull_abbonamento(): void
    {
        $gestito = app(Cassa::class)->applica([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_carta',
                'mode' => 'setup',
                'customer' => 'cus_olimpia',
                'setup_intent' => 'seti_carta',
                'metadata' => [
                    'tipo' => CambioDellaCarta::TIPO,
                    'user_id' => (string) $this->titolare->getKey(),
                    'tenant_id' => (string) $this->palestra->getKey(),
                ],
            ]],
        ]);

        $this->assertTrue($gestito);

        $cliente = $this->stripe->ultima('customers.update');
        $this->assertSame('cus_olimpia', $cliente[0]);
        $this->assertSame('pm_finto', $cliente[1]['invoice_settings']['default_payment_method']);

        $abbonamento = $this->stripe->ultima('subscriptions.update');
        $this->assertSame('sub_olimpia', $abbonamento[0]);
        $this->assertSame('pm_finto', $abbonamento[1]['default_payment_method']);

        // 💡 E si guarda se c'è qualcosa rimasto da pagare.
        $this->assertNotSame([], $this->stripe->chiamateA('invoices.all'));
    }

    /** ⛔ Lo staff non cambia la carta della palestra. */
    #[Test]
    public function un_trainer_non_cambia_la_carta(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'anna@olimpia.test');

        $this->expectExceptionMessage('titolare');

        app(CambioDellaCarta::class)->apri($trainer, 'http://localhost/admin');
    }

    /** Senza un cliente Stripe non c'è niente da cambiare, e il tasto non c'è. */
    #[Test]
    public function senza_un_pagamento_il_tasto_non_c_e(): void
    {
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $this->palestra->getKey())->delete();
        $this->entra($this->titolare);

        Livewire::test(IlTuoPiano::class)->assertDontSee('Cambia metodo di pagamento');

        $this->assertSame([], $this->stripe->chiamateA('checkout.sessions.create'));
    }

    private function entra(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(TenantContext::class)->set($utente->tenant);
    }
}
