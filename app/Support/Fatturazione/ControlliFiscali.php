<?php

declare(strict_types=1);

namespace App\Support\Fatturazione;

/**
 * 🧾 **I controlli sui dati fiscali** — 23/09/2026, fasi F6.4 e F8.
 *
 * ══ 🚨 PERCHÉ CONTROLLARLI QUI E NON LASCIARLO ALLO SDI ══════════════════
 *
 * ⛔ Perché noi **non trasmettiamo allo SDI** (✅ D2: *«Basta che mi generi
 * l'xml e il pdf»*). La fattura la scarica il committente e la carica lui: una
 * partita IVA sbagliata la scopre **lì**, settimane dopo, con uno scarto che
 * nessuno sa più ricondurre a un modulo compilato male.
 *
 * 💡 Una cifra di controllo sbagliata vuol dire quasi sempre **una cifra
 * battuta male**: fermarla mentre la persona sta ancora scrivendo costa un
 * messaggio, fermarla dopo costa una nota di credito.
 *
 * ⚠️ **Solo per l'Italia** la P.IVA ha una cifra di controllo che sappiamo
 * verificare. Per gli altri paesi si controlla la forma e basta: ⛔ rifiutare una
 * partita IVA tedesca perché non passa l'algoritmo italiano sarebbe peggio che
 * non controllarla.
 */
final class ControlliFiscali
{
    /**
     * Una partita IVA italiana valida: 11 cifre, **con la cifra di controllo
     * giusta**.
     *
     * 📐 L'algoritmo del Ministero: le cifre in posizione dispari si sommano
     * così come sono; quelle in posizione pari si raddoppiano, e se il doppio
     * supera 9 si toglie 9. L'undicesima cifra è quella che porta la somma al
     * multiplo di dieci successivo.
     */
    public static function partitaIvaItaliana(string $piva): bool
    {
        $piva = self::pulisci($piva);

        if (! preg_match('/^\d{11}$/', $piva)) {
            return false;
        }

        /*
         * ⛔ **Undici zeri passano la formula** (somma zero, controllo zero) e
         * non sono una partita IVA: è il valore che si scrive per «non ce
         * l'ho». 💡 Rifiutato qui, prima che finisca in una fattura.
         */
        if ($piva === '00000000000') {
            return false;
        }

        $somma = 0;

        for ($i = 0; $i < 10; $i++) {
            $cifra = (int) $piva[$i];

            if ($i % 2 === 1) {
                $cifra *= 2;
                $cifra = $cifra > 9 ? $cifra - 9 : $cifra;
            }

            $somma += $cifra;
        }

        return (10 - ($somma % 10)) % 10 === (int) $piva[10];
    }

    /**
     * La partita IVA, **del paese giusto**.
     *
     * ⚠️ Fuori dall'Italia: da 2 a 14 caratteri alfanumerici. 💡 È la forma
     * più larga che ammette il tracciato FatturaPA (`IdCodice`, 28 caratteri)
     * restando abbastanza stretta da fermare un'email incollata nel campo
     * sbagliato.
     */
    public static function partitaIva(string $piva, string $nazione = 'IT'): bool
    {
        if (strtoupper($nazione) === 'IT') {
            return self::partitaIvaItaliana($piva);
        }

        return (bool) preg_match('/^[A-Z0-9]{2,14}$/', self::pulisci($piva));
    }

    /**
     * Il codice fiscale: **11 cifre** (un'impresa) oppure **16 caratteri** (una
     * persona — la ditta individuale).
     *
     * ⚠️ Sulle 16 lettere non si verifica il carattere di controllo: è
     * l'Agenzia delle Entrate che assegna i codici di omocodia, e un algoritmo
     * scritto qui rischierebbe di rifiutarne uno vero.
     */
    public static function codiceFiscale(string $cf): bool
    {
        $cf = self::pulisci($cf);

        return (bool) preg_match('/^(\d{11}|[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z][A-Z0-9]{3}[A-Z])$/', $cf);
    }

    /**
     * Il codice destinatario SDI: **7 caratteri alfanumerici**.
     *
     * 💡 `0000000` è valido, e vuol dire «non ho un codice: mandatela alla
     * PEC». Chi lo usa **deve** dare la PEC — il controllo sta nel modulo, dove
     * si vedono tutti e due i campi insieme.
     */
    public static function codiceSdi(string $codice): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{7}$/', self::pulisci($codice));
    }

    /**
     * Toglie spazi e punti, e mette in maiuscolo.
     *
     * 💡 È il modo in cui la gente li scrive — `IT 123.456.789.01`, «ab12cd3» —
     * e rifiutarli per quello sarebbe pignoleria che non protegge niente.
     */
    public static function pulisci(string $valore): string
    {
        $valore = strtoupper(preg_replace('/[\s.\-]/', '', $valore) ?? $valore);

        /*
         * ⚠️ Il prefisso `IT` si toglie: nella FatturaPA il paese sta in un
         * campo suo (`IdPaese`), e `ITIT01234567890` sarebbe un codice che lo
         * SDI rifiuta.
         */
        return preg_replace('/^IT(?=\d{11}$)/', '', $valore) ?? $valore;
    }
}
