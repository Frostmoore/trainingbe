<?php

declare(strict_types=1);

namespace App\Services\Ai\Guardie;

use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\FoodItem;

/**
 * I macronutrienti dichiarati devono spiegare le calorie dichiarate.
 *
 * ── Cosa fa, in una riga ─────────────────────────────────────────────────
 *
 * Somma i macro con i fattori di Atwater — **proteine 4, carboidrati 4, grassi
 * 9, alcol 7 kcal per grammo** — e confronta il risultato con le calorie che il
 * modello ha scritto. Se i due numeri non si assomigliano, la stima **si
 * contraddice da sola**, e la sua confidenza scende.
 *
 * ── 🚨 Perche' muove la confidenza e NON corregge i numeri ────────────────
 *
 * Quando i due conti non tornano si sa che **uno** dei due e' sbagliato, non
 * quale. Riscrivere le calorie dai macro sarebbe altrettanto arbitrario che
 * riscrivere i macro dalle calorie: nei due casi si salverebbe un numero
 * inventato al posto di uno sbagliato, con la differenza che il primo sembra
 * verificato.
 *
 * Abbassare la confidenza, invece, e' l'unica cosa che si sa per certo: che di
 * quella voce ci si puo' fidare meno. E adesso che c'e' il foglio di conferma,
 * una confidenza bassa **si vede**.
 *
 * ── ⚠️ Le tre ragioni per cui e' una guardia morbida ──────────────────────
 *
 * 1. **La fibra alimentare rende quasi 2 kcal/g, non 4**, ma le tabelle la
 *    contano dentro i carboidrati. Verdure e legumi hanno quindi uno scarto
 *    vero e legittimo — il broccolo crudo sfiora il 20%.
 * 2. **I polioli** (maltitolo, sorbitolo) rendono circa 2,4 kcal/g e stanno
 *    anch'essi fra i carboidrati.
 * 3. **Gli arrotondamenti**: il modello manda numeri interi, e su una porzione
 *    piccola tre arrotondamenti valgono piu' dello scarto che si cerca.
 *
 * 🚨 Per questo la banda neutra e' larga (fino al 25%) e sotto le
 * {@see self::KCAL_MINIME} calorie il controllo **non si fa affatto**: una
 * guardia che punisce gli spinaci insegna solo a non fidarsi della guardia.
 */
final class CoerenzaEnergetica
{
    /**
     * I fattori di Atwater: quante kcal rende un grammo di ciascun macro.
     *
     * ⚠️ Sono gli stessi numeri che il backend usa gia' in `CalorieCalculator`
     * per ripartire i target. **Se cambiano di la' devono cambiare di qua**, o
     * il sistema si contraddirebbe: prescriverebbe una dieta con una tabella e
     * la verificherebbe con un'altra.
     */
    public const KCAL_PER_GRAMMO = [
        'protein' => 4.0,
        'carbs' => 4.0,
        'fat' => 9.0,
        'alcohol' => 7.0,
    ];

    /**
     * Sotto queste calorie il controllo non si fa.
     *
     * 🚨 L'errore **relativo** esplode sul rumore: una voce da 8 kcal con i
     * macro arrotondati all'intero puo' sbagliare del 50% senza che ci sia
     * niente di sbagliato. E una voce da 8 kcal non sposta nessuna dieta.
     */
    private const KCAL_MINIME = 20.0;

    /** Fino a qui i conti tornano: la stima si merita una spinta. */
    private const SOGLIA_COERENTE = 0.10;

    /** Fin qui si tace: e' la banda della fibra, dei polioli e degli arrotondamenti. */
    private const SOGLIA_NEUTRA = 0.25;

    /** Oltre questa non e' piu' un'imprecisione. */
    private const SOGLIA_SOSPETTA = 0.50;

    private const BONUS = 0.05;

    private const PENALITA_SOSPETTA = -0.15;

    private const PENALITA_GRAVE = -0.30;

    /**
     * La stessa stima, con la confidenza corretta da quanto i conti tornano.
     *
     * 🚨 **Si prende la correzione PIU' BASSA fra tutte le voci**, e questo fa
     * due cose insieme: una sola voce incoerente abbassa la confidenza di tutto
     * il pasto, e il bonus arriva solo se **ogni** voce se lo e' meritato. E'
     * la scelta prudente, la stessa di tutto il resto del sistema.
     *
     * ⚠️ Una voce che non si puo' controllare — troppo piccola, o senza macro —
     * vale **zero**: non aiuta e non danneggia. Non poter verificare non e'
     * un'accusa, ma non e' nemmeno un'assoluzione.
     */
    public static function correggi(FoodEstimate $stima): FoodEstimate
    {
        $correzioni = [];

        foreach ($stima->items as $voce) {
            $scarto = self::scartoDi($voce);

            $correzioni[] = $scarto === null ? 0.0 : self::correzionePer($scarto);
        }

        if ($correzioni === []) {
            return $stima;
        }

        $delta = min($correzioni);

        if ($delta === 0.0) {
            return $stima;
        }

        return new FoodEstimate(
            items: $stima->items,
            totals: $stima->totals,
            confidence: self::inScala($stima->confidence + $delta),
            note: $stima->note,
        );
    }

    /**
     * Di quanto le calorie dichiarate si discostano da quelle che i macro
     * spiegano, in proporzione — da 0 (identiche) a quasi 1 (non c'entrano
     * niente).
     *
     * 💡 Il denominatore e' **il maggiore dei due**, non uno dei due: cosi' lo
     * scarto e' simmetrico. Dividere per le calorie dichiarate darebbe un numero
     * enorme quando sono loro a essere troppo basse, e piccolo nel caso opposto —
     * cioe' due metri diversi per lo stesso errore.
     *
     * Restituisce `null` quando non c'e' niente da controllare.
     */
    public static function scartoDi(FoodItem $voce): ?float
    {
        $kcal = $voce->kcal;

        if ($kcal === null || $kcal < self::KCAL_MINIME) {
            return null;
        }

        /*
         * ⚠️ Nessun macro dichiarato non e' un'incoerenza: e' un dato mancante.
         * Punirlo vorrebbe dire abbassare la confidenza di una voce inserita a
         * mano con le sole calorie, che e' un modo perfettamente legittimo di
         * registrare qualcosa.
         */
        if ($voce->protein === null && $voce->carbs === null && $voce->fat === null) {
            return null;
        }

        $attese = self::kcalDaiMacro($voce);

        if ($attese <= 0.0) {
            return null;
        }

        return abs($kcal - $attese) / max($kcal, $attese);
    }

    /** Le calorie che i macronutrienti dichiarati spiegano. */
    public static function kcalDaiMacro(FoodItem $voce): float
    {
        return ($voce->protein ?? 0.0) * self::KCAL_PER_GRAMMO['protein']
            + ($voce->carbs ?? 0.0) * self::KCAL_PER_GRAMMO['carbs']
            + ($voce->fat ?? 0.0) * self::KCAL_PER_GRAMMO['fat']
            + ($voce->alcohol ?? 0.0) * self::KCAL_PER_GRAMMO['alcohol'];
    }

    private static function correzionePer(float $scarto): float
    {
        return match (true) {
            $scarto <= self::SOGLIA_COERENTE => self::BONUS,
            $scarto <= self::SOGLIA_NEUTRA => 0.0,
            $scarto <= self::SOGLIA_SOSPETTA => self::PENALITA_SOSPETTA,
            default => self::PENALITA_GRAVE,
        };
    }

    private static function inScala(float $confidenza): float
    {
        return round(max(0.0, min(1.0, $confidenza)), 2);
    }
}
