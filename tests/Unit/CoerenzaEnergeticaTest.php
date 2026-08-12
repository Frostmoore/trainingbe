<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\FoodItem;
use App\Services\Ai\Guardie\CoerenzaEnergetica;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La guardia sulla coerenza energetica — 12/08/2026.
 *
 * 🚨 **Cosa prova davvero.** Che una stima che si contraddice da sola — macro che
 * non spiegano le calorie dichiarate — perde confidenza, e che una coerente ne
 * guadagna. **Non** prova che la stima sia giusta: i conti possono tornare
 * benissimo su un alimento interpretato male, ed e' esattamente il caso della
 * cotoletta di pollo (330 kcal, 48 g di proteine, 15 g di grassi: Atwater da'
 * 327, cioe' lo 0,9% di scarto su un'interpretazione sbagliata).
 *
 * ⚠️ E' un asse diverso da `macroImpossibili` lato app: quello guarda la **massa**
 * (i macro non possono pesare piu' dell'alimento), questo guarda l'**energia**.
 */
class CoerenzaEnergeticaTest extends TestCase
{
    private function voce(
        ?float $kcal,
        ?float $protein = null,
        ?float $carbs = null,
        ?float $fat = null,
        ?float $alcohol = null,
    ): FoodItem {
        return new FoodItem(
            name: 'x',
            qty: null,
            unit: null,
            grams: 100.0,
            kcal: $kcal,
            protein: $protein,
            carbs: $carbs,
            fat: $fat,
            alcohol: $alcohol,
        );
    }

    private function stima(array $voci, float $confidenza): FoodEstimate
    {
        return FoodEstimate::fromArray([
            'items' => $voci,
            'confidence' => $confidenza,
        ]);
    }

    // ─────────────────────── il conto ───────────────────────

    #[Test]
    public function it_sums_the_macros_with_the_atwater_factors(): void
    {
        // 10 g di proteine + 20 di carboidrati + 5 di grassi + 10 di alcol
        // = 40 + 80 + 45 + 70 = 235
        $this->assertSame(
            235.0,
            CoerenzaEnergetica::kcalDaiMacro($this->voce(200, 10, 20, 5, 10)),
        );
    }

    /**
     * 💡 Il denominatore e' **il maggiore dei due**, quindi lo scarto e'
     * simmetrico: 100 dichiarate contro 200 attese vale quanto 200 contro 100.
     */
    #[Test]
    public function the_gap_is_symmetric(): void
    {
        // Attese 200 (50 g di carboidrati), dichiarate 100 -> scarto 100/200
        $this->assertEqualsWithDelta(0.5, CoerenzaEnergetica::scartoDi($this->voce(100, carbs: 50)), 0.001);

        // Attese 100 (25 g di carboidrati), dichiarate 200 -> scarto 100/200
        $this->assertEqualsWithDelta(0.5, CoerenzaEnergetica::scartoDi($this->voce(200, carbs: 25)), 0.001);
    }

    // ─────────────────── quando non si controlla ───────────────────

    /**
     * 🚨 Sotto le 20 kcal l'errore **relativo** esplode sul rumore: una voce da
     * 8 kcal con i macro arrotondati all'intero puo' sbagliare del 50% senza che
     * ci sia niente di sbagliato. E una voce da 8 kcal non sposta nessuna dieta.
     */
    #[Test]
    public function tiny_entries_are_not_checked(): void
    {
        $this->assertNull(CoerenzaEnergetica::scartoDi($this->voce(8, protein: 1)));
        $this->assertNull(CoerenzaEnergetica::scartoDi($this->voce(0, protein: 0)));
        $this->assertNull(CoerenzaEnergetica::scartoDi($this->voce(null, protein: 10)));
    }

    /**
     * ⚠️ **Nessun macro dichiarato non e' un'incoerenza: e' un dato mancante.**
     *
     * Punirlo vorrebbe dire abbassare la confidenza di una voce inserita a mano
     * con le sole calorie — che e' un modo perfettamente legittimo di registrare
     * qualcosa.
     */
    #[Test]
    public function an_entry_without_macros_is_not_an_accusation(): void
    {
        $this->assertNull(CoerenzaEnergetica::scartoDi($this->voce(250)));
    }

    // ─────────────────── le bande ───────────────────

    /**
     * I dati veri del committente, presi dallo staging. **Tutti coerenti.**
     *
     * 💡 Vale la pena averli come test: dicono che questa guardia, sulla sua
     * giornata, non avrebbe alzato la mano nemmeno una volta. E' il modo di
     * sapere che non e' rumorosa.
     */
    #[Test]
    public function the_real_entries_are_all_coherent(): void
    {
        $veri = [
            'succo di frutta' => [236, 0.5, 58, 0],
            'salsiccia secca' => [270, 13.5, 1, 23.5],
            'coppiette' => [450, 26, 0, 38],
            'focaccia' => [297, 8, 36, 14],
            'cotolette di pollo' => [330, 48, 0, 15],
            'prosciutto' => [233, 54, 0, 3.75],
        ];

        foreach ($veri as $nome => [$kcal, $p, $c, $f]) {
            $scarto = CoerenzaEnergetica::scartoDi($this->voce($kcal, $p, $c, $f));

            $this->assertNotNull($scarto);
            $this->assertLessThan(
                0.10,
                $scarto,
                sprintf('«%s» risulta incoerente al %.1f%%: la guardia sarebbe rumorosa.', $nome, $scarto * 100),
            );
        }
    }

    /**
     * ⚠️ **La banda neutra esiste per la fibra**, che sta fra i carboidrati nelle
     * tabelle ma rende quasi 2 kcal/g invece di 4. Un broccolo crudo ha uno
     * scarto vero del 20% e non c'e' niente di sbagliato.
     */
    #[Test]
    public function fibre_rich_foods_are_not_punished(): void
    {
        // Broccolo crudo, 100 g: 34 kcal, 2,8 P, 7 C, 0,4 F -> attese 42,8
        $stima = $this->stima(
            [['name' => 'Broccoli', 'kcal' => 34, 'protein' => 2.8, 'carbs' => 7, 'fat' => 0.4]],
            0.8,
        );

        $this->assertSame(0.8, $stima->confidence, 'La fibra non deve costare confidenza.');
    }

    #[Test]
    public function a_coherent_estimate_gains_confidence(): void
    {
        $stima = $this->stima(
            [['name' => 'Petto di pollo', 'kcal' => 165, 'protein' => 31, 'carbs' => 0, 'fat' => 3.6]],
            0.70,
        );

        $this->assertSame(0.75, $stima->confidence);
    }

    #[Test]
    public function an_incoherent_estimate_loses_confidence(): void
    {
        // Dichiarate 400, ma i macro ne spiegano 100: scarto del 75%.
        $stima = $this->stima(
            [['name' => 'Misterioso', 'kcal' => 400, 'protein' => 10, 'carbs' => 15, 'fat' => 0]],
            0.90,
        );

        $this->assertSame(0.60, $stima->confidence);
    }

    /**
     * 🚨 **La confidenza non esce mai dalla scala.** Un bonus su una stima gia'
     * a 1.0 non la porta a 1.05: un numero fuori scala farebbe fallire la
     * validazione e, peggio, romperebbe le soglie dell'app che lo confrontano
     * con 0.85 e 0.6.
     */
    #[Test]
    public function the_confidence_never_leaves_the_scale(): void
    {
        $alta = $this->stima(
            [['name' => 'Petto di pollo', 'kcal' => 165, 'protein' => 31, 'carbs' => 0, 'fat' => 3.6]],
            1.0,
        );

        $this->assertSame(1.0, $alta->confidence);

        $bassa = $this->stima(
            [['name' => 'Misterioso', 'kcal' => 400, 'protein' => 10, 'carbs' => 15, 'fat' => 0]],
            0.10,
        );

        $this->assertSame(0.0, $bassa->confidence);
    }

    // ─────────────────── l'alcol ───────────────────

    /**
     * 🚨 **Senza il campo `alcohol` la guardia punirebbe ogni bicchiere di vino.**
     *
     * Il vino ha circa 4 g di carboidrati e 15 g di alcol per 150 ml: Atwater
     * senza l'alcol prevede 16 kcal contro le 125 vere, cioe' l'87% di scarto su
     * una stima **perfettamente giusta**. E' la ragione per cui il campo esiste
     * nello schema.
     */
    #[Test]
    public function wine_is_only_coherent_once_alcohol_is_counted(): void
    {
        $senzaAlcol = CoerenzaEnergetica::scartoDi($this->voce(125, protein: 0.1, carbs: 4, fat: 0));
        $conAlcol = CoerenzaEnergetica::scartoDi($this->voce(125, protein: 0.1, carbs: 4, fat: 0, alcohol: 15));

        $this->assertGreaterThan(0.5, $senzaAlcol, 'Senza alcol il vino sembra incoerente.');
        $this->assertLessThan(0.10, $conAlcol, 'Con l\'alcol i conti tornano.');
    }

    /**
     * 🚨 **Il caso vero uscito dalla chiamata di verifica dello schema**, e la
     * ragione per cui la banda neutra e' larga.
     *
     * Chiedendo «un bicchiere di vino rosso da 150 ml» il modello ha risposto
     * 120 kcal con **11,84 g** di alcol — sbagliando il conto, perche' 150 ml a
     * 12 gradi fanno 14,2 g. Lo scarto energetico che ne esce e' del **22%**:
     * dentro la banda neutra, quindi la guardia **non** interviene.
     *
     * ⚠️ E' voluto, ed e' il compromesso di tutta questa guardia: la stessa
     * soglia che lascia passare un broccolo al 20% lascia passare anche questo.
     * Stringere per prendere il vino vorrebbe dire punire le verdure ogni volta,
     * e una guardia che grida sugli spinaci insegna solo a ignorarla.
     *
     * 💡 La correzione giusta e' stata **nel prompt**, non nella soglia: la
     * formula adesso e' scritta per esteso, con l'avviso che i gradi vanno
     * divisi per cento.
     */
    #[Test]
    public function the_wine_the_model_got_slightly_wrong_stays_in_the_neutral_band(): void
    {
        $scarto = CoerenzaEnergetica::scartoDi(
            $this->voce(120, protein: 0.3, carbs: 2.25, fat: 0, alcohol: 11.84),
        );

        $this->assertGreaterThan(0.10, $scarto, 'Lo scarto c\'e\' davvero.');
        $this->assertLessThan(0.25, $scarto, 'Ma resta sotto la soglia: la guardia non e\' rumorosa.');

        $stima = $this->stima([
            ['name' => 'Vino rosso', 'kcal' => 120, 'protein' => 0.3, 'carbs' => 2.25, 'fat' => 0, 'alcohol' => 11.84],
        ], 0.92);

        $this->assertSame(0.92, $stima->confidence, 'Nessuna penalita\', e nessun bonus.');
    }

    /**
     * ⚠️ **Con i grammi giusti il vino passa.** 150 ml a 12 gradi fanno 14,2 g
     * di alcol: 99,4 kcal dall'alcol piu' 9 dagli zuccheri fanno 108, contro le
     * ~110 delle tabelle.
     */
    #[Test]
    public function a_correctly_computed_wine_earns_the_bonus(): void
    {
        $stima = $this->stima([
            ['name' => 'Vino rosso', 'kcal' => 110, 'protein' => 0.3, 'carbs' => 2.25, 'fat' => 0, 'alcohol' => 14.2],
        ], 0.80);

        $this->assertSame(0.85, $stima->confidence);
    }

    // ─────────────────── piu' voci insieme ───────────────────

    /**
     * 🚨 **Si prende la correzione piu' bassa fra tutte le voci**, e questo fa
     * due cose insieme: una sola voce incoerente abbassa la confidenza di tutto
     * il pasto, e il bonus arriva solo se **ogni** voce se lo e' meritato.
     */
    #[Test]
    public function one_bad_entry_drags_the_whole_meal_down(): void
    {
        $stima = $this->stima([
            ['name' => 'Petto di pollo', 'kcal' => 165, 'protein' => 31, 'carbs' => 0, 'fat' => 3.6],
            ['name' => 'Misterioso', 'kcal' => 400, 'protein' => 10, 'carbs' => 15, 'fat' => 0],
        ], 0.90);

        $this->assertSame(0.60, $stima->confidence);
    }

    /**
     * ⚠️ Una voce che non si puo' controllare vale **zero**: non aiuta e non
     * danneggia. Non poter verificare non e' un'accusa, ma non e' nemmeno
     * un'assoluzione — quindi il bonus non arriva.
     */
    #[Test]
    public function an_unverifiable_entry_cancels_the_bonus_without_penalising(): void
    {
        $stima = $this->stima([
            ['name' => 'Petto di pollo', 'kcal' => 165, 'protein' => 31, 'carbs' => 0, 'fat' => 3.6],
            ['name' => 'Caffe\'', 'kcal' => 2],
        ], 0.80);

        $this->assertSame(0.80, $stima->confidence);
    }

    #[Test]
    public function an_empty_estimate_is_left_alone(): void
    {
        $this->assertSame(0.0, $this->stima([], 0.0)->confidence);
        $this->assertSame(0.42, $this->stima([], 0.42)->confidence);
    }
}
