<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

/**
 * Da «Sant'Angelo in Vado» alla chiave `sant angelo in vado` — 18/08/2026.
 *
 * ── 🚨 Perche' non riusa `ChiaveAlimento` ──────────────────────────────────
 *
 * Sembrano lo stesso problema — normalizzare un nome scritto a mano — e non lo
 * sono. `ChiaveAlimento` **toglie le parole di riempimento** (`di`, `la`, `al`,
 * `con`), e per un alimento e' la cosa giusta: «pasta al pomodoro» e «pasta con
 * pomodoro» sono la stessa cosa per chi la mangia, e devono cadere sulla stessa
 * riga.
 *
 * ⚠️ Per un comune sarebbe un disastro, e in due modi:
 *
 * 1. **`La Spezia` diventerebbe `spezia`**, `Il Cairo` diventerebbe `cairo`, e
 *    `La Valletta Brianza` diventerebbe `valletta brianza`. Chi digita «la sp»
 *    normalizzato darebbe «sp», che non e' prefisso di «spezia»: la ricerca a
 *    prefisso — l'unica che usi l'indice — smetterebbe di trovare la citta'
 *    proprio mentre la si sta scrivendo.
 * 2. **Il nome di un comune e' ufficiale.** Non esistono due modi legittimi di
 *    scriverlo, quindi non c'e' niente da far collidere: la tolleranza che serve
 *    a un alimento qui e' solo un modo di perdere informazione.
 *
 * 💡 Quindi qui si normalizza **la forma**, non le parole: maiuscole, accenti,
 * apostrofi, spazi doppi. Nient'altro.
 *
 * 🚨 **La stessa normalizzazione deve valere in scrittura e in lettura.** Due
 * normalizzazioni diverse fra l'import e la ricerca sono il modo classico di
 * avere un indice che non trova mai niente — lezione gia' pagata sul catalogo
 * degli alimenti.
 */
class ChiaveComune
{
    /**
     * La chiave con cui un comune si cerca e si confronta.
     *
     * Torna stringa vuota se non resta niente: chi la usa deve trattarlo come
     * «nessuna ricerca», non come «cerca il vuoto».
     */
    public function da(string $nome): string
    {
        $t = mb_strtolower(trim($nome));

        $t = $this->senzaAccenti($t);

        /*
         * ⚠️ L'apostrofo diventa **spazio**, non niente.
         *
         * `Sant'Angelo` deve dare `sant angelo`, non `santangelo`: e' cosi' che
         * lo scrive chi lo digita in fretta sulla tastiera del telefono, dove
         * l'apostrofo e' in seconda pagina. 💡 E per la ricerca a prefisso e'
         * indifferente: chi scrive `sant'ang` e chi scrive `sant ang` cadono
         * sulla stessa forma.
         */
        $t = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $t) ?? $t;

        return trim(preg_replace('~\s+~u', ' ', $t) ?? $t);
    }

    /**
     * La forma su cui si cerca. E' la stessa di `da()`, e questo metodo esiste
     * per dirlo.
     *
     * 💡 Non e' un doppione inutile: rende **impossibile** che qualcuno cerchi
     * con una normalizzazione e scriva con un'altra, perche' non c'e' una
     * seconda funzione da scrivere per sbaglio. Il giorno che le due dovessero
     * divergere, divergeranno qui e in modo visibile.
     */
    public function perCercare(string $testo): string
    {
        return $this->da($testo);
    }

    /**
     * 💡 A mano e non con `iconv`: `iconv('UTF-8', 'ASCII//TRANSLIT')` dipende
     * dalla localizzazione del sistema e sullo stesso codice da' risultati
     * diversi fra la macchina di sviluppo e il server — cioe' chiavi diverse per
     * lo stesso comune, che e' esattamente il difetto che questa classe esiste
     * per evitare.
     *
     * 🚨 Ci sono anche le tedesche (`ö`, `ü`, `ß`) e le francesi (`ô`, `ê`): i
     * comuni bilingui di Alto Adige e Valle d'Aosta hanno nomi che le usano, e
     * senza queste righe `Gröden` e `groden` sarebbero due cose diverse.
     */
    private function senzaAccenti(string $testo): string
    {
        return strtr($testo, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',
        ]);
    }
}
