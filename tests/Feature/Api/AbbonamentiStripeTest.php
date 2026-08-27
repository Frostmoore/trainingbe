<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 3b-H.9 — il ciclo di vita dell'abbonamento.
 *
 * ── 🚨 IL DIFETTO CHE QUESTI TEST CHIUDONO ────────────────────────────────
 *
 * Fino al 27/08 si ascoltava **solo il primo pagamento**: chi si abbonava aveva
 * un mese e poi si spegneva, **continuando a pagare**. ⛔ E' il difetto peggiore
 * possibile qui, perche' si manifesta trenta giorni dopo, su un cliente che ha
 * l'addebito in regola, e non produce nessun errore da nessuna parte.
 *
 * ⚠️ `Cassa::applica()` legge soltanto array, quindi si prova per intero senza
 * parlare con Stripe: e' anche il motivo per cui e' scritta cosi'.
 */
class AbbonamentiStripeTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    private Cassa $cassa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'io@alfa.test');
        $this->cassa = app(Cassa::class);
    }

    /** L'abbonamento personale come lo scrive il primo pagamento. */
    private function abbonamento(array $extra = []): PlanSubscription
    {
        return PlanSubscription::create([
            'tenant_id' => $this->iscritto->tenant->getKey(),
            'plan_id' => Plan::where('code', Plan::PLUS)->value('id'),
            'stripe_subscription_id' => 'sub_test123',
            'stripe_customer_id' => 'cus_test123',
            'starts_at' => now()->subDays(29),
            'ends_at' => now()->addDay(),
            'rinnova' => true,
        ] + $extra);
    }

    private function fattura(int $fineDelPeriodo, string $sub = 'sub_test123'): array
    {
        return [
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_test',
                'subscription' => $sub,
                'lines' => ['data' => [['period' => ['end' => $fineDelPeriodo]]]],
            ]],
        ];
    }

    // ───────────────────────── il rinnovo ─────────────────────────

    #[Test]
    public function il_rinnovo_sposta_la_scadenza(): void
    {
        $riga = $this->abbonamento();
        $fine = now()->addDays(31);

        $this->cassa->applica($this->fattura($fine->timestamp));

        $riga->refresh();

        // 💡 Un giorno di grazia: il rinnovo puo' arrivare qualche ora dopo.
        $this->assertTrue(
            $riga->ends_at->greaterThan($fine),
            'la scadenza deve andare oltre la fine del periodo pagato',
        );
    }

    /**
     * 🚨 **La prima fattura arriva SUBITO**, insieme al primo pagamento.
     *
     * ⛔ Con un `addMonth()` il primo mese sarebbe diventato due: qui la
     * scadenza si **calcola** dal periodo della fattura, quindi applicarla due
     * volte non cambia niente.
     */
    #[Test]
    public function applicare_due_volte_la_stessa_fattura_non_regala_un_mese(): void
    {
        $riga = $this->abbonamento();
        $fine = now()->addDays(31);

        $this->cassa->applica($this->fattura($fine->timestamp));
        $primo = $riga->refresh()->ends_at;

        $this->cassa->applica($this->fattura($fine->timestamp));
        $secondo = $riga->refresh()->ends_at;

        $this->assertEquals($primo, $secondo);
    }

    /** ⚠️ Il campo ha cambiato posto fra le versioni dell'API: si guardano tutte e due. */
    #[Test]
    public function il_rinnovo_si_trova_anche_col_nome_nuovo(): void
    {
        $riga = $this->abbonamento();
        $fine = now()->addDays(31);

        $this->cassa->applica([
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'parent' => ['subscription_details' => ['subscription' => 'sub_test123']],
                'lines' => ['data' => [['period' => ['end' => $fine->timestamp]]]],
            ]],
        ]);

        $this->assertTrue($riga->refresh()->ends_at->greaterThan(now()->addDays(30)));
    }

    /** 💡 Le fatture dei pacchetti di gettoni passano di qui e non devono fare niente. */
    #[Test]
    public function una_fattura_senza_abbonamento_non_fa_niente(): void
    {
        $riga = $this->abbonamento();
        $prima = $riga->ends_at;

        $this->cassa->applica([
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_x', 'lines' => ['data' => []]]],
        ]);

        $this->assertEquals($prima, $riga->refresh()->ends_at);
    }

    /** ⛔ Un abbonamento che non conosciamo non deve toccare quelli che conosciamo. */
    #[Test]
    public function il_rinnovo_di_uno_sconosciuto_non_tocca_gli_altri(): void
    {
        $riga = $this->abbonamento();
        $prima = $riga->ends_at;

        $this->cassa->applica($this->fattura(now()->addDays(31)->timestamp, 'sub_altrui'));

        $this->assertEquals($prima, $riga->refresh()->ends_at);
    }

    // ───────────────────────── la disdetta ─────────────────────────

    /**
     * 🚨 **Disdire non spegne niente subito**: chi ha pagato il mese lo usa
     * fino in fondo. Cambia solo la frase che l'app scrive.
     */
    #[Test]
    public function la_disdetta_non_spegne_labbonamento_subito(): void
    {
        $riga = $this->abbonamento();

        $this->cassa->applica([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_test123',
                'cancel_at_period_end' => true,
                'current_period_end' => now()->addDays(10)->timestamp,
            ]],
        ]);

        $riga->refresh();

        $this->assertFalse((bool) $riga->rinnova);
        $this->assertTrue($riga->ends_at->isFuture(), 'resta attivo fino alla scadenza');
    }

    #[Test]
    public function e_ci_si_puo_ripensare(): void
    {
        $riga = $this->abbonamento(['rinnova' => false]);

        $this->cassa->applica([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_test123',
                'cancel_at_period_end' => false,
                'current_period_end' => now()->addDays(10)->timestamp,
            ]],
        ]);

        $this->assertTrue((bool) $riga->refresh()->rinnova);
    }

    /** ⚠️ Stripe manda questo alla FINE del periodo pagato, non alla disdetta. */
    #[Test]
    public function quando_finisce_davvero_si_chiude(): void
    {
        $riga = $this->abbonamento();

        $this->cassa->applica([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_test123']],
        ]);

        $riga->refresh();

        $this->assertFalse($riga->ends_at->isFuture());
        $this->assertFalse((bool) $riga->rinnova);
    }

    // ───────────────────────── quello che l'app vede ─────────────────────────

    #[Test]
    public function il_listino_dice_quando_scade_e_se_si_rinnova(): void
    {
        $this->abbonamento();

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonamento_attivo.rinnova', true)
            ->assertJsonPath('data.abbonamento_attivo.gestibile', true)
            ->assertJsonStructure(['data' => ['abbonamento_attivo' => ['fino_al']]]);
    }

    /**
     * ⛔ Alle palestre il pulsante «gestisci» non deve comparire: il loro
     * abbonamento non viene da Stripe, e la pagina sarebbe vuota.
     */
    #[Test]
    public function un_abbonamento_che_non_viene_da_stripe_non_e_gestibile(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertJsonPath('data.abbonamento_attivo.gestibile', false);
    }

    #[Test]
    public function e_senza_cliente_stripe_il_portale_si_rifiuta(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/billing/portale')
            ->assertStatus(422);
    }

    // ───────────────────────── la robustezza ─────────────────────────

    /**
     * 🚨 `applica()` non deve **mai** lanciare: un `500` farebbe ritentare
     * Stripe per ore su un evento gia' registrato, e ogni ritentativo
     * uscirebbe sul doppione **prima** di riprovare l'accredito.
     */
    #[Test]
    public function un_evento_malformato_non_esplode(): void
    {
        foreach ([[], ['type' => 'invoice.paid'], ['type' => 'boh', 'data' => 'x']] as $rotto) {
            $this->cassa->applica($rotto);
        }

        $this->assertTrue(true, 'nessuna eccezione: e la garanzia che serve al webhook');
    }
}
