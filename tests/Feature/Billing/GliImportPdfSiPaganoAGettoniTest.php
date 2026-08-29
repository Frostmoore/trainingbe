<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PortafoglioGettoni;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎯 Gli import da PDF si pagano **sempre e solo** a gettoni — U.6.
 *
 * 📌 *«L'import dei pdf costa SEMPRE 50 gettoni, abbonato o no. E devono essere
 * proprio GETTONI, non si puo' usare la quota flat»* — 28/08/2026.
 *
 * ── 🚨 Perche' questa classe esiste, e non basta `CicloDeiGettoniTest` ─────
 *
 * Perche' **tutti i test esistenti hanno gettoni**, e con i gettoni in tasca la
 * modifica di U.6 e' invisibile: il cancello si apre lo stesso, la chiamata
 * riesce lo stesso. ⛔ L'unico caso che la distingue e' quello che non era mai
 * stato scritto — **quota piena e portafoglio vuoto** — e li' prima si passava e
 * adesso no.
 *
 * ── ⚠️ Le condizioni che devono valere tutte ──────────────────────────────
 *
 * | # | Condizione |
 * |---|---|
 * | 1 | i due PDF costano **50** |
 * | 2 | con la quota **piena** e i gettoni in tasca, si paga **coi gettoni** |
 * | 3 | quota piena e **zero gettoni** → si rifiuta |
 * | 4 | e si rifiuta col messaggio dei **gettoni**, non con quello della quota |
 * | 5 | per tutte le **altre** funzioni la quota viene ancora prima |
 */
final class GliImportPdfSiPaganoAGettoniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
        $this->iscritto->accendiLAi();
    }

    private function cancello(): CancelloDeiGettoni
    {
        return app(CancelloDeiGettoni::class);
    }

    private function gettoni(int $quanti): void
    {
        $this->palestra->forceFill(['ai_credits' => $quanti])->save();
        $this->iscritto->refresh()->load('tenant');
    }

    /**
     * 🔢 **Il listino, che e' l'unica cosa che il committente ha detto in cifre.**
     *
     * ⚠️ La scheda stava a **10** e non perche' qualcuno l'avesse deciso: cadeva
     * nel default dei multimodali. Un prezzo per omissione non lascia tracce, ed
     * e' esattamente il motivo per cui va inchiodato da un test.
     */
    #[Test]
    public function i_due_pdf_costano_cinquanta(): void
    {
        $this->assertSame(50, AiFeature::PdfImport->costoInGettoni());
        $this->assertSame(50, AiFeature::NutritionPdfImport->costoInGettoni());

        // 💡 Il contorno, per essere sicuri che non sia salito tutto: una foto
        // resta a 10 e una chiamata di testo a 1.
        $this->assertSame(10, AiFeature::FoodPhoto->costoInGettoni());
        $this->assertSame(1, AiFeature::FoodText->costoInGettoni());
    }

    /**
     * 🚨 **Il cuore di U.6**: quota intatta, eppure paga coi gettoni.
     *
     * ⛔ Prima di oggi questo ritornava `false` — cioe' «la copre la quota» — e
     * l'import non toccava il portafoglio.
     */
    #[Test]
    public function con_la_quota_piena_un_pdf_paga_lo_stesso_coi_gettoni(): void
    {
        $this->gettoni(100);

        // 🔎 La premessa del test: la quota **c'e' davvero**. Senza questa
        // riga il test passerebbe anche in un mondo dove la quota e' finita,
        // cioe' proverebbe un'altra cosa.
        $this->assertTrue(
            app(MemberAiQuota::class)->hasQuotaLeft($this->iscritto, AiFeature::PdfImport),
            'La premessa non regge: la quota risulta gia\' esaurita.',
        );

        $this->assertTrue(
            $this->cancello()->apri($this->iscritto, AiFeature::PdfImport),
            'Con quota piena l\'import della scheda deve pagare coi gettoni.',
        );

        $this->assertTrue(
            $this->cancello()->apri($this->iscritto, AiFeature::NutritionPdfImport),
            'Con quota piena l\'import del piano deve pagare coi gettoni.',
        );
    }

    /**
     * ⚖️ **Il contrappeso**: per tutto il resto la regola di sempre non si muove.
     *
     * 🚨 Senza questo test, «salta la quota» poteva essere stato scritto **per
     * tutte** le funzioni e nessuno se ne sarebbe accorto: i gettoni ci sono, le
     * chiamate riescono, solo il saldo cala. E' il difetto che
     * `CancelloDeiGettoni` descrive da solo — *«un gettone speso mentre la quota
     * e' ancora piena e' un gettone rubato»*.
     */
    #[Test]
    public function per_le_altre_funzioni_la_quota_viene_ancora_prima(): void
    {
        $this->gettoni(100);

        $this->assertFalse(
            $this->cancello()->apri($this->iscritto, AiFeature::FoodText),
            'Una chiamata di testo con la quota piena non deve toccare i gettoni.',
        );

        $this->assertFalse(
            $this->cancello()->apri($this->iscritto, AiFeature::FoodPhoto),
            'Nemmeno una foto: l\'eccezione e\' solo per i PDF.',
        );

        $this->assertSame(100, app(PortafoglioGettoni::class)->saldo($this->iscritto));
    }

    /**
     * 🎯 **U.6.4 — il test che il committente ha chiesto in una riga.**
     *
     * *«un abbonato con quota piena e zero gettoni non deve poter importare»*.
     *
     * ⛔ E' anche l'unico che sarebbe rosso su tutti i test scritti finora: tutti
     * gli altri hanno gettoni in tasca, e a gettoni pieni la modifica non si
     * vede.
     */
    #[Test]
    public function quota_piena_e_zero_gettoni_non_bastano_per_un_pdf(): void
    {
        $this->gettoni(0);

        $this->expectException(GettoniEsauritiException::class);

        $this->cancello()->apri($this->iscritto, AiFeature::PdfImport);
    }

    /**
     * 🚨 **E il messaggio deve essere quello giusto.**
     *
     * Il cancello ha un ripiego cortese: a chi non ha **mai** comprato gettoni
     * manda il messaggio della quota, perche' «ricarica i gettoni» non dice
     * niente a chi non sa che esistano.
     *
     * ⛔ Per un PDF quel ripiego e' una **bugia**: direbbe «hai finito la quota
     * del mese» a chi ce l'ha intatta, e quella persona aspetterebbe il primo
     * del mese per una cosa che il primo del mese non sistema.
     *
     * 💡 Chi arriva qui non ha mai comprato niente — nessun `AiCreditMovement` —
     * cioe' e' proprio il caso in cui il ripiego scatterebbe.
     */
    #[Test]
    public function chi_non_ha_mai_comprato_gettoni_non_riceve_il_messaggio_della_quota(): void
    {
        $this->gettoni(0);

        try {
            $this->cancello()->apri($this->iscritto, AiFeature::NutritionPdfImport);

            $this->fail('Doveva rifiutare: zero gettoni e i PDF non usano la quota.');
        } catch (AiQuotaExceededException) {
            $this->fail(
                'Ha risposto «quota esaurita» a chi ha la quota intatta: '.
                'e\' il ripiego cortese, che per i PDF non deve scattare.',
            );
        } catch (GettoniEsauritiException $e) {
            $this->assertSame(0, $e->saldo);

            // 💡 «Te ne servono 50 e ne hai 0» e' l'unico messaggio che dice
            // **quanto** ricaricare.
            $this->assertSame(50, $e->servivano);
        }
    }

    /**
     * ⚠️ **Quarantanove non sono cinquanta.**
     *
     * 🚨 Il caso al bordo: con il prezzo vecchio (10) questo saldo bastava, e il
     * test sarebbe verde per la ragione sbagliata. E' quello che inchioda il
     * fatto che il prezzo applicato sia davvero 50, non solo scritto nell'enum.
     */
    #[Test]
    public function quarantanove_gettoni_non_bastano_per_un_pdf(): void
    {
        $this->gettoni(49);

        try {
            $this->cancello()->apri($this->iscritto, AiFeature::PdfImport);

            $this->fail('49 gettoni non bastano per un import da 50.');
        } catch (GettoniEsauritiException $e) {
            $this->assertSame(49, $e->saldo);
            $this->assertSame(50, $e->servivano);
        }

        // 💡 E cinquanta bastano: il bordo si prova da tutti e due i lati,
        // altrimenti «rifiuta sempre» passerebbe per «rifiuta al punto giusto».
        $this->gettoni(50);

        $this->assertTrue($this->cancello()->apri($this->iscritto, AiFeature::PdfImport));
    }

    /**
     * 💰 **E quando si consuma, ne scala cinquanta.**
     *
     * ⚠️ `apri()` decide, `consuma()` esegue: sono due metodi e due momenti — nel
     * caso vero, due processi diversi con una coda in mezzo. Provare solo il
     * primo lascerebbe scoperta la meta' che tocca i soldi.
     */
    #[Test]
    public function il_consumo_scala_cinquanta_gettoni(): void
    {
        $this->gettoni(120);

        $conGettoni = $this->cancello()->apri($this->iscritto, AiFeature::PdfImport);

        $this->cancello()->consuma($this->iscritto, AiFeature::PdfImport, $conGettoni);

        $this->assertSame(
            70,
            app(PortafoglioGettoni::class)->saldo($this->iscritto->refresh()),
        );
    }
}
