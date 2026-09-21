<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\Pages\ViewTenant;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Services\Billing\Stripe\Cassa;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 💰 **Il registro degli incassi** — 22/09/2026, fase F3.
 *
 * ══ ⛔ IL BUCO CHE CHIUDE ════════════════════════════════════════════════
 *
 * 📌 Il committente: *«Quanti soldi mi ha dato dall'inizio»*.
 *
 * 🚨 **Non lo sapevamo.** `invoice.paid` allungava l'abbonamento senza scrivere
 * da nessuna parte **quanto** era stato pagato: un cliente a canone da un anno
 * risultava non aver mai dato niente, perché l'unico posto in cui quel
 * pagamento esisteva era Stripe.
 */
final class IlRegistroDeiPagamentiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PacchettiGettoniSeeder::class);
        $this->stripe = $this->stripeFinto();
    }

    /**
     * 🚨 **Il rinnovo di un canone finisce nel registro.**
     *
     * Era il buco più grosso: i mesi successivi al primo non li scriveva
     * nessuno.
     */
    #[Test]
    public function un_rinnovo_pagato_finisce_nel_registro(): void
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');

        $abbonamento = app(TenantContext::class)->runWithoutTenant(
            fn (): PlanSubscription => PlanSubscription::query()->create([
                'tenant_id' => $palestra->getKey(),
                'plan_id' => Plan::query()->where('code', 'gym_30')->sole()->getKey(),
                'stripe_subscription_id' => 'sub_palestra',
                'stripe_customer_id' => 'cus_palestra',
                'rinnova' => true,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addDays(2),
            ]),
        );

        app(Cassa::class)->applica([
            'id' => 'evt_rinnovo',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_rinnovo_1',
                'subscription' => 'sub_palestra',
                'amount_paid' => 13000,
            ]],
        ]);

        $pagamento = PagamentoIncassato::withoutGlobalScopes()->sole();

        $this->assertSame($palestra->getKey(), $pagamento->tenant_id);
        $this->assertSame(PagamentoIncassato::CANONE, $pagamento->tipo);
        $this->assertSame(13000, $pagamento->importo_cent);

        // ⚠️ E l'abbonamento è stato comunque allungato: il registro non
        // sostituisce niente, si aggiunge.
        $this->assertTrue($abbonamento->refresh()->ends_at->isAfter(now()->addDays(20)));
    }

    /**
     * 🚨 **Due webhook uguali non raddoppiano l'incasso.**
     *
     * ⛔ I webhook arrivano due volte: un registro che raddoppia è peggio di
     * uno che manca, perché sembra giusto.
     */
    #[Test]
    public function lo_stesso_pagamento_non_si_conta_due_volte(): void
    {
        $palestra = $this->creaPalestra();

        $registro = app(RegistroDeiPagamenti::class);

        $registro->segna($palestra, PagamentoIncassato::CANONE, 6000, stripeId: 'in_doppio');
        $registro->segna($palestra, PagamentoIncassato::CANONE, 6000, stripeId: 'in_doppio');

        $this->assertSame(1, PagamentoIncassato::withoutGlobalScopes()->count());
        $this->assertSame(6000, $registro->totaleDi($palestra));
    }

    /** 🎟️ E anche i gettoni sono soldi: ci vanno pure loro. */
    #[Test]
    public function anche_i_gettoni_comprati_sono_un_incasso(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        app(Cassa::class)->applica([
            'id' => 'evt_gettoni',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_gettoni',
                'mode' => 'payment',
                'amount_total' => 1250,
                'metadata' => [
                    'tipo' => Cassa::GETTONI,
                    'user_id' => (string) $persona->getKey(),
                    'tenant_id' => (string) $palestra->getKey(),
                    'gettoni' => '500',
                ],
            ]],
        ]);

        $pagamento = PagamentoIncassato::withoutGlobalScopes()
            ->delTipo(PagamentoIncassato::GETTONI)
            ->sole();

        $this->assertSame(1250, $pagamento->importo_cent);
        $this->assertSame($persona->getKey(), $pagamento->user_id);

        // 💡 E i gettoni sono arrivati a lui, come sempre.
        $this->assertSame(500, $persona->refresh()->ai_credits);
    }

    /**
     * ⚠️ **Il registro non deve far fallire un pagamento.**
     *
     * 🚨 Serve a noi: se scrivere la riga non riesce, il servizio va acceso
     * comunque. ⛔ Lanciare da qui farebbe rispondere 500 a Stripe, che
     * ritenterebbe l'evento.
     */
    #[Test]
    public function se_il_registro_non_scrive_il_pagamento_vale_lo_stesso(): void
    {
        $palestra = $this->creaPalestra();

        // 🔬 Un tenant che non esiste: la scrittura sbatte sulla chiave esterna.
        $esito = app(RegistroDeiPagamenti::class)->segna(999_999, PagamentoIncassato::CANONE, 100);

        $this->assertNull($esito);
        $this->assertSame(0, PagamentoIncassato::withoutGlobalScopes()->count());
    }

    /** 🏢 E la scheda della palestra mostra quello che ha dato. */
    #[Test]
    public function la_scheda_della_palestra_mostra_l_incassato(): void
    {
        $palestra = $this->creaPalestra();

        app(RegistroDeiPagamenti::class)->segna($palestra, PagamentoIncassato::CANONE, 13000, stripeId: 'in_1');
        app(RegistroDeiPagamenti::class)->segna($palestra, PagamentoIncassato::GETTONI, 1250, stripeId: 'cs_1');

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ViewTenant::class, ['record' => $palestra->getKey()])
            ->assertSuccessful()
            ->assertSee('142,50 €')
            ->assertSee('12,50 €');
    }

    /** 💡 I totali per mese, che servono al grafico. */
    #[Test]
    public function i_totali_per_mese_si_contano(): void
    {
        $palestra = $this->creaPalestra();
        $registro = app(RegistroDeiPagamenti::class);

        $registro->segna($palestra, PagamentoIncassato::CANONE, 6000, stripeId: 'a', quando: now());
        $registro->segna($palestra, PagamentoIncassato::CANONE, 6000, stripeId: 'b', quando: now());
        $registro->segna($palestra, PagamentoIncassato::CANONE, 6000, stripeId: 'c', quando: now()->subMonth());

        $perMese = $registro->perMese($palestra);

        $this->assertSame(12000, $perMese[now()->format('Y-m')]);
        $this->assertSame(6000, $perMese[now()->subMonth()->format('Y-m')]);
    }
}
