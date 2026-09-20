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
     * ── 🔄 Adesso riceve `Listino`, e fino al 19/09 era vietato ────────────
     *
     * Dal 16/08 questo metodo **non riceveva il listino apposta**: la home di
     * quel mattino spiegava, nel terzo passo di «come funziona», che una
     * palestra può rivendere un posto e tenersi la metà della differenza. ⚠️ È
     * vero, ed era la cosa sbagliata da dire lì — chi sta valutando se
     * scaricare un'app leggeva di essere la merce.
     *
     * 🎯 **Il 20/09 il committente ha chiesto il contrario**: *«deve mettere
     * bene in mostra tutte le funzionalità dell'app spiegando il piano gratuito
     * e quello su abbonamento in un punto specifico della pagina … spingiamo
     * comunque gli utenti ad abbonarsi, è così che guadagno»*.
     *
     * 💡 **Le due cose non si contraddicono**, e la differenza è chi legge:
     * «il Plus costa 8 € al mese» è un'informazione che serve a chi la paga;
     * «rivendilo a 8 e tieniti il resto» è un discorso fra noi e chi compra, e
     * quello resta fuori da qui. 🚨 Il test
     * `the_home_does_not_pitch_the_reseller_margin` continua a vietare il
     * secondo, parola per parola.
     */
    public function home(Listino $listino): View
    {
        return view('sito.home', [
            'formatta' => $this->formattatore(),
            'prezzoSingolo' => $listino->singolo(),

            /*
             * 💡 «Da X al mese»: si prende il **primo piano** di ogni platea,
             * non un numero scritto a mano. Il giorno che nasce un piano più
             * piccolo, la frase lo segue da sola.
             */
            'daTrainer' => $listino->piani('trainer')[0]['prezzo'],
            'daPalestra' => $listino->piani('palestre')[0]['prezzo'],
        ]);
    }

    /**
     * Per palestre e trainer — 20/09/2026.
     *
     * 💡 Riceve il listino perché la pagina cita **due numeri** (il piano più
     * piccolo di ognuno) e non devono essere scritti a mano: un prezzo copiato
     * è un prezzo che un giorno sarà diverso da quello vero.
     */
    public function professionisti(Listino $listino): View
    {
        return view('sito.professionisti', [
            'formatta' => $this->formattatore(),
            'listino' => $listino,
            'daTrainer' => $listino->piani('trainer')[0]['prezzo'],
            'daPalestra' => $listino->piani('palestre')[0]['prezzo'],
        ]);
    }

    /**
     * Lo smistamento dei prezzi — 20/09/2026.
     *
     * ── 📌 Perché tre pagine e non una ────────────────────────────────────
     *
     * Il committente: *«Prezzi deve essere un menù a tendina con Individuale,
     * trainer e palestra con tre pagine diverse»*.
     *
     * 💡 Ed è come lo fanno tutti: le tre platee comprano **cose diverse con
     * unità diverse** — una persona compra un abbonamento, un trainer compra
     * posti per i suoi allievi, una palestra compra posti e istruttori.
     * Metterle sulla stessa pagina obbliga ognuna a scorrere oltre i prezzi
     * degli altri due.
     *
     * ⚠️ `/prezzi` resta e **non muore**: chi ci arriva da un link vecchio o da
     * un motore di ricerca trova lo smistamento, non un 404.
     */
    public function prezzi(Listino $listino): View
    {
        return view('sito.prezzi', [
            'formatta' => $this->formattatore(),
            'prezzoSingolo' => $listino->singolo(),
            'daTrainer' => $listino->piani('trainer')[0]['prezzo'],
            'daPalestra' => $listino->piani('palestre')[0]['prezzo'],
        ]);
    }

    /** I prezzi di chi si allena: gratis, Plus, gettoni. */
    public function prezziIndividuale(Listino $listino): View
    {
        return view('sito.prezzi-individuale', [
            'formatta' => $this->formattatore(),
            'prezzoSingolo' => $listino->singolo(),
            'pacchetti' => $listino->pacchettiGettoni(),
        ]);
    }

    /** I prezzi di un trainer indipendente. */
    public function prezziTrainer(Listino $listino): View
    {
        return $this->listinoDiUnaPlatea($listino, 'trainer');
    }

    /** I prezzi di una palestra. */
    public function prezziPalestra(Listino $listino): View
    {
        return $this->listinoDiUnaPlatea($listino, 'palestre');
    }

    /**
     * Le due pagine a posti, che sono **la stessa pagina con altri numeri**.
     *
     * 🚨 Due viste copiate divergono al primo ritocco, e la divergenza si vede
     * solo mettendo le due pagine una accanto all'altra — cioè mai. ⚠️ Quello
     * che cambia davvero è il **vocabolario** (allievi/iscritti) e il fatto che
     * una palestra ha anche il tetto dei trainer: sta tutto in questo array.
     */
    private function listinoDiUnaPlatea(Listino $listino, string $platea): View
    {
        $piani = $listino->piani($platea);

        return view('sito.prezzi-a-posti', [
            'formatta' => $this->formattatore(),
            'platea' => $platea,
            'piani' => $piani,
            'suMisura' => $listino->suMisura($platea),
            'costoAlMinimo' => $listino->costoSuMisura($platea, $listino->suMisura($platea)['minimo']),
            'piuGrande' => $piani[count($piani) - 1],
            'prezzoSingolo' => $listino->singolo(),
            'esempio' => $listino->esempio($platea),

            /*
             * ⛔ **Tolti il 20/09 sera: `chiamateMensili`, `costoVisualizzazione`
             * e `budgetMinimo`.**
             *
             * 🚨 Il numero delle chiamate incluse **non si pubblica** (📌 *«poi
             * ok sono 150 ma non lo diciamo»*), e soprattutto raccontava una
             * cosa falsa: quella quota copre **solo l'automatico**, non le
             * richieste fatte a mano, che si pagano a gettoni sempre.
             *
             * ⛔ E la pubblicità nel catalogo non si vende, perché il catalogo
             * come prodotto non esiste: vedi il commento in `prezzi-a-posti`.
             */
        ]);
    }

    /**
     * 💡 Il formattatore dei prezzi, uno solo per tutte le pagine.
     *
     * ⚠️ Era copiato in ogni metodo: quattro copie della stessa `number_format`,
     * e il giorno che si volesse scrivere «8 €» invece di «8,00 €» bisognerebbe
     * trovarle tutte.
     */
    private function formattatore(): \Closure
    {
        return static fn (int $centesimi): string => number_format($centesimi / 100, 2, ',', '.').' €';
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

            /*
             * 🆕 ✍️ Le due liberatorie dell'art. 28 — 20/09/2026.
             *
             * 💡 Pubbliche apposta: una palestra o un trainer devono poterle
             * **leggere prima** di trovarsele davanti da firmare, e chi le
             * riceve da un collega deve poterle aprire senza un account.
             */
            'liberatoria-palestra' => 'liberatoria-palestra',
            'liberatoria-trainer' => 'liberatoria-trainer',
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
