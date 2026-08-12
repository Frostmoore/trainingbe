<?php

declare(strict_types=1);

namespace App\Services\Nutrition;

use InvalidArgumentException;

/**
 * Fabbisogno calorico e ripartizione dei macronutrienti.
 *
 * Logica pura: nessuna dipendenza, nessun accesso al database, nessuna
 * conoscenza di chi la chiama. E' quello che la rende verificabile con test
 * unitari veri — e questa e' la parte del sistema in cui uno sbaglio non da'
 * nessun errore, produce solo un numero credibile e sbagliato per mesi.
 *
 * **Nota di port.** La firma storica di `macros()` portava un `$kg` che non
 * usava piu' nessuno, tenuto per compatibilita'. Qui non c'e': non c'e' niente
 * da retrocompatibilizzare, e un parametro morto in una firma nuova e' un bug
 * che aspetta il primo che lo prende sul serio.
 */
class CalorieCalculator
{
    /**
     * Moltiplicatori del metabolismo basale per livello di attivita'.
     *
     * Sono i valori classici di Harris-Benedict aggiornati; l'ultimo, `athlete`,
     * copre chi si allena due volte al giorno e nella tabella standard non c'e'.
     *
     * @var array<string, float>
     */
    public const ACTIVITY = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
        'athlete' => 1.9,
    ];

    /**
     * Scostamento dal fabbisogno, per obiettivo.
     *
     * Percentuali e non valori fissi: −500 kcal su chi ne consuma 3.000 e' un
     * taglio del 17%, sulla stessa persona a 1.600 e' il 31% ed e' insostenibile.
     * `cut` e' piu' aggressivo di `lose` di proposito: sono due richieste
     * diverse, e appiattirle su una sola faceva si' che chi voleva dimagrire in
     * fretta smettesse di usare l'app.
     *
     * @var array<string, float>
     */
    public const GOAL_DELTA = [
        'lose_fast' => -0.20,
        'lose_slow' => -0.10,
        'maintain' => 0.0,
        'gain_lean' => 0.10,
        'gain_fast' => 0.20,
    ];

    /**
     * ⚠️ **Il vocabolario vecchio, tenuto vivo per i dati gia' salvati.**
     *
     * Fino al 12/08/2026 gli obiettivi erano tre — `lose` (−15%), `maintain`,
     * `bulk` (+12%) — piu' un `cut` (−25%) che il codice conosceva e che
     * **nessuno impostava mai**: il docblock diceva «si imposta dal piano
     * alimentare», e nel piano alimentare non c'era una riga che lo facesse. E'
     * la stessa forma degli altri difetti di questi giorni: una regola scritta e
     * mai eseguita.
     *
     * 🚨 Restano qui e **non** in `GOAL_DELTA` perche' non devono comparire in
     * nessuna tendina: sono solo un ponte per i profili salvati prima della
     * migrazione e per i piani alimentari che li avessero congelati.
     *
     * @var array<string, string>
     */
    public const GOAL_STORICI = [
        'lose' => 'lose_slow',
        'cut' => 'lose_fast',
        'bulk' => 'gain_lean',
    ];

    /**
     * Ripartizione dei macro in percentuale delle calorie, per obiettivo.
     *
     * 🚨 **Percentuali del target e non grammi per chilo, ed e' una scelta.**
     * La formula g/kg e' piu' comune ma su una persona di 120 kg in forte
     * deficit produce piu' proteine di quante ne stiano nel target: il conto non
     * torna e bisogna correggerlo a mano. In percentuale il conto torna sempre.
     *
     * `lose` e `cut` alzano le proteine perche' in deficit servono a limitare la
     * perdita di massa magra — che e' esattamente cio' che l'utente non vuole
     * perdere.
     *
     * @var array<string, array{protein: float, carbs: float, fat: float}>
     */
    public const MACRO_SPLIT = [
        'lose_fast' => ['protein' => 0.38, 'carbs' => 0.32, 'fat' => 0.30],
        'lose_slow' => ['protein' => 0.32, 'carbs' => 0.38, 'fat' => 0.30],
        'maintain' => ['protein' => 0.25, 'carbs' => 0.48, 'fat' => 0.27],
        'gain_lean' => ['protein' => 0.28, 'carbs' => 0.50, 'fat' => 0.22],
        'gain_fast' => ['protein' => 0.25, 'carbs' => 0.52, 'fat' => 0.23],
    ];

    /** Calorie per grammo: le costanti di Atwater. */
    private const KCAL_PER_G = ['protein' => 4.0, 'carbs' => 4.0, 'fat' => 9.0];

    // ───────────────────────── indici ─────────────────────────

    public function bmi(float $kg, float $cm): float
    {
        if ($cm <= 0) {
            throw new InvalidArgumentException('Altezza non valida.');
        }

        $m = $cm / 100;

        return round($kg / ($m * $m), 1);
    }

    /**
     * Metabolismo basale con Mifflin-St Jeor.
     *
     * Preferita a Harris-Benedict perche' e' piu' accurata sulla popolazione
     * generale di oggi, che e' mediamente piu' pesante di quella su cui la
     * seconda era stata tarata nel 1919.
     *
     * @param  string  $sex  `male` | `female` — qualunque altro valore usa la
     *                       costante femminile, che e' la piu' prudente: un
     *                       fabbisogno sottostimato porta a un deficit piu'
     *                       piccolo del previsto, uno sovrastimato a mangiare
     *                       piu' del necessario credendo di essere a target.
     */
    public function bmr(string $sex, float $kg, float $cm, int $age): float
    {
        $base = (10 * $kg) + (6.25 * $cm) - (5 * $age);

        return round($base + (mb_strtolower($sex) === 'male' ? 5 : -161), 1);
    }

    public function tdee(float $bmr, string $activity): float
    {
        $fattore = self::ACTIVITY[mb_strtolower($activity)]
            ?? self::ACTIVITY['sedentary'];

        return round($bmr * $fattore, 1);
    }

    // ───────────────────────── obiettivo ─────────────────────────

    /**
     * Il target calorico giornaliero.
     *
     * Il pavimento a 1.200 kcal non e' negoziabile: sotto quella soglia un piano
     * alimentare non e' piu' una dieta, e questo sistema non e' un dispositivo
     * medico. Un obiettivo sconosciuto vale `maintain`, non un errore: un piano
     * salvato con un valore vecchio deve continuare a funzionare.
     */
    public function calorieTarget(float $tdee, string $goal): int
    {
        $delta = self::GOAL_DELTA[self::normalizzaObiettivo($goal)] ?? 0.0;

        return max(1200, (int) round($tdee * (1 + $delta)));
    }

    /**
     * Traduce un obiettivo del vocabolario vecchio in quello nuovo.
     *
     * 🚨 **Un obiettivo sconosciuto vale `maintain`, non un errore** — ma
     * `maintain` e' anche il valore che si otterrebbe per sbaglio dimenticando
     * una traduzione, ed e' esattamente cosi' che il 12/08/2026 chi aveva
     * scritto «voglio dimagrire» si e' ritrovato un target di mantenimento.
     *
     * ⚠️ Per questo la conversione e' esplicita e in un posto solo: chi aggiunge
     * un obiettivo tocca `GOAL_DELTA`, `MACRO_SPLIT` e `Profile::GOALS`, e i
     * test in `CalorieCalculatorTest` falliscono da soli se ne dimentica uno.
     */
    public static function normalizzaObiettivo(string $goal): string
    {
        $goal = mb_strtolower(trim($goal));

        return self::GOAL_STORICI[$goal] ?? $goal;
    }

    /**
     * I grammi di ciascun macro per un dato target.
     *
     * @return array{protein_g: int, carbs_g: int, fat_g: int}
     */
    public function macros(int $kcal, string $goal): array
    {
        $split = self::MACRO_SPLIT[self::normalizzaObiettivo($goal)] ?? self::MACRO_SPLIT['maintain'];

        return [
            'protein_g' => (int) round($kcal * $split['protein'] / self::KCAL_PER_G['protein']),
            'carbs_g' => (int) round($kcal * $split['carbs'] / self::KCAL_PER_G['carbs']),
            'fat_g' => (int) round($kcal * $split['fat'] / self::KCAL_PER_G['fat']),
        ];
    }

    /**
     * Le calorie che i macro indicati producono davvero.
     *
     * Serve a verificare la coerenza di un piano compilato a mano: se un trainer
     * scrive target 2.000 kcal e macro che ne fanno 2.600, il pannello deve
     * poterglielo dire prima che il piano arrivi all'iscritto.
     */
    public function kcalFromMacros(float $proteinG, float $carbsG, float $fatG): int
    {
        return (int) round(
            $proteinG * self::KCAL_PER_G['protein']
            + $carbsG * self::KCAL_PER_G['carbs']
            + $fatG * self::KCAL_PER_G['fat'],
        );
    }

    /** @return array<string, string> per i menu a tendina */
    public static function activityOptions(): array
    {
        return [
            'sedentary' => 'Sedentario (ufficio, niente sport)',
            'light' => 'Leggero (1-2 allenamenti a settimana)',
            'moderate' => 'Moderato (3-4 allenamenti)',
            'active' => 'Attivo (5-6 allenamenti)',
            'athlete' => 'Atleta (due sedute al giorno)',
        ];
    }

    /** @return array<string, string> */
    public static function goalOptions(): array
    {
        return [
            'lose' => 'Dimagrire',
            'cut' => 'Definizione',
            'maintain' => 'Mantenere',
            'bulk' => 'Aumentare massa',
        ];
    }
}
