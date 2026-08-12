<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Guardie\MealValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I controlli deterministici sulla stima — 13/08/2026.
 *
 * 🚨 **Il principio: fuzzy al modello, deterministico al backend.** Il modello fa
 * l'unica cosa che sa fare meglio di noi — capire che «due spinacine» sono 220 g
 * di petto di pollo impanato. Tutto cio' che si puo' calcolare lo calcola PHP,
 * che le somme non le sbaglia mai.
 */
class MealValidatorTest extends TestCase
{
    private MealValidator $v;

    protected function setUp(): void
    {
        parent::setUp();

        $this->v = new MealValidator;
    }

    /** @param list<array<string, mixed>> $voci */
    private function valida(array $voci, float $confidenza = 0.9): array
    {
        return $this->v->valida(FoodEstimate::fromArray([
            'items' => $voci,
            'confidence' => $confidenza,
        ]));
    }

    // ───────────────────────── i totali ─────────────────────────

    /**
     * 🚨 **I totali li somma PHP, sempre.**
     *
     * Anche quando il modello li mandasse, non si guardano: chiedergli di sommare
     * quattro numeri dopo avergli fatto fare tutta l'inferenza aggiunge un modo
     * di sbagliare e non aggiunge niente.
     */
    #[Test]
    public function the_totals_are_summed_by_php_even_when_the_model_sends_its_own(): void
    {
        $stima = FoodEstimate::fromArray([
            'items' => [
                ['name' => 'Pasta', 'grams' => 100, 'kcal' => 353, 'protein_g' => 11, 'carbs_g' => 71, 'fat_g' => 1.5],
                ['name' => 'Olio', 'grams' => 14, 'kcal' => 126, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 14],
            ],
            // Un totale palesemente sbagliato mandato dal modello: va ignorato.
            'totals' => ['kcal' => 9999, 'protein' => 0, 'carbs' => 0, 'fat' => 0],
            'confidence' => 0.9,
        ]);

        $this->assertSame(479.0, $stima->totals['kcal']);
        $this->assertSame(11.0, $stima->totals['protein']);
        $this->assertSame(15.5, $stima->totals['fat']);
    }

    // ───────────────────────── errori gravi ─────────────────────────

    /**
     * 🚨 Grave e non avviso: se compare «pezzo», il modello ha **ignorato lo
     * schema** — e cio' che ha ignorato una volta puo' averlo ignorato altrove.
     */
    #[Test]
    public function a_forbidden_unit_is_a_hard_failure(): void
    {
        $esito = $this->valida([
            ['name' => 'Cotoletta', 'qty' => 2, 'unit' => 'pezzi', 'grams' => 200, 'kcal' => 330],
        ]);

        $this->assertNotEmpty($esito['gravi']);
        $this->assertStringContainsString('pezzi', $esito['gravi'][0]);
    }

    #[Test]
    public function an_unknown_basis_or_state_is_a_hard_failure(): void
    {
        $esito = $this->valida([
            ['name' => 'x', 'grams' => 100, 'kcal' => 200, 'basis' => 'per_porzione'],
            ['name' => 'y', 'grams' => 100, 'kcal' => 200, 'state' => 'tiepido'],
        ]);

        $this->assertCount(2, $esito['gravi']);
    }

    /** ⚠️ Calorie senza peso: la voce non entra in nessun totale riscalabile. */
    #[Test]
    public function calories_without_a_weight_are_a_hard_failure(): void
    {
        $esito = $this->valida([['name' => 'Un piatto', 'kcal' => 500]]);

        $this->assertNotEmpty($esito['gravi']);
    }

    #[Test]
    public function a_clean_estimate_has_no_violations(): void
    {
        $esito = $this->valida([
            [
                'name' => 'Petto di pollo', 'qty' => 100, 'unit' => 'g', 'grams' => 100,
                'basis' => 'per_100g', 'state' => 'cotto', 'declared' => true,
                'kcal' => 165, 'protein_g' => 31, 'carbs_g' => 0, 'fat_g' => 3.6,
                'alcohol_g' => 0, 'confidence' => 0.95,
            ],
        ]);

        $this->assertSame([], $esito['gravi']);
        $this->assertSame([], $esito['avvisi']);
    }

    // ───────────────────────── la densita' ─────────────────────────

    /**
     * ⚠️ **Si segnala e NON si riscrive.**
     *
     * La specsheet proponeva di ricalcolare i grammi con densita' 1,0 quando il
     * rapporto esce dall'intervallo. Qui no: il **miele pesa 1,42 g/ml** e lo
     * sciroppo d'acero 1,33. Sostituirli con 1,0 introdurrebbe un errore del 30%
     * per «correggere» un valore giusto.
     *
     * 💡 La densita' e' una proprieta' dell'alimento, non una formula: e' fuzzy,
     * e cio' che e' fuzzy non si corregge in automatico.
     */
    #[Test]
    public function an_odd_density_is_flagged_but_the_weight_is_left_alone(): void
    {
        $esito = $this->valida([
            [
                'name' => 'Miele', 'qty' => 20, 'unit' => 'ml', 'grams' => 28.4, 'ml' => 20,
                'basis' => 'per_100g', 'kcal' => 87, 'carbs_g' => 23, 'confidence' => 0.9,
            ],
        ]);

        $this->assertSame([], $esito['gravi']);
        $this->assertNotEmpty($esito['avvisi']);
        $this->assertSame(28.4, $esito['stima']->items[0]->grams, 'Il peso NON va riscritto.');
        $this->assertSame(0.6, $esito['stima']->items[0]->confidence);
    }

    #[Test]
    public function a_normal_density_says_nothing(): void
    {
        // Succo: 500 ml, 525 g, densita' 1,05.
        $esito = $this->valida([
            [
                'name' => 'Succo', 'qty' => 500, 'unit' => 'ml', 'grams' => 525, 'ml' => 500,
                'basis' => 'per_100ml', 'kcal' => 225, 'protein_g' => 3, 'carbs_g' => 50, 'fat_g' => 1,
                'confidence' => 0.8,
            ],
        ]);

        $this->assertSame([], $esito['avvisi']);
    }

    // ───────────────────────── l'alcol, che si corregge ─────────────────────────

    /**
     * 🚨 **L'unico punto in cui la correzione automatica e' legittima**:
     * `ml × (gradi / 100) × 0,789` non e' una stima, e' aritmetica.
     *
     * ⚠️ Il modello la sbaglia davvero. Il 12/08/2026, su 150 ml a 12 gradi, ha
     * risposto **11,84 g** invece di 14,2. Il prompt e' stato corretto, ma un
     * prompt e' una richiesta — questo e' un vincolo.
     */
    #[Test]
    public function the_alcohol_is_recomputed_from_the_declared_strength(): void
    {
        $esito = $this->valida([
            [
                'name' => 'Vino rosso', 'qty' => 150, 'unit' => 'ml', 'grams' => 148, 'ml' => 150,
                'basis' => 'per_100ml', 'kcal' => 120, 'protein_g' => 0.1, 'carbs_g' => 4,
                'fat_g' => 0, 'alcohol_g' => 11.84, 'abv_pct' => 12, 'confidence' => 0.8,
            ],
        ]);

        $voce = $esito['stima']->items[0];

        // 150 × 0,12 × 0,789 = 14,20
        $this->assertSame(14.2, $voce->alcohol);
        $this->assertNotEmpty($esito['avvisi']);

        // 💡 Corretto l'alcol si rifanno anche le calorie: quasi tutte le calorie
        // di una bevanda alcolica stanno li' dentro.
        $this->assertSame(115.8, $voce->kcal);
    }

    /** Uno scarto piccolo non si tocca: sono arrotondamenti. */
    #[Test]
    public function a_small_alcohol_gap_is_left_alone(): void
    {
        $esito = $this->valida([
            [
                'name' => 'Vino', 'qty' => 150, 'unit' => 'ml', 'grams' => 148, 'ml' => 150,
                'kcal' => 125, 'alcohol_g' => 14, 'abv_pct' => 12, 'confidence' => 0.8,
            ],
        ]);

        $this->assertSame(14.0, $esito['stima']->items[0]->alcohol);
        $this->assertSame(125.0, $esito['stima']->items[0]->kcal);
    }

    /** ⚠️ Senza gradazione non c'e' formula: non si inventa niente. */
    #[Test]
    public function without_the_strength_the_alcohol_is_not_touched(): void
    {
        $esito = $this->valida([
            ['name' => 'Amaro', 'qty' => 40, 'unit' => 'ml', 'grams' => 40, 'ml' => 40, 'kcal' => 90, 'alcohol_g' => 3],
        ]);

        $this->assertSame(3.0, $esito['stima']->items[0]->alcohol);
    }

    // ───────────────────────── plausibilita' ─────────────────────────

    /** 🚨 Nessun alimento supera i 9,4 kcal per grammo: e' il grasso puro. */
    #[Test]
    public function more_calories_per_gram_than_pure_fat_is_flagged(): void
    {
        $esito = $this->valida([
            ['name' => 'Qualcosa', 'grams' => 100, 'kcal' => 1200, 'fat_g' => 100, 'confidence' => 0.9],
        ]);

        $this->assertNotEmpty($esito['avvisi']);
        $this->assertSame(0.4, $esito['stima']->items[0]->confidence);
    }

    #[Test]
    public function an_absurd_meal_total_is_flagged(): void
    {
        $esito = $this->valida([
            ['name' => 'Olio', 'grams' => 1000, 'kcal' => 9000, 'fat_g' => 1000, 'confidence' => 0.9],
        ]);

        $this->assertNotEmpty($esito['avvisi']);
    }

    // ───────────────────────── la confidenza del pasto ─────────────────────────

    /**
     * 🚨 **La confidenza del pasto non e' la media.**
     *
     * Un ingrediente che pesa il 70% delle calorie ed e' incerto non puo' dare un
     * pasto «sicuro»: sarebbe la media a mentire, non la voce.
     */
    #[Test]
    public function a_dominant_uncertain_item_drags_the_meal_confidence_down(): void
    {
        $esito = $this->valida([
            ['name' => 'Pasta al ragu', 'grams' => 300, 'kcal' => 700, 'confidence' => 0.45],
            ['name' => 'Mela', 'grams' => 150, 'kcal' => 78, 'confidence' => 0.95],
        ], confidenza: 0.9);

        $this->assertSame(0.45, $esito['stima']->confidence);
    }

    /** ⚠️ Una voce incerta ma **marginale** non trascina giu' niente. */
    #[Test]
    public function a_marginal_uncertain_item_does_not_drag_the_meal_down(): void
    {
        $esito = $this->valida([
            ['name' => 'Petto di pollo', 'grams' => 200, 'kcal' => 330, 'protein_g' => 62, 'fat_g' => 7, 'confidence' => 0.95],
            ['name' => 'Un pizzico di sale', 'grams' => 2, 'kcal' => 0, 'confidence' => 0.2],
        ], confidenza: 0.9);

        $this->assertSame(0.9, $esito['stima']->confidence);
    }

    // ───────────────────────── la base di calcolo ─────────────────────────

    /**
     * 🚨 **La prova del difetto trovato in produzione.**
     *
     * Nel diario del committente, il 13/08/2026: 521 ml di succo, 547 g, 245,9
     * kcal. Le 45 kcal/100 ml applicate ai **grammi** invece che ai millilitri —
     * il 5% di troppo su ogni bevanda, sempre nella stessa direzione.
     *
     * `baseDiCalcolo()` e' la risposta: dice su quale numero vanno letti i valori
     * per 100.
     */
    #[Test]
    public function the_basis_says_which_quantity_the_nutrients_refer_to(): void
    {
        $esito = $this->valida([
            [
                'name' => 'Succo', 'qty' => 500, 'unit' => 'ml', 'grams' => 525, 'ml' => 500,
                'basis' => 'per_100ml', 'kcal' => 225, 'carbs_g' => 50, 'confidence' => 0.8,
            ],
            [
                'name' => 'Pane', 'qty' => 50, 'unit' => 'g', 'grams' => 50, 'ml' => null,
                'basis' => 'per_100g', 'kcal' => 133, 'carbs_g' => 25, 'confidence' => 0.9,
            ],
        ]);

        $this->assertSame(500.0, $esito['stima']->items[0]->baseDiCalcolo(), 'il liquido si legge sui ml');
        $this->assertSame(50.0, $esito['stima']->items[1]->baseDiCalcolo(), 'il solido sui grammi');
        $this->assertTrue($esito['stima']->items[0]->eLiquido());
        $this->assertFalse($esito['stima']->items[1]->eLiquido());
    }

    /**
     * ⚠️ **La nota resta un campo libero.** La specsheet proponeva di
     * restringerla a «assenza di cibo o ambiguita' gravi»: e' il campo che ha
     * spiegato perche' una cotoletta fosse stimata male, mentre la confidenza
     * diceva 0.85.
     */
    #[Test]
    public function the_note_survives_the_validation(): void
    {
        $stima = FoodEstimate::fromArray([
            'items' => [['name' => 'Cotolette', 'grams' => 200, 'kcal' => 330, 'protein_g' => 48, 'fat_g' => 15]],
            'confidence' => 0.85,
            'note' => 'Non e\' specificato se sono impanate.',
        ]);

        $esito = $this->v->valida($stima);

        $this->assertSame('Non e\' specificato se sono impanate.', $esito['stima']->note);
    }
}
