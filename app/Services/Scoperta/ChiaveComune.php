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
         * 🆕 🚨 **I segni combinanti si tolgono** — 23/09/2026, F6.1.
         *
         * ⛔ `mb_strtolower('İzmir')` non dà `izmir`: dà `i` più un **puntino
         * combinante** (U+0307), un carattere a sé. La riga qui sotto, che
         * trasforma in spazio tutto ciò che non è lettera, ne faceva `i zmir` —
         * e chi cercava «izmir» non trovava la terza città della Turchia.
         *
         * 💡 `\p{Mn}` è la categoria Unicode dei segni **che non occupano
         * spazio**: toglierli è anche la rete di sicurezza per un nome arrivato
         * in forma decomposta (`e` + accento invece di `é`), che la tabella di
         * `senzaAccenti()` non riconoscerebbe.
         */
        $t = preg_replace('~\p{Mn}+~u', '', $t) ?? $t;

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
     *
     * ══ 🆕 🌍 E ADESSO TUTTO L'ALFABETO LATINO — 23/09/2026, F6.1 ═════════
     *
     * 🚨 **La tabella conosceva solo le nostre lettere**, ed era giusto finché
     * i comuni erano italiani. ⛔ Da mondiale, `Łódź` diventava `łodź`: chi
     * digitava «lodz» — cioè chiunque, perché sulla tastiera italiana la `ł`
     * non c'è — non trovava una città da settecentomila abitanti.
     *
     * ✅ Adesso ci sono le lettere di Polonia, Repubblica Ceca, Slovacchia,
     * Croazia, Slovenia, Ungheria, Romania, Turchia, paesi baltici e nordici, e
     * le vocali del vietnamita. 💡 Sono le lingue dei paesi da cui arrivano i
     * nomi **locali** di `mondo.jsonl.gz` (`Warszawa`, `Kraków`, `Brașov`).
     *
     * ⚠️ **Resta scritta a mano**, e il perché è quello di sopra: `iconv` e
     * perfino `intl` dipendono da cosa c'è installato sul server. ⛔ Due
     * macchine con due tabelle diverse producono due chiavi per la stessa città,
     * e la ricerca smette di trovare le righe scritte dall'altra.
     *
     * 💡 Le lettere non latine (cirillico, greco, arabo, cinese) **restano come
     * sono**: non c'è una traslitterazione giusta sola, e il nome GeoNames di
     * quelle città è già in caratteri latini (`Moscow`, `Beijing`).
     */
    private function senzaAccenti(string $testo): string
    {
        return strtr($testo, self::LETTERE);
    }

    /**
     * La tabella delle lettere, **minuscole** — `da()` abbassa prima di
     * chiamarla.
     *
     * @var array<string, string>
     */
    private const LETTERE = [
        // ── Le nostre, e quelle di sempre ─────────────────────────────────
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',

        // ── 🆕 Europa centrale e orientale ────────────────────────────────
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'č' => 'c', 'ď' => 'd', 'ě' => 'e', 'ň' => 'n', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ů' => 'u', 'ž' => 'z',
        'ĺ' => 'l', 'ľ' => 'l', 'ŕ' => 'r',
        'đ' => 'd',
        'ő' => 'o', 'ű' => 'u',
        'ă' => 'a', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',

        // ── 🆕 Turchia ─────────────────────────────────────────────────────
        'ğ' => 'g', 'ı' => 'i',

        // ── 🆕 Baltici e nordici ───────────────────────────────────────────
        'ā' => 'a', 'ē' => 'e', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u',
        'ģ' => 'g', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n', 'ė' => 'e', 'į' => 'i', 'ų' => 'u',
        'ð' => 'd', 'þ' => 'th',

        // ── 🆕 Vietnam, le più comuni ──────────────────────────────────────
        'ạ' => 'a', 'ả' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
        'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
        'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
        'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
        'ọ' => 'o', 'ỏ' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
        'ơ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
        'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
        'ỳ' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
    ];
}
