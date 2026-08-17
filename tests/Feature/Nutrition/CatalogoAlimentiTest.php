<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Services\Nutrition\Catalogo\AlimentoAmmissibile;
use App\Services\Nutrition\Catalogo\ChiaveAlimento;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Il catalogo degli alimenti — Parte L, 17/08/2026.
 *
 * 🚨 **Queste due classi decidono la qualita' di tutto il catalogo.** La chiave
 * stabilisce cosa vuol dire «stesso alimento»; il filtro stabilisce cosa entra.
 * Sono anche le uniche due che, sbagliando, sbagliano **in silenzio**: una
 * chiave troppo larga fonde alimenti diversi, una troppo stretta riempie il
 * catalogo di doppioni, e in nessuno dei due casi qualcosa fallisce.
 */
class CatalogoAlimentiTest extends TestCase
{
    // ═══════════════════ la chiave ═══════════════════

    /**
     * 🚨 **Il caso per cui la chiave esiste.**
     *
     * Il committente ha chiesto «scarta se ha lo stesso nome». ⚠️ Confrontare i
     * nomi cosi' come sono scritti non scarterebbe **nessuno** di questi
     * quattro, che per una persona sono lo stesso identico alimento.
     */
    #[Test]
    public function four_ways_of_writing_the_same_food_collapse_into_one(): void
    {
        $chiavi = new ChiaveAlimento;

        $attesa = $chiavi->da('Pasta al pomodoro');

        foreach (['pasta al pomodoro', 'Pasta  al  pomodoro', ' Pasta al pomodoro ', 'PASTA AL POMODORO'] as $variante) {
            $this->assertSame($attesa, $chiavi->da($variante), "«{$variante}» non collide");
        }
    }

    /** 💡 Gli accenti: sulla tastiera del telefono sono un tasto in piu' che meta' delle persone non preme. */
    #[Test]
    public function accents_do_not_split_a_food_in_two(): void
    {
        $chiavi = new ChiaveAlimento;

        $this->assertSame($chiavi->da('Purè di patate'), $chiavi->da('Pure di patate'));
        $this->assertSame($chiavi->da('Cioccolato fondente'), $chiavi->da('Cioccolato, fondente'));
    }

    /**
     * 🚨 **La marca fa parte dell'identita'.**
     *
     * ⚠️ Fondere due whey alla fragola di produttori diversi darebbe a entrambe
     * i valori della prima arrivata — e sono prodotti con macro diversi.
     */
    #[Test]
    public function the_same_name_from_two_brands_stays_two_foods(): void
    {
        $chiavi = new ChiaveAlimento;

        $this->assertNotSame(
            $chiavi->da('Whey fragola', 'Prozis'),
            $chiavi->da('Whey fragola', 'MyProtein'),
        );
    }

    /** ⚠️ Una chiave vuota collegherebbe fra loro alimenti senza niente in comune. */
    #[Test]
    public function a_name_made_only_of_filler_words_still_produces_a_key(): void
    {
        $this->assertNotSame('', (new ChiaveAlimento)->da('di e con'));
    }

    // ═══════════════════ il filtro ═══════════════════

    /**
     * 🚨 **Il controllo che tiene in piedi il catalogo.**
     *
     * Non era stato chiesto. ⚠️ Senza, il primo che digita «Riso 3.500 kcal»
     * invece di 350 lo pubblica per tutti, e da li' in poi lo suggeriamo noi.
     */
    #[Test]
    public function a_misplaced_decimal_point_does_not_get_in(): void
    {
        $filtro = new AlimentoAmmissibile;

        // Riso vero: 332 kcal, 6.7 P, 80.4 C, 0.4 G.
        $this->assertTrue($filtro->ammesso('Riso', [
            'kcal_100' => 332.0, 'protein_100' => 6.7, 'carbs_100' => 80.4, 'fat_100' => 0.4,
        ]));

        // Lo stesso riso con la virgola sbagliata.
        $this->assertNotNull($filtro->motivoDelRifiuto('Riso', [
            'kcal_100' => 3320.0, 'protein_100' => 6.7, 'carbs_100' => 80.4, 'fat_100' => 0.4,
        ]));
    }

    #[Test]
    public function nothing_can_exceed_pure_fat(): void
    {
        // 🚨 900 kcal per 100 g e' il grasso puro. Non esiste niente di piu'.
        $this->assertSame(
            'piu\' di 900 kcal per 100 g: impossibile',
            (new AlimentoAmmissibile)->motivoDelRifiuto('Olio strano', [
                'kcal_100' => 950.0, 'protein_100' => 0.0, 'carbs_100' => 0.0, 'fat_100' => 100.0,
            ]),
        );
    }

    /** ⚠️ Cento grammi di alimento non possono contenere piu' di cento grammi di roba. */
    #[Test]
    public function the_macros_cannot_weigh_more_than_the_food(): void
    {
        $this->assertStringContainsString(
            'piu\' di 100 g',
            (string) (new AlimentoAmmissibile)->motivoDelRifiuto('Impossibile', [
                'kcal_100' => 600.0, 'protein_100' => 80.0, 'carbs_100' => 60.0, 'fat_100' => 5.0,
            ]),
        );
    }

    /** Il requisito chiesto: senza tutti e quattro i macro non si entra. */
    #[Test]
    public function a_missing_macro_keeps_the_food_out(): void
    {
        $this->assertSame(
            'manca protein_100',
            (new AlimentoAmmissibile)->motivoDelRifiuto('Mela', [
                'kcal_100' => 52.0, 'protein_100' => null, 'carbs_100' => 13.8, 'fat_100' => 0.2,
            ]),
        );
    }

    /**
     * 💡 **Gli alimenti quasi acalorici passano lo stesso.**
     *
     * ⚠️ Su 5 kcal uno scarto di 2 e' il 40 %, e un controllo cieco
     * scarterebbe le verdure, il brodo e l'acqua aromatizzata — cioe' proprio
     * le cose che nel diario si scrivono di continuo.
     */
    #[Test]
    public function almost_calorie_free_foods_are_not_rejected(): void
    {
        $this->assertTrue((new AlimentoAmmissibile)->ammesso('Lattuga', [
            'kcal_100' => 19.0, 'protein_100' => 1.8, 'carbs_100' => 2.2, 'fat_100' => 0.4,
        ]));
    }

    /**
     * 🚨 **Un pasto non e' un alimento.**
     *
     * ⚠️ «Pasta al pomodoro e due uova» e' esattamente come si scrive in un
     * diario, quindi arriverebbe di continuo — e come riga di catalogo non
     * serve a nessuno.
     */
    #[Test]
    public function a_meal_does_not_become_a_catalogue_entry(): void
    {
        $filtro = new AlimentoAmmissibile;

        $macroValidi = ['kcal_100' => 150.0, 'protein_100' => 8.0, 'carbs_100' => 20.0, 'fat_100' => 4.0];

        $this->assertNotNull($filtro->motivoDelRifiuto('Pasta al pomodoro e due uova con pane', $macroValidi));

        // 💡 Ma «pasta e fagioli» resta un alimento: e' nel catalogo CREA.
        $this->assertNull($filtro->motivoDelRifiuto('Pasta e fagioli', $macroValidi));
    }

    // ═══ le tre correzioni imparate dalla prima importazione CREA ═══

    /**
     * 🚨 **La fibra e l'alcol portano calorie che i quattro macro non spiegano.**
     *
     * ── Come si e' scoperto ───────────────────────────────────────────────
     *
     * La prima importazione CREA ha scartato 77 alimenti su 832. Fra questi:
     * carciofi, crusca, lamponi, zucca, funghi — tutti **ricchi di fibra** — e
     * la birra. ⚠️ Non era un problema dei dati: la formula `4·P + 4·C + 9·G`
     * non vede la fibra (~2 kcal/g) ne' l'alcol (7 kcal/g), e quindi calcolava
     * meno calorie di quelle vere.
     */
    #[Test]
    public function fibre_and_alcohol_are_counted_in_the_energy_check(): void
    {
        $filtro = new AlimentoAmmissibile;

        // Carciofi crudi (CREA): 33 kcal, e i macro ne spiegano solo 23.
        $carciofi = ['kcal_100' => 33.0, 'protein_100' => 2.7, 'carbs_100' => 2.5, 'fat_100' => 0.2];

        $this->assertNotNull($filtro->motivoDelRifiuto('Carciofi', $carciofi));
        $this->assertNull($filtro->motivoDelRifiuto('Carciofi', $carciofi, ['fibra_100' => 5.5]));

        // Birra chiara (CREA): 34 kcal, quasi tutte dall'alcol.
        $birra = ['kcal_100' => 34.0, 'protein_100' => 0.2, 'carbs_100' => 3.5, 'fat_100' => 0.0];

        $this->assertNotNull($filtro->motivoDelRifiuto('Birra chiara', $birra));
        $this->assertNull($filtro->motivoDelRifiuto('Birra chiara', $birra, ['alcol_100' => 3.5]));
    }

    /**
     * 🚨 **Il controllo «sembra un pasto» vale solo per il testo scritto a mano.**
     *
     * ⚠️ Applicato a una fonte dichiarata scartava nomi legittimi delle Tabelle
     * CREA: «Pizza con pomodoro e mozzarella» e «Bovino adulto, geretto
     * anteriore e posteriore» sono **un alimento solo**. Il difetto che il
     * controllo esiste per fermare arriva dalla tastiera di qualcuno, non da un
     * ente di ricerca.
     */
    #[Test]
    public function the_meal_check_does_not_apply_to_a_declared_source(): void
    {
        $filtro = new AlimentoAmmissibile;
        $macro = ['kcal_100' => 255.0, 'protein_100' => 10.0, 'carbs_100' => 35.0, 'fat_100' => 8.0];

        $this->assertNotNull($filtro->motivoDelRifiuto('Pizza con pomodoro e mozzarella', $macro));
        $this->assertNull($filtro->motivoDelRifiuto('Pizza con pomodoro e mozzarella', $macro, ['da_fonte' => true]));
    }

    /**
     * 💡 **Lo zero al posto della cella vuota resta un'ipotesi che i numeri
     * devono confermare.**
     *
     * CREA lascia la cella vuota dove il valore e' zero o in tracce (i grassi
     * di un succo d'arancia). L'importatore ci mette zero — ma il controllo di
     * coerenza gira lo stesso, quindi uno zero che **non** torna con le calorie
     * dichiarate fa scartare l'alimento comunque.
     */
    #[Test]
    public function a_zero_filled_in_for_a_blank_cell_still_has_to_add_up(): void
    {
        $filtro = new AlimentoAmmissibile;

        // Succo d'arancia: i grassi sono davvero zero, e il conto torna.
        $this->assertNull($filtro->motivoDelRifiuto('Arance succo', [
            'kcal_100' => 33.0, 'protein_100' => 0.5, 'carbs_100' => 8.2, 'fat_100' => 0.0,
        ], ['da_fonte' => true]));

        // Un alimento da 500 kcal con tutti i macro a zero: lo zero non regge.
        $this->assertNotNull($filtro->motivoDelRifiuto('Sospetto', [
            'kcal_100' => 500.0, 'protein_100' => 0.0, 'carbs_100' => 0.0, 'fat_100' => 0.0,
        ], ['da_fonte' => true]));
    }

    /**
     * Alimenti veri, con i numeri veri delle Tabelle CREA.
     *
     * 💡 Servono a provare che il filtro **non sia troppo severo**: un filtro
     * che scarta tutto passa tutti i test negativi e non fa entrare niente.
     */
    #[Test]
    #[DataProvider('alimentiVeri')]
    public function real_foods_from_the_crea_tables_are_accepted(string $nome, float $kcal, float $p, float $c, float $g): void
    {
        $this->assertNull(
            (new AlimentoAmmissibile)->motivoDelRifiuto($nome, [
                'kcal_100' => $kcal, 'protein_100' => $p, 'carbs_100' => $c, 'fat_100' => $g,
            ]),
            "«{$nome}» e' stato scartato e non doveva",
        );
    }

    public static function alimentiVeri(): array
    {
        return [
            'pane bianco' => ['Pane bianco', 268.0, 8.1, 59.5, 0.5],
            'acciuga' => ['Acciuga o alice', 96.0, 16.8, 1.5, 2.6],
            'olio di oliva' => ['Olio di oliva', 899.0, 0.0, 0.0, 99.9],
            'zucchero' => ['Zucchero', 392.0, 0.0, 104.5, 0.0],
            'petto di pollo' => ['Petto di pollo', 100.0, 23.3, 0.0, 0.8],
            'caramelle mou' => ['Caramelle tipo "mou"', 430.0, 2.1, 71.1, 17.2],
        ];
    }
}
