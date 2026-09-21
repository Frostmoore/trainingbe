<?php

declare(strict_types=1);

namespace App\Support\Training;

/**
 * 📋 Da «5 × 8» a cinque righe da 8 — 23/09/2026.
 *
 * 📌 Il committente: *«Se una scheda dice ad esempio hack squat 5x8 non deve
 * diventare "serie 5" "ripetizioni 8" ma deve aggiungere 5 righe di serie con 8
 * ripetizioni … mi trovo con "serie 5" "ripetizioni 6-10" che non significa
 * niente … Deve essere una riga per serie e il numero di ripetizioni giusto»*.
 *
 * 💡 Il modello adesso risponde **già** riga per riga (`Prompts::PDF_SYSTEM`,
 * regola 3). Questa classe serve a tutto il resto che parla ancora «serie ×
 * ripetizioni»: le bozze salvate prima del cambio, una risposta del modello
 * senza righe, un test. ⛔ Da qui non esce mai una riga con «6-10» dentro: le
 * ripetizioni di una riga sono **un numero**, o niente.
 *
 * | Scritto | Righe |
 * |---|---|
 * | `5` × `8` | 8 · 8 · 8 · 8 · 8 |
 * | `5` × `12-10-8-6-4` | 12 · 10 · 8 · 6 · 4 (una per serie) |
 * | `12-10-8` (senza serie) | 12 · 10 · 8 |
 * | `4` × `8-12` | 8 · 8 · 8 · 8 — ⚠️ un **intervallo** vale il numero più basso, come fa già l'app |
 * | `3` × `10+10` | 10 · 10 · 10 (per lato) |
 * | `3` × `45 secondi` / `45"` | isometria 45 s |
 * | `1` × `10 min` | isometria 600 s |
 * | `3` × `max` / `cedimento` | ripetizioni vuote |
 */
final class SerieDallaPrescrizione
{
    /** Oltre questo numero di serie non è una scheda, è un errore di lettura. */
    public const MASSIMO = 30;

    /**
     * @return list<array{reps: ?int, weight: ?float, iso_sec: ?int, rest_sec: ?int}>
     */
    public static function espandi(?int $serie, ?string $ripetizioni, ?int $recuperoSec = null, ?float $peso = null): array
    {
        $testo = mb_strtolower(trim((string) $ripetizioni));
        $iso = self::secondi($testo);
        $numeri = $iso === null ? self::numeri($testo) : [];

        /*
         * 🚨 **Una piramide si riconosce, un intervallo no.** «12-10-8» con tre
         * serie è una ripetizione per serie; «8-12» è un intervallo, e si scrive
         * crescente. 💡 Quindi: tanti numeri quante le serie **e** (più di due,
         * oppure decrescenti) → uno per serie.
         */
        $piramide = count($numeri) >= 2
            && ($serie === null || $serie === count($numeri))
            && (count($numeri) > 2 || $numeri[0] > $numeri[1]);

        if ($piramide) {
            $perSerie = $numeri;
        } else {
            $quante = max(1, min(self::MASSIMO, $serie ?? 1));
            $una = $numeri === [] ? null : min($numeri);
            $perSerie = array_fill(0, $quante, $una);
        }

        return array_values(array_map(
            static fn (?int $reps): array => [
                'reps' => $reps,
                'weight' => $peso,
                'iso_sec' => $iso,
                'rest_sec' => $recuperoSec,
            ],
            array_slice($perSerie, 0, self::MASSIMO),
        ));
    }

    /**
     * Le ripetizioni come numeri. ⚠️ «10+10» (per lato) è **un** 10, non due.
     *
     * @return list<int>
     */
    private static function numeri(string $testo): array
    {
        if ($testo === '' || str_contains($testo, 'max') || str_contains($testo, 'ceden')) {
            return [];
        }

        // ⚠️ Quello fra parentesi è un commento — il tempo «(2.0.2)», «(per lato)» —,
        // non ripetizioni: «10-12 (2.0.2)» dava 0.
        $testo = (string) preg_replace('/\([^)]*\)/', ' ', $testo);
        $testo = (string) preg_replace('/(\d+)\s*\+\s*\d+/', '$1', $testo);

        preg_match_all('/\d+/', $testo, $trovati);

        return array_values(array_map('intval', $trovati[0]));
    }

    /** «45 sec», «45"», «40s», «1'», «10 min», «1'30» → secondi; altrimenti `null`. */
    private static function secondi(string $testo): ?int
    {
        if (preg_match("/^(\\d+)\\s*'\\s*(\\d+)/u", $testo, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        if (preg_match("/^(\\d+)\\s*(min|minuti|minuto|')/u", $testo, $m) === 1) {
            return (int) $m[1] * 60;
        }

        if (preg_match('/^(\d+)\s*(s|sec|secondi|secondo|")\b/u', $testo.' ', $m) === 1
            || preg_match('/^(\d+)\s*"/u', $testo, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
