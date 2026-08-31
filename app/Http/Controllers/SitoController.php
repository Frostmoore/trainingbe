<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TrainerInvite;
use App\Services\Billing\Listino;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Il sito pubblico — F9 della Parte B.
 *
 * ⚠️ **È l'unica cosa di tutto il piano che non è né app né pannello**: è
 * marketing, prezzi e conformità. Tre pubblici, tre percorsi (F9.1).
 *
 * 🚨 **Nessun prezzo è scritto dentro una vista**: i numeri arrivano da
 * `Listino` (`config/listino.php`). Un prezzo in un template è un prezzo che un
 * giorno dirà una cosa diversa da quella che il sistema fattura — ed è la classe
 * di errore che si scopre da un cliente arrabbiato, non da un test.
 */
class SitoController extends Controller
{
    /**
     * La home.
     *
     * ── 🚨 Non riceve `Listino`, ed e' voluto ─────────────────────────────
     *
     * Perche' **sulla home non c'e' nessun prezzo**. La versione del 16/08
     * mattina spiegava, nel terzo passo di «come funziona», che una palestra
     * puo' rivendere un posto a 10 € e tenersene la meta'.
     *
     * ⚠️ E' vero, ed e' la cosa sbagliata da dire li': chi sta valutando se
     * scaricare un'app legge che e' un prodotto costruito per essere rivenduto
     * addosso a lui, e chiude. Quei numeri stanno su `/prezzi`, che e' la
     * pagina dove una palestra li sta cercando apposta.
     *
     * 💡 Non avere il listino iniettato **e' il modo di renderlo difficile da
     * rimettere**: chi volesse riscriverci un prezzo dovrebbe prima cambiare
     * questa firma, e a quel punto legge il perche'.
     */
    public function home(): View
    {
        return view('sito.home');
    }

    /**
     * La pagina dei prezzi — 16/08/2026.
     *
     * 🚨 **Ogni numero arriva da `Listino`, nessuno e' scritto nella vista.** Un
     * prezzo in un template e' un prezzo che un giorno dira' una cosa diversa
     * da quella che il sistema fattura, e lo si scopre da un cliente arrabbiato
     * invece che da un test.
     */
    public function prezzi(Listino $listino): View
    {
        return view('sito.prezzi', [
            'formatta' => static fn (int $centesimi): string => number_format($centesimi / 100, 2, ',', '.').' €',
            'listino' => $listino,
            'prezzoSingolo' => $listino->singolo(),
            'chiamateMensili' => $listino->chiamateMensili(),
            'pacchetti' => $listino->pacchettiGettoni(),
            'esempio' => $listino->esempio(),

            /*
             * 📣 M5.7 — anche questi due passano dal `Listino`, non dalla vista.
             * Un prezzo scritto in un template è un prezzo che un giorno dirà
             * una cosa diversa da quella che il sistema addebita.
             */
            'costoVisualizzazione' => $listino->costoVisualizzazione() / 100,
            'budgetMinimo' => $listino->budgetMinimoPubblicita() / 100,
        ]);
    }

    /**
     * Un documento legale, reso dal suo sorgente in Markdown.
     *
     * ── 🚨 Perche' il sorgente sta in `resources/legal/` ──────────────────
     *
     * Perche' **il sito e' la pubblicazione**: il testo che una persona legge
     * deve stare nel repository che viene deployato, non in un repo di
     * documentazione che potrebbe divergere senza che nessuno se ne accorga.
     *
     * ⚠️ Reso a ogni richiesta e non precompilato: sono due file di poche
     * decine di kilobyte, e una cache in piu' e' una cosa in piu' che puo'
     * mostrare la versione vecchia di un documento legale.
     *
     * 🚨 `abort(404)` su un nome sconosciuto, e l'elenco e' **chiuso**: senza,
     * `/documento/../../.env` sarebbe una lettura di file arbitraria.
     */
    public function documento(string $quale): View
    {
        $ammessi = [
            'privacy' => 'informativa-privacy',
            'condizioni' => 'condizioni-uso',
        ];

        abort_unless(isset($ammessi[$quale]), 404);

        $percorso = resource_path("legal/{$ammessi[$quale]}.md");

        abort_unless(is_file($percorso), 404);

        $testo = file_get_contents($percorso) ?: '';

        return view('sito.documento', [
            'titolo' => $this->primoTitolo($testo),
            'corpo' => Str::markdown($testo, ['html_input' => 'escape']),
        ]);
    }

    /**
     * Il titolo del documento, preso dalla sua prima riga.
     *
     * 💡 Cosi' il titolo della pagina e quello del testo non possono divergere:
     * chi cambia l'intestazione del documento cambia anche la scheda del
     * browser, senza doverselo ricordare.
     */
    private function primoTitolo(string $markdown): string
    {
        foreach (preg_split('~\R~', $markdown) ?: [] as $riga) {
            if (str_starts_with($riga, '# ')) {
                return trim(substr($riga, 2));
            }
        }

        return 'Documento';
    }

    /**
     * La pagina d'atterraggio di un invito — F6.2.
     *
     * 🚨 **Non riscatta niente.** Il riscatto ha bisogno di nome, email e
     * password, cioè di un modulo: questa pagina dice soltanto se il link è
     * ancora buono e manda all'app. ⚠️ Un `GET` che consumasse l'invito lo
     * brucerebbe al primo servizio che fa l'anteprima del link — quelli di
     * messaggistica lo aprono da soli per mostrare il riquadro.
     *
     * 💡 E il token **non si mostra** nella pagina: viaggia già nell'URL, e
     * ripeterlo nel corpo lo farebbe finire negli screenshot.
     */
    public function invito(string $token): View
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        return view('sito.invito', [
            'valido' => $invito !== null && $invito->eValido(),
            'trainer' => $invito?->trainer?->name,
        ]);
    }

    /**
     * L'invito di una **palestra**, per chi non ha l'app — 3b-V.3.1.
     *
     * 🚨 **Su un telefono con l'app qui non ci arriva nessuno**: Android apre
     * direttamente l'app (App Links). Ci arriva chi tocca il link da un
     * computer, o da un telefono senza l'app — cioe' esattamente la persona che
     * l'invito deve convincere a installarla.
     *
     * 💡 Per questo mostra **le stesse cose** della schermata dell'app — nome,
     * descrizione, cosa ottieni — e non un semplice «scarica l'app»: chi arriva
     * qui non sa ancora se gli interessa.
     */
    public function invitoInPalestra(string $token): View
    {
        $dati = app(InvitiInPalestra::class)->anteprima($token);

        return view('sito.invito-palestra', [
            /*
             * ⛔ **Un solo esito negativo.** Scaduto, revocato, gia' usato,
             * rifiutato e mai esistito danno la stessa pagina: distinguerli
             * permetterebbe di provare token a tappeto e capire quali sono
             * esistiti, e quando. E' la stessa regola dell'API.
             */
            'valido' => $dati !== null,
            'palestra' => $dati['palestra']['name'] ?? null,
            'descrizione' => $dati['descrizione'] ?? null,
            'cosaOttieni' => $dati['cosa_ottieni'] ?? [],

            /*
             * 🔗 **Lo store CON il token attaccato** — V.3.3.
             *
             * 🚨 E' la meta' che fa funzionare il «referrer»: senza questo
             * parametro il Play Store non ha niente da conservare, e il codice
             * nell'app legge sempre vuoto. ⛔ Funzionerebbe benissimo e non
             * servirebbe a niente — nessun errore, nessun segno.
             *
             * ⚠️ Il valore va codificato **due volte**: una perche' e' dentro
             * `referrer=`, e il Play Store lo decodifica una volta lui.
             */
            'store' => $this->storeConIlToken($token),
        ]);
    }

    /**
     * Il link allo store, con il token attaccato — 3b-V.3.3.
     *
     * ── 🚨 Si parte da `app_versione.store.android`, non da una stringa ────
     *
     * ⛔ La prima versione costruiva l'URL a mano da un identificativo di
     * pacchetto. Avrebbe funzionato — il valore di riserva e' quello giusto — e
     * si sarebbe rotta **solo** il giorno in cui qualcuno cambia
     * `APP_STORE_ANDROID`, mandando la gente sulla scheda sbagliata.
     *
     * ⚠️ E' esattamente l'avvertimento scritto sopra quella config: *«le copie
     * gia' installate manderebbero la gente sulla scheda sbagliata, e sarebbero
     * proprio quelle che non si possono aggiornare»*.
     *
     * ── ⚠️ Il separatore si sceglie, non si indovina ───────────────────────
     *
     * L'URL configurato **puo' gia' avere** una query (`?id=…`) o non averla, se
     * un giorno punta altrove. 💡 Un `&` attaccato a un URL senza `?` produce un
     * link che lo store non capisce — e nessuno se ne accorge finche' qualcuno
     * non lo tocca.
     */
    private function storeConIlToken(string $token): string
    {
        $store = (string) config('app_versione.store.android');

        if ($store === '') {
            return '';
        }

        $separatore = str_contains($store, '?') ? '&' : '?';

        /*
         * ⚠️ Il valore va codificato: e' un parametro dentro un parametro, e il
         * Play Store lo decodifica una volta lui prima di passarlo all'app.
         */
        return $store.$separatore.'referrer='.rawurlencode('invito='.$token);
    }
}
