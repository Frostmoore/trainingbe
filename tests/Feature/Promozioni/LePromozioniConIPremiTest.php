<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\DurataDelloSconto;
use App\Enums\GenereDiPromozione;
use App\Enums\StatoAssegnazione;
use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Filament\God\Resources\Promozioni\Pages\CreatePromozione;
use App\Filament\God\Resources\Promozioni\Schemas\ModuloDellaPromozione;
use App\Models\PacchettoGettoni;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\Stripe\Cassa;
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
 * 🎁 **Le promozioni coi premi** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * Le regole di F2 (incolonnare, la migliore fra le esclusive, mai sotto zero)
 * restano provate in `Tests\Feature\Billing\LePromozioniTest`. Qui ci sono
 * quelle nuove, una per test: il pacchetto giusto, la priorità, il massimo
 * per persona, il budget, il webhook che segna l'uso una volta sola, e il
 * modulo che mostra solo i premi del genere scelto.
 */
final class LePromozioniConIPremiTest extends TestCase
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

    /** @param  array<string, mixed>  $premio */
    private function promo(array $premio = [], array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Offerta',
            'genere' => GenereDiPromozione::Gettoni,
        ], $extra));

        PromozionePremio::query()->create(array_merge([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoDiPremio::ScontoGettoni,
            'tipo_sconto' => TipoDiSconto::Percentuale,
            'valore' => 20,
        ], $premio));

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Tutti,
        ]);

        return $promozione->fresh(['bersagli', 'premi']);
    }

    private function persona(string $email = 'tizio@esempio.test'): User
    {
        // Una palestra per persona: lo slug viene dall'email, così due persone non si pestano.
        $slug = substr(md5($email), 0, 8);

        return $this->creaUtente($this->creaPalestra('P '.$slug, $slug, strtoupper($slug)), UserRole::Member, $email);
    }

    /** @return array{0: PacchettoGettoni, 1: PacchettoGettoni} */
    private function duePacchetti(): array
    {
        $tutti = PacchettoGettoni::query()->inVendita()->orderBy('gettoni')->get();

        return [$tutti[0], $tutti[1]];
    }

    // ═════════════════════════ il premio giusto ═════════════════════════

    /**
     * 🎯 **Uno sconto su un pacchetto solo non sconta gli altri.**
     *
     * ⛔ Senza il controllo, «20% sul pacchetto grande» diventerebbe il 20% su
     * tutto il listino dei gettoni.
     */
    #[Test]
    public function lo_sconto_su_un_pacchetto_vale_solo_su_quello(): void
    {
        [$piccolo, $grande] = $this->duePacchetti();
        $promozione = $this->promo(['pacchetto_id' => $grande->getKey()]);

        $servizio = app(LePromozioni::class);

        $suGrande = $servizio->applica($grande->prezzo_cent, [$promozione], SuCosaVale::Gettoni, $grande->getKey());
        $suPiccolo = $servizio->applica($piccolo->prezzo_cent, [$promozione], SuCosaVale::Gettoni, $piccolo->getKey());

        $this->assertSame((int) round($grande->prezzo_cent * 0.2), $suGrande['sconto']);
        $this->assertSame(0, $suPiccolo['sconto']);
        $this->assertSame([], $suPiccolo['applicate']);
    }

    /** 🎯 E uno sconto sui gettoni non tocca l'abbonamento, nemmeno se glielo si chiede. */
    #[Test]
    public function uno_sconto_sui_gettoni_non_sconta_il_canone(): void
    {
        $conto = app(LePromozioni::class)->applica(799, [$this->promo()], SuCosaVale::Canone);

        $this->assertSame(0, $conto['sconto']);
    }

    /**
     * ⚠️ **Il checkout sconta il pacchetto che si compra**, e lo dice a Stripe.
     *
     * Prova il giro vero: `Cassa::apri()` → coupon di Stripe con l'importo
     * calcolato da noi.
     */
    #[Test]
    public function la_cassa_sconta_il_pacchetto_giusto(): void
    {
        [$piccolo, $grande] = $this->duePacchetti();
        $this->promo(['pacchetto_id' => $grande->getKey()]);
        $persona = $this->persona();

        app(Cassa::class)->apri($persona, Cassa::GETTONI, $piccolo->gettoni);
        $this->assertSame([], $this->stripe->chiamateA('coupons.create'));

        app(Cassa::class)->apri($persona, Cassa::GETTONI, $grande->gettoni);
        $coupon = $this->stripe->ultima('coupons.create')[0];

        $this->assertSame((int) round($grande->prezzo_cent * 0.2), $coupon['amount_off']);
    }

    /** 💶 Il prezzo speciale toglie la differenza col listino, e non alza mai il prezzo. */
    #[Test]
    public function il_prezzo_speciale_toglie_la_differenza(): void
    {
        [, $grande] = $this->duePacchetti();

        $premio = new PromozionePremio([
            'tipo' => TipoDiPremio::PrezzoPacchetto,
            'pacchetto_id' => $grande->getKey(),
            'prezzo_cent' => 100,
        ]);

        $this->assertSame($grande->prezzo_cent - 100, $premio->scontoSu($grande->prezzo_cent));

        $premio->prezzo_cent = $grande->prezzo_cent + 500;
        $this->assertSame(0, $premio->scontoSu($grande->prezzo_cent));
    }

    // ═════════════════════════ chi vince ═════════════════════════

    /**
     * 🚨 **La priorità viene prima della convenienza** fra le non cumulabili.
     *
     * 📌 *«Cumulabile sì/no, e quale vince se ce ne sono più di una»*.
     */
    #[Test]
    public function fra_le_esclusive_vince_la_priorita_piu_alta(): void
    {
        $grossa = $this->promo(['valore' => 50]);
        $scelta = $this->promo(['valore' => 10], ['priorita' => 5]);

        $conto = app(LePromozioni::class)->applica(10000, [$grossa, $scelta], SuCosaVale::Gettoni);

        $this->assertSame(1000, $conto['sconto']);
        $this->assertSame($scelta->getKey(), $conto['applicate'][0]->getKey());
    }

    /** 💡 A parità di priorità, conviene ancora al cliente. */
    #[Test]
    public function a_parita_di_priorita_vince_la_piu_conveniente(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 10], ['priorita' => 3]),
            $this->promo(['valore' => 30], ['priorita' => 3]),
        ], SuCosaVale::Gettoni);

        $this->assertSame(3000, $conto['sconto']);
    }

    /** ⚠️ E un'esclusiva con priorità più alta batte anche le cumulabili che converrebbero di più. */
    #[Test]
    public function la_priorita_batte_anche_le_cumulabili(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 5], ['priorita' => 9]),
            $this->promo(['valore' => 30], ['cumulabile' => true]),
            $this->promo(['valore' => 30], ['cumulabile' => true]),
        ], SuCosaVale::Gettoni);

        $this->assertSame(500, $conto['sconto']);
    }

    // ═════════════════════════ i limiti ═════════════════════════

    /**
     * 🚨 **Il massimo per persona.** Opzione 34.
     *
     * ⛔ Prima una automatica si riapplicava a ogni acquisto della stessa
     * persona, finché non finiva il tetto totale.
     */
    #[Test]
    public function chi_l_ha_gia_presa_non_la_riprende(): void
    {
        $promozione = $this->promo([], ['massimo_per_persona' => 1]);
        $tizio = $this->persona('tizio@esempio.test');
        $caio = $this->persona('caio@esempio.test');

        $servizio = app(LePromozioni::class);
        $this->assertCount(1, $servizio->automaticheDi($tizio, SuCosaVale::Gettoni));

        $servizio->segnaUsate($promozione->codice, $tizio, 'cs_primo', 100);

        $this->assertCount(0, $servizio->automaticheDi($tizio, SuCosaVale::Gettoni));
        $this->assertCount(1, $servizio->automaticheDi($caio, SuCosaVale::Gettoni));
        $this->assertNull($servizio->conIlCodice($promozione->codice, $tizio));
    }

    /** 💡 Vuoto vuol dire senza limite: la stessa persona la riusa. */
    #[Test]
    public function senza_massimo_per_persona_si_riusa(): void
    {
        $promozione = $this->promo([], ['massimo_per_persona' => null]);
        $tizio = $this->persona();

        app(LePromozioni::class)->segnaUsate($promozione->codice, $tizio, 'cs_primo', 100);

        $this->assertCount(1, app(LePromozioni::class)->automaticheDi($tizio, SuCosaVale::Gettoni));
    }

    /**
     * 💶 **Il budget in euro si ferma al tetto.** Opzione 35.
     *
     * E una revoca lo restituisce: quei soldi non sono stati dati.
     */
    #[Test]
    public function il_budget_finito_spegne_la_promozione(): void
    {
        $promozione = $this->promo([], ['budget_cent' => 1000]);
        $tizio = $this->persona();

        app(LePromozioni::class)->segnaUsate($promozione->codice, $tizio, 'cs_uno', 600);
        $this->assertTrue($promozione->fresh()->eSpendibile());

        app(LePromozioni::class)->segnaUsate($promozione->codice, $tizio, 'cs_due', 400);
        $this->assertFalse($promozione->fresh()->eSpendibile());
        $this->assertSame('Questa offerta è esaurita.', $promozione->fresh()->perchePiuNonVale());

        PromozioneAssegnazione::query()->where('riferimento', 'cs_due')->update(['stato' => StatoAssegnazione::Revocata]);
        $this->assertTrue($promozione->fresh()->eSpendibile());
    }

    // ═════════════════════════ il webhook ═════════════════════════

    /**
     * 🐛 **Il webhook segna l'uso, e una volta sola.** P1.6.
     *
     * ⛔ Fino al 23/09 non lo segnava nessuno. E Stripe rimanda gli eventi:
     * la stessa sessione due volte deve contare uno.
     */
    #[Test]
    public function il_pagamento_segna_l_uso_una_volta_sola(): void
    {
        [, $grande] = $this->duePacchetti();
        $promozione = $this->promo([], ['massimo_usi' => 10]);
        $tizio = $this->persona();

        $evento = [
            'id' => 'evt_con_sconto',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_con_sconto',
                'mode' => 'payment',
                'amount_total' => 800,
                'total_details' => ['amount_discount' => 200],
                'metadata' => [
                    'tipo' => Cassa::GETTONI,
                    'user_id' => (string) $tizio->getKey(),
                    'tenant_id' => (string) $tizio->tenant_id,
                    'gettoni' => (string) $grande->gettoni,
                    'promozioni' => $promozione->codice,
                ],
            ]],
        ];

        app(Cassa::class)->applica($evento);
        app(Cassa::class)->applica($evento);

        $assegnazione = PromozioneAssegnazione::query()->sole();

        $this->assertSame(1, $promozione->fresh()->usata_volte);
        $this->assertSame(StatoAssegnazione::Usata, $assegnazione->stato);
        $this->assertSame(200, $assegnazione->scontato_cent);
        $this->assertSame($tizio->getKey(), $assegnazione->user_id);
    }

    // ═════════════════════════ il modulo ═════════════════════════

    /**
     * 🎀 **Il genere decide i premi.** 📌 *«devo avere un form diverso per
     * ciascuna scelta»*.
     */
    #[Test]
    public function ogni_genere_offre_solo_i_suoi_premi(): void
    {
        $gettoni = array_keys(TipoDiPremio::optionsPer(GenereDiPromozione::Gettoni));
        $abbonamenti = array_keys(TipoDiPremio::optionsPer(GenereDiPromozione::Abbonamenti));

        $this->assertContains(TipoDiPremio::ScontoGettoni->value, $gettoni);
        $this->assertNotContains(TipoDiPremio::ScontoAbbonamento->value, $gettoni);

        $this->assertContains(TipoDiPremio::ScontoAbbonamento->value, $abbonamenti);
        $this->assertNotContains(TipoDiPremio::ScontoGettoni->value, $abbonamenti);
    }

    /**
     * 🚧 **Un premio non ancora costruito non si sceglie** — ma uno già salvato
     * resta leggibile, o la modifica non si salverebbe.
     */
    #[Test]
    public function i_premi_non_ancora_costruiti_non_si_scelgono(): void
    {
        $this->assertArrayNotHasKey(TipoDiPremio::ScontoConsumo->value, TipoDiPremio::optionsPer(GenereDiPromozione::Abbonamenti));

        $conQuello = TipoDiPremio::optionsPer(GenereDiPromozione::Abbonamenti, TipoDiPremio::ScontoConsumo->value);
        $this->assertArrayHasKey(TipoDiPremio::ScontoConsumo->value, $conQuello);
        $this->assertStringContainsString('in arrivo', $conQuello[TipoDiPremio::ScontoConsumo->value]);
    }

    /** 🧹 Un premio cambiato di tipo non si porta dietro i valori di prima. */
    #[Test]
    public function il_modulo_svuota_i_campi_che_il_premio_non_usa(): void
    {
        $pulito = ModuloDellaPromozione::pulisci([
            'tipo' => TipoDiPremio::ScontoGettoni->value,
            'tipo_sconto' => 'percentuale',
            'valore' => 20,
            'giorni' => 30,
            'plan_id' => 4,
        ]);

        $this->assertSame(20, $pulito['valore']);
        $this->assertNull($pulito['giorni']);
        $this->assertNull($pulito['plan_id']);
    }

    /**
     * 🧙 **La procedura a passi crea la promozione coi suoi premi**, e gli
     * euro diventano centesimi.
     *
     * ⛔ Il modulo di F2 chiedeva i centesimi a mano: «5» voleva dire cinque
     * centesimi.
     */
    #[Test]
    public function la_procedura_a_passi_salva_premi_in_centesimi(): void
    {
        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(CreatePromozione::class)
            ->fillForm([
                'genere' => GenereDiPromozione::Abbonamenti->value,
                'premi' => [[
                    'tipo' => TipoDiPremio::ScontoAbbonamento->value,
                    'tipo_sconto' => TipoDiSconto::Importo->value,
                    'valore' => 5.5,
                    'durata' => DurataDelloSconto::UnaVolta->value,
                ]],
                'bersagli' => [['tipo' => TipoBersaglio::Tutti->value]],
                'attivazione' => ComeSiAttiva::Automatica->value,
                'massimo_per_persona' => 1,
                'nome' => 'Cinque e cinquanta',
                'codice' => 'CINQUE550',
                'stato' => 'attiva',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promozione = Promozione::query()->with('premi')->where('codice', 'CINQUE550')->sole();

        $this->assertSame(GenereDiPromozione::Abbonamenti, $promozione->genere);
        $this->assertSame(550, $promozione->premi->sole()->valore);
        $this->assertSame(1, $promozione->massimo_per_persona);
        $this->assertNotNull($promozione->creata_da_id);
    }
}
