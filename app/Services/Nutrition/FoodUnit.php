<?php

declare(strict_types=1);

namespace App\Services\Nutrition;

/**
 * La conversione delle unita' di misura in grammi.
 *
 * 🚨 **E' il fallback deterministico, non la verita' nutrizionale.** Un
 * cucchiaio d'olio pesa 14 g, uno di miele 21, uno di farina 8: la tabella qui
 * sotto ne conosce uno solo, 15, ed e' giusto cosi' perche' serve a chi inserisce
 * a mano senza sapere il peso. Quando c'e' di mezzo l'AI (B6) i grammi li
 * decide il modello, che sa di che alimento si parla — e la sua risposta vince
 * su questa tabella.
 *
 * Tutti i metodi sono statici e senza stato: e' logica pura, quindi si prova con
 * test unitari veri e non serve nessun database.
 */
final class FoodUnit
{
    /**
     * Quanti grammi (o millilitri, trattati 1:1) vale un'unita'.
     *
     * Il rapporto 1:1 fra ml e g e' un'approssimazione consapevole: vale per
     * l'acqua, sbaglia del 10% sull'olio. Distinguere volume e massa
     * richiederebbe la densita' di ogni alimento, cioe' un catalogo che qui non
     * c'e' — e nel contesto d'uso (un diario alimentare) l'errore e' sotto il
     * rumore dell'inserimento a occhio.
     *
     * @var array<string, float>
     */
    public const FACTORS = [
        'g' => 1.0,
        'mg' => 0.001,
        'hg' => 100.0,
        'kg' => 1000.0,
        'ml' => 1.0,
        'cl' => 10.0,
        'dl' => 100.0,
        'l' => 1000.0,
        'bicchiere' => 200.0,
        'cucchiaio' => 15.0,
        'cucchiaino' => 5.0,
        'tazza' => 240.0,
        'scoop' => 30.0,
    ];

    /**
     * L'ordine in cui le unita' compaiono in un menu a tendina.
     *
     * Non alfabetico: prima quelle che si usano davvero. Un elenco alfabetico
     * mette «bicchiere» prima di «g», e l'unita' piu' usata finisce in mezzo.
     *
     * @var list<string>
     */
    public const ORDER = [
        'g', 'kg', 'ml', 'l', 'cucchiaio', 'cucchiaino', 'bicchiere', 'tazza', 'scoop',
        'hg', 'dl', 'cl', 'mg',
    ];

    /** Sinonimi e abbreviazioni che arrivano dall'inserimento libero e dall'AI. */
    private const ALIASES = [
        'grammi' => 'g',
        'grammo' => 'g',
        'gr' => 'g',
        'millilitri' => 'ml',
        'litro' => 'l',
        'litri' => 'l',
        'chilogrammi' => 'kg',
        'chili' => 'kg',
        'etto' => 'hg',
        'etti' => 'hg',
        'cucchiai' => 'cucchiaio',
        'cucchiaini' => 'cucchiaino',
        'bicchieri' => 'bicchiere',
        'tazze' => 'tazza',
        'scoops' => 'scoop',
        'misurino' => 'scoop',
        'tbsp' => 'cucchiaio',
        'tsp' => 'cucchiaino',
        'cup' => 'tazza',
    ];

    /**
     * L'unita' normalizzata, o `null` se non la conosciamo.
     *
     * Restituire `null` invece di indovinare e' voluto: un'unita' sconosciuta
     * fatta passare per grammi produce un numero plausibile e sbagliato, che
     * nessuno controllera' piu'.
     */
    public static function valid(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $u = mb_strtolower(trim($unit));
        $u = rtrim($u, '.');

        $u = self::ALIASES[$u] ?? $u;

        return isset(self::FACTORS[$u]) ? $u : null;
    }

    /**
     * Quantita' × unita' → grammi.
     *
     * `null` se manca uno dei due o se l'unita' non e' riconosciuta: il
     * chiamante decide cosa farne, ma non riceve mai un numero inventato.
     */
    public static function toGrams(?float $qty, ?string $unit): ?float
    {
        if ($qty === null) {
            return null;
        }

        $u = self::valid($unit);

        if ($u === null) {
            return null;
        }

        return round($qty * self::FACTORS[$u], 2);
    }

    /** Grammi → quantita' nell'unita' indicata. L'inverso di `toGrams()`. */
    public static function fromGrams(?float $grams, ?string $unit): ?float
    {
        if ($grams === null) {
            return null;
        }

        $u = self::valid($unit);

        if ($u === null || self::FACTORS[$u] === 0.0) {
            return null;
        }

        return round($grams / self::FACTORS[$u], 2);
    }

    /** @return array<string, string> per i menu a tendina, nell'ordine d'uso */
    public static function options(): array
    {
        $out = [];

        foreach (self::ORDER as $u) {
            $out[$u] = $u;
        }

        return $out;
    }
}
