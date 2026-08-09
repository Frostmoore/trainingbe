<?php

declare(strict_types=1);

namespace Tests\Unit\Nutrition;

use App\Services\Nutrition\CalorieCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B5.3 — fabbisogno e macronutrienti.
 *
 * 🚨 Questa e' la parte del sistema in cui uno sbaglio **non da' nessun
 * errore**: produce un numero credibile, l'utente ci costruisce sopra tre mesi
 * di dieta e nessuno se ne accorge. Per questo i valori attesi sono calcolati a
 * mano nei commenti e non ripresi dall'implementazione.
 */
class CalorieCalculatorTest extends TestCase
{
    private CalorieCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new CalorieCalculator;
    }

    // ───────────────────────── indici ─────────────────────────

    #[Test]
    public function it_computes_the_bmi(): void
    {
        // 80 / (1.80 ^ 2) = 24.69 → 24.7
        $this->assertSame(24.7, $this->calc->bmi(80.0, 180.0));
    }

    /** Mifflin-St Jeor, uomo: 10×kg + 6.25×cm − 5×eta + 5 */
    #[Test]
    public function it_computes_the_bmr_for_a_man(): void
    {
        // 10×80 + 6.25×180 − 5×30 + 5 = 800 + 1125 − 150 + 5 = 1780
        $this->assertSame(1780.0, $this->calc->bmr('male', 80.0, 180.0, 30));
    }

    /** Donna: la costante finale e' −161. */
    #[Test]
    public function it_computes_the_bmr_for_a_woman(): void
    {
        // 10×60 + 6.25×165 − 5×30 − 161 = 600 + 1031.25 − 150 − 161 = 1320.25 → 1320.3
        $this->assertSame(1320.3, $this->calc->bmr('female', 60.0, 165.0, 30));
    }

    /**
     * Un sesso non riconosciuto usa la costante femminile.
     *
     * E' la scelta prudente: sottostimare il fabbisogno porta a un deficit piu'
     * piccolo del previsto; sovrastimarlo porta a mangiare piu' del necessario
     * credendo di essere a target.
     */
    #[Test]
    public function an_unknown_sex_uses_the_conservative_constant(): void
    {
        $this->assertSame(
            $this->calc->bmr('female', 70.0, 170.0, 40),
            $this->calc->bmr('altro', 70.0, 170.0, 40),
        );
    }

    #[Test]
    public function it_applies_the_activity_multiplier(): void
    {
        // 1780 × 1.55 = 2759
        $this->assertSame(2759.0, $this->calc->tdee(1780.0, 'moderate'));
        $this->assertSame(1780.0 * 1.9, $this->calc->tdee(1780.0, 'athlete'));
    }

    /** Un livello sconosciuto non esplode: vale «sedentario». */
    #[Test]
    public function an_unknown_activity_falls_back_to_sedentary(): void
    {
        $this->assertSame($this->calc->tdee(2000.0, 'sedentary'), $this->calc->tdee(2000.0, 'divano'));
    }

    // ───────────────────────── obiettivo ─────────────────────────

    #[Test]
    public function it_applies_the_goal_delta(): void
    {
        $this->assertSame(2550, $this->calc->calorieTarget(3000.0, 'lose'));      // −15%
        $this->assertSame(2250, $this->calc->calorieTarget(3000.0, 'cut'));       // −25%
        $this->assertSame(3000, $this->calc->calorieTarget(3000.0, 'maintain'));
        $this->assertSame(3360, $this->calc->calorieTarget(3000.0, 'bulk'));      // +12%
    }

    /**
     * 🚨 Il pavimento a 1.200 kcal non e' negoziabile.
     *
     * Sotto quella soglia un piano alimentare non e' piu' una dieta, e questo
     * sistema non e' un dispositivo medico.
     */
    #[Test]
    public function it_never_goes_below_the_floor(): void
    {
        $this->assertSame(1200, $this->calc->calorieTarget(1400.0, 'cut'));
        $this->assertSame(1200, $this->calc->calorieTarget(800.0, 'cut'));
    }

    // ───────────────────────── macro ─────────────────────────

    #[Test]
    public function it_splits_macros_by_percentage_of_the_target(): void
    {
        $m = $this->calc->macros(2000, 'maintain');

        // 25% proteine = 500 kcal / 4 = 125 g
        // 48% carbo   = 960 kcal / 4 = 240 g
        // 27% grassi  = 540 kcal / 9 =  60 g
        $this->assertSame(['protein_g' => 125, 'carbs_g' => 240, 'fat_g' => 60], $m);
    }

    /**
     * I macro devono ricomporre il target, non un numero vicino.
     *
     * Un errore di somma qui e' invisibile all'utente — vede tre numeri
     * plausibili — ma rende il piano incoerente col suo stesso obiettivo.
     */
    #[Test]
    public function the_macros_add_up_to_the_target(): void
    {
        foreach (array_keys(CalorieCalculator::MACRO_SPLIT) as $obiettivo) {
            foreach ([1400, 2000, 2600, 3200] as $target) {
                $m = $this->calc->macros($target, $obiettivo);

                $ricomposto = $this->calc->kcalFromMacros(
                    (float) $m['protein_g'],
                    (float) $m['carbs_g'],
                    (float) $m['fat_g'],
                );

                $this->assertLessThanOrEqual(
                    12,
                    abs($ricomposto - $target),
                    "Su «{$obiettivo}» a {$target} kcal i macro ne ricompongono {$ricomposto}.",
                );
            }
        }
    }

    /** In deficit le proteine salgono: servono a non perdere massa magra. */
    #[Test]
    public function cutting_raises_the_protein_share(): void
    {
        $mantenimento = $this->calc->macros(2000, 'maintain');
        $definizione = $this->calc->macros(2000, 'cut');

        $this->assertGreaterThan($mantenimento['protein_g'], $definizione['protein_g']);
    }

    /** Ogni ripartizione deve fare 100%, altrimenti il target non torna mai. */
    #[Test]
    public function every_split_adds_up_to_one_hundred_percent(): void
    {
        foreach (CalorieCalculator::MACRO_SPLIT as $obiettivo => $split) {
            $this->assertEqualsWithDelta(
                1.0,
                array_sum($split),
                0.001,
                "La ripartizione di «{$obiettivo}» non fa 100%.",
            );
        }
    }

    /** Ogni obiettivo con un delta deve avere anche una ripartizione. */
    #[Test]
    public function goals_and_splits_cover_the_same_set(): void
    {
        $this->assertSame(
            array_keys(CalorieCalculator::GOAL_DELTA),
            array_keys(CalorieCalculator::MACRO_SPLIT),
        );
    }

    #[Test]
    public function an_unknown_goal_behaves_like_maintain(): void
    {
        $this->assertSame(
            $this->calc->macros(2000, 'maintain'),
            $this->calc->macros(2000, 'obiettivo-che-non-esiste'),
        );
    }
}
