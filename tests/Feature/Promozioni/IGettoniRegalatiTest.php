<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\AiFeature;
use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\OrigineAssegnazione;
use App\Enums\StatoAssegnazione;
use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Filament\God\Resources\Promozioni\Pages\CreatePromozione;
use App\Jobs\ConsegnaLaPromozioneATutti;
use App\Models\AiCreditMovement;
use App\Models\LottoDiGettoni;
use App\Models\PacchettoGettoni;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Promozioni\ConsegnaDeiPremi;
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
 * 🪙 **I gettoni delle promozioni** — 24/09/2026, P2 di `plan_promozioni.md`.
 *
 * 📌 *«Voglio però sicuramente poter fare una cosa: una promozione per regalare
 * gettoni»*.
 *
 * Qui ci sono i soldi che si regalano: ogni errore è un gettone dato due
 * volte, o dato a chi non doveva, o tolto a chi l'aveva comprato. Un test per
 * regola.
 */
final class IGettoniRegalatiTest extends TestCase
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
     * @param  list<array<string, mixed>>  $premi
     * @param  array<string, mixed>  $extra
     */
    private function promo(array $premi, array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Regalo',
            'genere' => GenereDiPromozione::Gettoni,
            'attivazione' => ComeSiAttiva::Massa,
            'massimo_per_persona' => 1,
        ], $extra));

        foreach ($premi as $premio) {
            PromozionePremio::query()->create(['promozione_id' => $promozione->getKey(), ...$premio]);
        }

        PromozioneBersaglio::query()->create(['promozione_id' => $promozione->getKey(), 'tipo' => TipoBersaglio::Tutti]);

        return $promozione->fresh(['premi', 'bersagli']);
    }

    private function persona(string $email): User
    {
        $slug = substr(md5($email), 0, 8);

        return $this->creaUtente($this->creaPalestra('P '.$slug, $slug, strtoupper($slug)), UserRole::Member, $email);
    }

    private function consegna(): ConsegnaDeiPremi
    {
        return app(ConsegnaDeiPremi::class);
    }

    // ═════════════════════════ il regalo ═════════════════════════

    /** 🎁 **«Esegui» regala a tutti**, e il registro dice che è una promozione. */
    #[Test]
    public function esegui_regala_i_gettoni_a_tutti(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $caio = $this->persona('caio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]]);

        ConsegnaLaPromozioneATutti::dispatchSync($promozione->getKey());

        $this->assertSame(100, $tizio->refresh()->ai_credits);
        $this->assertSame(100, $caio->refresh()->ai_credits);
        $this->assertSame(2, $promozione->fresh()->usata_volte);

        $movimento = AiCreditMovement::withoutGlobalScopes()->where('user_id', $tizio->getKey())->sole();
        $this->assertSame(AiCreditMovement::PROMOZIONE, $movimento->causale);
        $this->assertStringContainsString('Regalo', (string) $movimento->nota);
    }

    /** 🚨 **Un secondo «Esegui» non ridà a chi ha già avuto** il suo massimo. */
    #[Test]
    public function un_secondo_giro_rispetta_il_massimo_per_persona(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]]);

        $this->consegna()->eseguiPerTutti($promozione);
        $this->travel(1)->minutes();
        $this->consegna()->eseguiPerTutti($promozione->fresh());

        $this->assertSame(100, $tizio->refresh()->ai_credits);
    }

    /** 💡 Senza massimo per persona, ogni giro è un regalo nuovo — è quello che si è chiesto. */
    #[Test]
    public function senza_massimo_ogni_giro_regala_di_nuovo(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]], ['massimo_per_persona' => null]);

        $this->consegna()->eseguiPerTutti($promozione);
        $this->travel(1)->minutes();
        $this->consegna()->eseguiPerTutti($promozione->fresh());

        $this->assertSame(200, $tizio->refresh()->ai_credits);
    }

    /**
     * ⚠️ **Il budget taglia, e poi ferma.** Opzione 35.
     *
     * Ne restano 50 e il regalo è 100: se ne danno 50. Poi più niente, e chi
     * resta fuori **non** ha un'assegnazione vuota che gli brucia il massimo.
     */
    #[Test]
    public function il_budget_in_gettoni_taglia_e_poi_ferma(): void
    {
        $uno = $this->persona('uno@esempio.test');
        $due = $this->persona('due@esempio.test');
        $tre = $this->persona('tre@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]], ['budget_gettoni' => 150]);

        $this->consegna()->consegna($promozione, $uno, OrigineAssegnazione::Massa, 'm:1');
        $this->consegna()->consegna($promozione->fresh(), $due, OrigineAssegnazione::Massa, 'm:2');
        $terza = $this->consegna()->consegna($promozione->fresh(), $tre, OrigineAssegnazione::Massa, 'm:3');

        $this->assertSame(100, $uno->refresh()->ai_credits);
        $this->assertSame(50, $due->refresh()->ai_credits);
        $this->assertNull($terza);
        $this->assertSame(0, (int) $tre->refresh()->ai_credits);
        $this->assertFalse(PromozioneAssegnazione::query()->where('user_id', $tre->getKey())->exists());
        $this->assertFalse($promozione->fresh()->eSpendibile());
    }

    /** 🎯 Chi è fuori dai destinatari non riceve niente, anche con «Esegui». */
    #[Test]
    public function chi_non_e_nei_destinatari_non_riceve(): void
    {
        $dentro = $this->persona('dentro@esempio.test');
        $fuori = $this->persona('fuori@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]]);
        $promozione->bersagli()->delete();
        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Utente,
            'user_id' => $dentro->getKey(),
        ]);

        $this->consegna()->eseguiPerTutti($promozione->fresh(['premi', 'bersagli']));

        $this->assertSame(100, $dentro->refresh()->ai_credits);
        $this->assertSame(0, (int) $fuori->refresh()->ai_credits);
    }

    /**
     * ⏱️ **Le automatiche arrivano una volta per persona**, anche se il
     * comando gira ogni quindici minuti e il massimo per persona è vuoto.
     */
    #[Test]
    public function le_automatiche_arrivano_una_volta_sola(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $this->promo(
            [['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 30]],
            ['attivazione' => ComeSiAttiva::Automatica, 'massimo_per_persona' => null],
        );

        $this->artisan('promozioni:giro', ['--solo' => 'automatiche'])->assertSuccessful();
        $this->artisan('promozioni:giro', ['--solo' => 'automatiche'])->assertSuccessful();

        $this->assertSame(30, $tizio->refresh()->ai_credits);
    }

    /** 🔗 **Il link si riscatta una volta**, e poi dice «l'hai già ricevuta». */
    #[Test]
    public function il_link_si_riscatta_una_volta(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 40]], ['attivazione' => ComeSiAttiva::Link]);

        $this->assertNotNull($this->consegna()->riscatta($promozione, $tizio));
        $this->assertNull($this->consegna()->riscatta($promozione->fresh(), $tizio));

        $this->assertSame(40, $tizio->refresh()->ai_credits);
        $this->assertSame('Hai già ricevuto questa offerta.', $this->consegna()->percheNo($promozione->fresh(), $tizio));
    }

    // ═════════════════════════ la scadenza ═════════════════════════

    /**
     * ⏳ **A scadenza si toglie solo il residuo del regalo.** Opzione 7.
     *
     * 🚨 Si spende prima dal regalo che scade: dei 100 regalati ne restano 70,
     * e i 50 comprati restano tutti.
     */
    #[Test]
    public function a_scadenza_si_toglie_solo_il_residuo_del_regalo(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        app(PortafoglioGettoni::class)->accredita($tizio, 50);

        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100, 'giorni' => 10]]);
        $this->consegna()->eseguiPerTutti($promozione);

        // 30 gettoni spesi: tre chiamate da 10.
        foreach (range(1, 3) as $i) {
            app(PortafoglioGettoni::class)->consuma($tizio->refresh(), AiFeature::FoodPhoto);
        }

        $this->assertSame(70, LottoDiGettoni::query()->sole()->gettoni_restanti);

        $this->travel(11)->days();
        $this->artisan('promozioni:giro', ['--solo' => 'scadenze'])->assertSuccessful();

        $this->assertSame(50, $tizio->refresh()->ai_credits);
        $this->assertSame(-70, AiCreditMovement::withoutGlobalScopes()->where('causale', AiCreditMovement::SCADENZA)->sole()->delta);
        $this->assertNotNull(LottoDiGettoni::query()->sole()->chiuso_il);
    }

    /** ⛔ E se il regalo è stato speso tutto, a scadenza non si toglie niente. */
    #[Test]
    public function un_regalo_speso_tutto_non_scade_niente(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 10, 'giorni' => 5]]);
        $this->consegna()->eseguiPerTutti($promozione);

        app(PortafoglioGettoni::class)->consuma($tizio->refresh(), AiFeature::FoodPhoto);
        app(PortafoglioGettoni::class)->accredita($tizio->refresh(), 100);

        $this->travel(6)->days();
        $this->artisan('promozioni:giro', ['--solo' => 'scadenze'])->assertSuccessful();

        $this->assertSame(100, $tizio->refresh()->ai_credits);
        $this->assertFalse(AiCreditMovement::withoutGlobalScopes()->where('causale', AiCreditMovement::SCADENZA)->exists());
    }

    // ═════════════════════════ i ricorrenti ═════════════════════════

    /** 📅 **N gettoni al mese per N mesi**, e poi basta. Opzione 5. */
    #[Test]
    public function i_gettoni_ricorrenti_arrivano_ogni_mese_e_poi_finiscono(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRicorrenti, 'gettoni' => 20, 'volte' => 3]]);

        $this->consegna()->eseguiPerTutti($promozione);
        $this->assertSame(20, $tizio->refresh()->ai_credits);

        foreach ([40, 60, 60] as $atteso) {
            $this->travel(1)->months();
            $this->travel(1)->hours();
            $this->artisan('promozioni:giro', ['--solo' => 'ricorrenti'])->assertSuccessful();
            $this->assertSame($atteso, $tizio->refresh()->ai_credits);
        }

        $assegnazione = PromozioneAssegnazione::query()->sole();
        $this->assertSame(StatoAssegnazione::Usata, $assegnazione->stato);
        $this->assertNull($assegnazione->prossimo_accredito_il);
        $this->assertSame(60, $assegnazione->gettoni);
    }

    // ═════════════════════════ il bonus ═════════════════════════

    /**
     * 🪙 **Il bonus arriva col pagamento**, lo dice la pagina di Stripe, e un
     * evento ripetuto non lo raddoppia. Opzione 2.
     */
    #[Test]
    public function il_bonus_arriva_col_pagamento_una_volta_sola(): void
    {
        $pacchetto = PacchettoGettoni::query()->inVendita()->orderByDesc('gettoni')->first();
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo(
            [['tipo' => TipoDiPremio::GettoniBonus, 'valore' => 20]],
            ['attivazione' => ComeSiAttiva::Automatica],
        );
        $atteso = intdiv($pacchetto->gettoni * 20, 100);

        app(Cassa::class)->apri($tizio, Cassa::GETTONI, $pacchetto->gettoni);
        $sessione = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertStringContainsString("(+{$atteso} in regalo)", $sessione['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame($promozione->codice, $sessione['metadata']['bonus']);
        $this->assertArrayNotHasKey('discounts', $sessione);

        $evento = [
            'id' => 'evt_bonus',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_bonus',
                'mode' => 'payment',
                'amount_total' => $pacchetto->prezzo_cent,
                'metadata' => $sessione['metadata'],
            ]],
        ];

        app(Cassa::class)->applica($evento);

        /*
         * ⚠️ Il doppio evento intero lo scarta `StripeWebhookController` per
         * `event_id` (e con lui l'acquisto). Qui si prova che il **bonus**, da
         * solo, non si ridà: stessa sessione → stessa assegnazione → niente.
         */
        $segnate = app(LePromozioni::class)->segnaUsate($promozione->codice, $tizio, 'cs_bonus', 0);
        $this->consegna()->bonusDellAcquisto($segnate, [$promozione->codice], $tizio, $pacchetto->gettoni, $pacchetto->getKey());

        $this->assertSame($pacchetto->gettoni + $atteso, $tizio->refresh()->ai_credits);
        $this->assertSame($atteso, PromozioneAssegnazione::query()->sole()->gettoni);
    }

    /** 💶 **Il prezzo speciale** arriva a Stripe come sconto della differenza. Opzione 4. */
    #[Test]
    public function il_prezzo_speciale_arriva_a_stripe(): void
    {
        $pacchetto = PacchettoGettoni::query()->inVendita()->orderByDesc('gettoni')->first();
        $tizio = $this->persona('tizio@esempio.test');
        $this->promo(
            [['tipo' => TipoDiPremio::PrezzoPacchetto, 'pacchetto_id' => $pacchetto->getKey(), 'prezzo_cent' => 100]],
            ['attivazione' => ComeSiAttiva::Automatica],
        );

        app(Cassa::class)->apri($tizio, Cassa::GETTONI, $pacchetto->gettoni);

        $this->assertSame($pacchetto->prezzo_cent - 100, $this->stripe->ultima('coupons.create')[0]['amount_off']);
    }

    /**
     * 🎁💶 **Regalo subito e sconto dopo, in una promozione sola.**
     *
     * Col massimo per persona a 1 il regalo non deve chiudere la porta allo
     * sconto: lo sconto resta «in sospeso» sulla stessa assegnazione, e il
     * pagamento lo consuma.
     */
    #[Test]
    public function regalo_e_sconto_insieme_non_si_escludono(): void
    {
        $tizio = $this->persona('tizio@esempio.test');
        $promozione = $this->promo([
            ['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 10],
            ['tipo' => TipoDiPremio::ScontoGettoni, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 50],
        ], ['attivazione' => ComeSiAttiva::Link]);

        $this->consegna()->riscatta($promozione, $tizio);
        $this->assertSame(10, $tizio->refresh()->ai_credits);

        $servizio = app(LePromozioni::class);
        $this->assertNotNull($servizio->conIlCodice($promozione->codice, $tizio, SuCosaVale::Gettoni));

        $servizio->segnaUsate($promozione->codice, $tizio, 'cs_misto', 300);

        $assegnazione = PromozioneAssegnazione::query()->sole();
        $this->assertFalse($assegnazione->acquisto_in_sospeso);
        $this->assertSame(300, $assegnazione->scontato_cent);
        $this->assertSame(1, $promozione->fresh()->usata_volte);
        $this->assertNull($servizio->conIlCodice($promozione->codice, $tizio, SuCosaVale::Gettoni));
    }

    // ═════════════════════════ il modulo ═════════════════════════

    /** ⛔ **«Esegui» senza regali non si salva**: sarebbe un tasto che non fa niente. */
    #[Test]
    public function l_accredito_di_massa_pretende_un_regalo(): void
    {
        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(CreatePromozione::class)
            ->fillForm([
                'genere' => GenereDiPromozione::Gettoni->value,
                'premi' => [['tipo' => TipoDiPremio::ScontoGettoni->value, 'tipo_sconto' => 'percentuale', 'valore' => 10]],
                'bersagli' => [['tipo' => TipoBersaglio::Tutti->value]],
                'attivazione' => ComeSiAttiva::Massa->value,
                'nome' => 'Sbagliata',
                'codice' => 'SBAGLIATA1',
                'stato' => 'attiva',
            ])
            ->call('create')
            ->assertHasFormErrors(['attivazione']);
    }
}
