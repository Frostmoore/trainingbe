<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\DurataDelloSconto;
use App\Enums\GenereDiPromozione;
use App\Enums\StatoPromozione;
use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Services\Billing\LePromozioni;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎁 **Le promozioni: chi le prende e quanto tolgono** — 22/09/2026, fase F2.
 *
 * ══ 🚨 PERCHÉ QUESTO FILE È LUNGO ════════════════════════════════════════
 *
 * Perché qui si decide **quanto paga la gente**, e tutti gli errori possibili
 * sono silenziosi: uno sconto che si somma invece di incolonnarsi regala il 6%
 * e nessuno se ne accorge; un bersaglio letto in AND invece che in OR non dà
 * la promozione a nessuno e sembra che non funzioni; un importo fisso più
 * grande del prezzo produce un totale **negativo** che Stripe rifiuta **dopo**
 * aver mostrato il prezzo al cliente.
 */
class LePromozioniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🆕 P1 — `tipo_sconto`, `valore` e `su_cosa` non stanno più sulla riga:
     * l'helper li accetta ancora e ne fa **premi**, uno per voce di `su_cosa`,
     * così i casi di F2 restano scritti come erano.
     */
    private function promo(array $extra = []): Promozione
    {
        $tipoSconto = $extra['tipo_sconto'] ?? TipoDiSconto::Percentuale;
        $valore = $extra['valore'] ?? 20;
        $suCosa = $extra['su_cosa'] ?? [SuCosaVale::Gettoni->value, SuCosaVale::Canone->value];
        unset($extra['tipo_sconto'], $extra['valore'], $extra['su_cosa']);

        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Offerta di prova',
            'genere' => GenereDiPromozione::Entrambi,
            'cumulabile' => false,
        ], $extra));

        foreach ($suCosa as $voce) {
            PromozionePremio::query()->create([
                'promozione_id' => $promozione->getKey(),
                'tipo' => $voce === SuCosaVale::Gettoni->value ? TipoDiPremio::ScontoGettoni : TipoDiPremio::ScontoAbbonamento,
                'tipo_sconto' => $tipoSconto,
                'valore' => $valore,
                'durata' => $voce === SuCosaVale::Gettoni->value ? null : DurataDelloSconto::UnaVolta,
            ]);
        }

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Tutti,
        ]);

        return $promozione->fresh(['bersagli', 'premi']);
    }

    // ═════════════════════════ il conto ═════════════════════════

    /** 💡 Una sola promozione: il caso semplice, e serve come riferimento. */
    #[Test]
    public function una_percentuale_toglie_la_sua_parte(): void
    {
        $conto = app(LePromozioni::class)->applica(1000, [$this->promo(['valore' => 20])], SuCosaVale::Gettoni);

        $this->assertSame(200, $conto['sconto']);
        $this->assertSame(800, $conto['finale']);
    }

    /**
     * 🚨 **30% + 20% fa 44%, non 50%.**
     *
     * ⛔ Sommare le percentuali è il modo classico di regalare il 6% senza
     * accorgersene: la seconda si applica a **quello che resta**, non
     * all'importo di partenza.
     */
    #[Test]
    public function due_cumulabili_si_incolonnano_non_si_sommano(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 30, 'cumulabile' => true]),
            $this->promo(['valore' => 20, 'cumulabile' => true]),
        ], SuCosaVale::Gettoni);

        // 10.000 − 30% = 7.000; 7.000 − 20% = 5.600.
        $this->assertSame(5600, $conto['finale']);
        $this->assertSame(4400, $conto['sconto']);
        $this->assertCount(2, $conto['applicate']);
    }

    /**
     * 🚨 **Fra le non cumulabili vince la migliore**, non la prima trovata.
     *
     * ⛔ Chi riceve due offerte si aspetta quella che conviene a lui: il
     * contrario si legge come una fregatura, e per giunta è una scelta fatta
     * dall'ordine di una query.
     */
    #[Test]
    public function fra_le_esclusive_vince_quella_che_conviene(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 10]),
            $this->promo(['valore' => 35]),
            $this->promo(['valore' => 5]),
        ], SuCosaVale::Gettoni);

        $this->assertSame(3500, $conto['sconto']);
        $this->assertCount(1, $conto['applicate']);
    }

    /**
     * ⚠️ **«Non cumulabile» vuol dire «da sola»**, non «da sola fra le sue
     * pari»: se conviene di più lei, le cumulabili restano fuori.
     */
    #[Test]
    public function una_esclusiva_che_conviene_esclude_anche_le_cumulabili(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 50]),
            $this->promo(['valore' => 10, 'cumulabile' => true]),
            $this->promo(['valore' => 10, 'cumulabile' => true]),
        ], SuCosaVale::Gettoni);

        // 50% da sola batte 10% + 10% incolonnati (19%).
        $this->assertSame(5000, $conto['sconto']);
        $this->assertCount(1, $conto['applicate']);
    }

    /** 💡 E se conviene la coppia, si tiene la coppia. */
    #[Test]
    public function ma_se_conviene_la_coppia_si_tiene_la_coppia(): void
    {
        $conto = app(LePromozioni::class)->applica(10000, [
            $this->promo(['valore' => 15]),
            $this->promo(['valore' => 20, 'cumulabile' => true]),
            $this->promo(['valore' => 20, 'cumulabile' => true]),
        ], SuCosaVale::Gettoni);

        // 20% + 20% incolonnati = 36%, meglio del 15% da solo.
        $this->assertSame(3600, $conto['sconto']);
        $this->assertCount(2, $conto['applicate']);
    }

    /**
     * ⛔ **Non si scende sotto zero.** Uno sconto a importo più grande del
     * prezzo non regala la differenza.
     */
    #[Test]
    public function uno_sconto_piu_grande_del_prezzo_si_ferma_a_zero(): void
    {
        $conto = app(LePromozioni::class)->applica(300, [
            $this->promo(['tipo_sconto' => TipoDiSconto::Importo, 'valore' => 5000]),
        ], SuCosaVale::Gettoni);

        $this->assertSame(0, $conto['finale']);
        $this->assertSame(300, $conto['sconto']);
    }

    /** ⚠️ E una volta a zero, le altre non compaiono come se avessero fatto qualcosa. */
    #[Test]
    public function arrivati_a_zero_le_altre_non_si_applicano(): void
    {
        $conto = app(LePromozioni::class)->applica(1000, [
            $this->promo(['tipo_sconto' => TipoDiSconto::Importo, 'valore' => 1000, 'cumulabile' => true]),
            $this->promo(['valore' => 50, 'cumulabile' => true]),
        ], SuCosaVale::Gettoni);

        $this->assertSame(0, $conto['finale']);
        $this->assertCount(1, $conto['applicate']);
    }

    /** 💡 Nessuna promozione: il prezzo resta quello. */
    #[Test]
    public function senza_promozioni_non_cambia_niente(): void
    {
        $conto = app(LePromozioni::class)->applica(1234, [], SuCosaVale::Gettoni);

        $this->assertSame(1234, $conto['finale']);
        $this->assertSame(0, $conto['sconto']);
        $this->assertSame([], $conto['applicate']);
    }

    // ═════════════════════════ chi la prende ═════════════════════════

    /** 🎯 «Tutti» vuol dire tutti. */
    #[Test]
    public function il_bersaglio_tutti_prende_chiunque(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $this->assertTrue(app(LePromozioni::class)->loPrende($this->promo(), $persona));
    }

    /**
     * 🚨 Il bersaglio «palestra» guarda **dove la persona sta**, non la sua
     * colonna `tenant_id`.
     */
    #[Test]
    public function il_bersaglio_palestra_prende_chi_ci_sta_dentro(): void
    {
        $palestra = $this->creaPalestra();
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $dentro = $this->creaUtente($palestra, UserRole::Member, 'dentro@esempio.test');
        $fuori = $this->creaUtente($altra, UserRole::Member, 'fuori@esempio.test');

        $promozione = $this->promo();
        $promozione->bersagli()->delete();

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Palestra,
            'tenant_id' => $palestra->getKey(),
        ]);

        $servizio = app(LePromozioni::class);
        $promozione = $promozione->fresh('bersagli');

        $this->assertTrue($servizio->loPrende($promozione, $dentro));
        $this->assertFalse($servizio->loPrende($promozione, $fuori));
    }

    /** 🎯 Il ruolo, che è il caso «per tutti i trainer». */
    #[Test]
    public function il_bersaglio_ruolo_prende_chi_ha_quel_ruolo(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');

        $promozione = $this->promo();
        $promozione->bersagli()->delete();

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Ruolo,
            'ruolo' => UserRole::Trainer->value,
        ]);

        $servizio = app(LePromozioni::class);
        $promozione = $promozione->fresh('bersagli');

        $this->assertTrue($servizio->loPrende($promozione, $trainer));
        $this->assertFalse($servizio->loPrende($promozione, $iscritto));
    }

    /**
     * ⚠️ **I bersagli sono in OR.** «Per i trainer e per Tizio» vuol dire chi è
     * trainer **oppure** è Tizio.
     */
    #[Test]
    public function piu_bersagli_si_sommano_non_si_intersecano(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach2@esempio.test');
        $tizio = $this->creaUtente($palestra, UserRole::Member, 'tizio2@esempio.test');
        $nessuno = $this->creaUtente($palestra, UserRole::Member, 'nessuno@esempio.test');

        $promozione = $this->promo();
        $promozione->bersagli()->delete();

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Ruolo,
            'ruolo' => UserRole::Trainer->value,
        ]);

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Utente,
            'user_id' => $tizio->getKey(),
        ]);

        $servizio = app(LePromozioni::class);
        $promozione = $promozione->fresh('bersagli');

        $this->assertTrue($servizio->loPrende($promozione, $trainer));
        $this->assertTrue($servizio->loPrende($promozione, $tizio));
        $this->assertFalse($servizio->loPrende($promozione, $nessuno));
    }

    // ═════════════════════════ quando vale ═════════════════════════

    /** ⛔ Sospesa: non si spende. */
    #[Test]
    public function una_promozione_sospesa_non_si_spende(): void
    {
        $promozione = $this->promo(['stato' => StatoPromozione::Sospesa]);

        $this->assertFalse($promozione->eSpendibile());
        $this->assertSame('Questa offerta è sospesa.', $promozione->perchePiuNonVale());
    }

    /** ⛔ Scaduta: nemmeno. E il motivo si distingue da «sospesa». */
    #[Test]
    public function una_promozione_scaduta_lo_dice(): void
    {
        $promozione = $this->promo(['scade_il' => now()->subDay()]);

        $this->assertFalse($promozione->eSpendibile());
        $this->assertTrue($promozione->eScaduta());
        $this->assertSame('Questa offerta è scaduta.', $promozione->perchePiuNonVale());
    }

    /** ⛔ Esaurita: il tetto degli usi è un tetto vero. */
    #[Test]
    public function una_promozione_esaurita_non_si_spende_piu(): void
    {
        $promozione = $this->promo(['massimo_usi' => 2]);
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'esaurita@esempio.test');

        // 🆕 P1 — l'uso lo segna il webhook, una volta per sessione pagata.
        app(LePromozioni::class)->segnaUsate($promozione->codice, $persona, 'cs_uno', 100);
        app(LePromozioni::class)->segnaUsate($promozione->codice, $persona, 'cs_due', 100);

        $this->assertTrue($promozione->refresh()->eEsaurita());
        $this->assertSame('Questa offerta è esaurita.', $promozione->perchePiuNonVale());
    }

    /**
     * 🚨 **Il codice non basta: dice quale offerta, non a chi spetta.**
     *
     * ⛔ Senza il controllo sui bersagli, un codice inoltrato varrebbe per
     * chiunque.
     */
    #[Test]
    public function un_codice_inoltrato_a_chi_non_c_entra_non_vale(): void
    {
        $palestra = $this->creaPalestra();
        $dentro = $this->creaUtente($palestra, UserRole::Member, 'suo@esempio.test');

        $estraneo = app(CreaTenantPersonale::class)(
            'Estraneo', 'estraneo@esempio.test', ['password' => self::FAKE_PASSWORD],
        )->fresh();

        $promozione = $this->promo(['attivazione' => 'link']);
        $promozione->bersagli()->delete();

        PromozioneBersaglio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Palestra,
            'tenant_id' => $palestra->getKey(),
        ]);

        $servizio = app(LePromozioni::class);

        $this->assertNotNull($servizio->dalLink($promozione->codice, $dentro, SuCosaVale::Gettoni));
        $this->assertNull($servizio->dalLink($promozione->codice, $estraneo, SuCosaVale::Gettoni));
    }

    /**
     * ⚠️ **Le promozioni con link non compaiono da sole.** È il senso di
     * averle divise: una si spende aprendo l'indirizzo, l'altra ce l'hai.
     */
    #[Test]
    public function quelle_col_link_non_si_applicano_da_sole(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'auto@esempio.test');

        $this->promo(['attivazione' => 'link']);
        $automatica = $this->promo(['attivazione' => 'automatica']);

        $trovate = app(LePromozioni::class)->automaticheDi($persona, SuCosaVale::Gettoni);

        $this->assertCount(1, $trovate);
        $this->assertSame($automatica->getKey(), $trovate->first()->getKey());
    }

    /** 🎯 E una promozione sui gettoni non sconta un abbonamento. */
    #[Test]
    public function una_promozione_vale_solo_su_quello_che_dice(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'soloGettoni@esempio.test');

        $this->promo(['su_cosa' => [SuCosaVale::Gettoni->value]]);

        $servizio = app(LePromozioni::class);

        $this->assertCount(1, $servizio->automaticheDi($persona, SuCosaVale::Gettoni));
        $this->assertCount(0, $servizio->automaticheDi($persona, SuCosaVale::Canone));
    }
}
