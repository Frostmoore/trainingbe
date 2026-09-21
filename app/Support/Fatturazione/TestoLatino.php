<?php

declare(strict_types=1);

namespace App\Support\Fatturazione;

/**
 * ✍️ Un testo che la FatturaPA accetta — fase F8, 23/09/2026.
 *
 * 🚨 **Lo schema ammette solo Latin-1** (`[\p{IsBasicLatin}\p{IsLatin-1Supplement}]`)
 * nei campi di testo: niente «—», «€», «×», virgolette curve, emoji. ⛔ Un solo
 * carattere fuori posto e l'intero file è **scartato**, non corretto. E sono
 * proprio i caratteri che scriviamo dappertutto nei nomi dei piani e dei
 * pacchetti.
 *
 * 💡 Le lettere accentate italiane **stanno** in Latin-1 (à è é ì ò ù): restano
 * come sono. Si sostituisce solo quello che non c'è, con l'equivalente più
 * vicino, e si toglie ciò che non ha un equivalente.
 */
final class TestoLatino
{
    /** @var array<string, string> */
    private const SOSTITUZIONI = [
        '—' => '-', '–' => '-', '‐' => '-', '−' => '-',
        '“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"',
        '‘' => "'", '’' => "'", '‚' => "'",
        '…' => '...', '×' => 'x', '€' => 'EUR', '·' => '-', '•' => '-',
        "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ',
    ];

    /**
     * Il testo pulito, tagliato a `$massimo` caratteri.
     *
     * ⚠️ `normalizedString`: niente a capo né tabulazioni, e gli spazi doppi
     * diventano uno — un a capo in una descrizione è un errore di formato.
     */
    public static function di(?string $testo, int $massimo): string
    {
        $testo = strtr((string) $testo, self::SOSTITUZIONI);
        $testo = (string) preg_replace('/\s+/u', ' ', $testo);

        // Ogni carattere rimasto fuori da Latin-1 (emoji, alfabeti non latini) si toglie.
        $testo = (string) preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '', $testo);

        $testo = trim($testo);

        return mb_substr($testo, 0, $massimo);
    }

    /** Solo lettere maiuscole e cifre: codici fiscali, CAP, codici SDI. */
    public static function codice(?string $valore): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $valore));
    }
}
