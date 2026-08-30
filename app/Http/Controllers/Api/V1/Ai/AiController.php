<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Enums\AiFeature;
use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Jobs\StimaIlCibo;
use App\Models\AiAdvice;
use App\Models\FoodEntry;
use App\Models\StimaCibo;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\FoodItem;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Ai\Guardie\MealValidator;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Ai\StimaConRitentativo;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Dashboard\DashboardService;
use App\Services\Nutrition\DiaryService;
use App\Support\Tempo\FasciaDelConsiglio;
use App\Support\Tempo\GiornoLocale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gli endpoint AI dell'app — B6.6.
 *
 * 🚨 **La quota si controlla PRIMA di chiamare il modello**, in ogni metodo.
 * Controllarla dopo vorrebbe dire aver gia' pagato i token che si sta rifiutando
 * di concedere: il tetto servirebbe a dire «hai sforato», non a impedirlo.
 *
 * Le eccezioni del layer AI hanno il proprio `render()` e arrivano al client
 * gia' tradotte: qui non si cattura niente, perche' un `try/catch` generico
 * finirebbe per trasformare un 429 di quota in un 500 generico.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly MemberAiQuota $quota,
        private readonly TenantContext $tenants,
        private readonly DiaryService $diary,
        private readonly DashboardService $dashboard,
        private readonly MealValidator $validatore,
        private readonly PortafoglioGettoni $portafoglio,
        private readonly CancelloDeiGettoni $cancello,
        private readonly StimaConRitentativo $stimatore,
        private readonly PianoAttivo $piani,
    ) {}

    // ───────────────────────── cibo ─────────────────────────

    /**
     * Accoda la stima di un pasto **scritto** — FASE 9.4.
     *
     * ══ 🚨 NON CHIAMA PIU' IL MODELLO, E QUESTO E' IL PUNTO ═══════════════
     *
     * Prima questo metodo restava fermo **2–8 secondi** dentro la chiamata al
     * modello, e con lui il processo PHP che serviva la richiesta. I processi di
     * questo dominio sono **sei** (`pm.max_children = 6`, misurato in FASE 8.1):
     * a sette persone che scrivevano il pranzo insieme **non si fermava l'AI, si
     * fermava il sito** — chi faceva il login, chi guardava la scheda, tutti in
     * coda dietro a una stima.
     *
     * ⚠️ E il cibo si scrive a pranzo e a cena, cioe' **per definizione tutti
     * insieme**: non era un picco immaginario da grande scala, era il
     * funzionamento normale dell'app con sette utenti.
     *
     * 💡 Adesso qui dentro si valida, si apre il cancello, si scrive una riga e
     * si accoda: **~50 ms**. L'attesa sta su un worker, dove non toglie posto a
     * nessuno.
     *
     * ── 🚨 `save` non esiste piu' su questa strada, ed e' una decisione ────
     *
     * L'app manda **sempre** `save: false` e poi conferma da
     * `POST ai/food/confirm`: quella e' la strada vera, e passa di li' perche'
     * `source` e `ai_raw` devono sopravvivere alla conferma.
     *
     * ⚠️ Ma soprattutto `save: true` **vorrebbe dire un'altra cosa adesso**: la
     * stima finisce minuti dopo, magari con l'app chiusa, e scrivere in diario
     * mentre nessuno guarda non e' la stessa funzione di prima. 🚨 Si rifiuta con
     * un `422` che lo dice, invece di ignorarlo in silenzio: un parametro
     * accettato e non applicato e' peggio di uno rifiutato.
     */
    public function foodFromText(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:1000'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            /*
             * ⚠️ `sometimes` e non `nullable`: `declined` è una regola
             * **implicita**, cioè fallisce anche quando il campo **manca**.
             * Con `nullable` ogni richiesta senza `save` prendeva 422 — cioè
             * tutte quelle dell'app.
             */
            'save' => ['sometimes', 'declined'],
        ], [
            'save.declined' => __('Le stime si confermano da /ai/food/confirm.'),
        ]);

        $utente = $request->user();

        /*
         * 🚨 **Il cancello PRIMA di accodare.** Aprirlo nel worker vorrebbe dire
         * accettare la richiesta, far vedere «sto pensando», e poi dire di no —
         * cioe' far aspettare qualcuno per un rifiuto che si sapeva gia'.
         */
        $conGettoni = $this->assertQuota($utente, AiFeature::FoodText);

        $stima = StimaCibo::daTesto($utente, [
            'text' => $dati['text'],
            'meal' => $dati['meal'] ?? null,
            'eaten_at' => $dati['eaten_at'] ?? null,
        ], $conGettoni);

        StimaIlCibo::dispatch((int) $stima->getKey());

        return response()->json(['data' => $stima->perLApp()], 202);
    }

    /**
     * Accoda la stima di un pasto **fotografato** — FASE 9.4.
     *
     * 🚨 **La foto si deposita, perche' il worker deve poterla leggere.** Il file
     * temporaneo dell'upload sparisce con la richiesta, e la richiesta finisce
     * fra 50 millisecondi.
     *
     * ⚠️ **Ed e' il pezzo che cambia il registro dei trattamenti**: una foto di
     * un pasto passava e basta, adesso **sta a riposo sul nostro disco** per i
     * secondi che separano l'accodamento dal turno. 💡 `StimaCibo::completa()` la
     * cancella appena la stima esiste — anche quando fallisce.
     */
    public function foodFromPhoto(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            /*
             * ⚠️ `sometimes` e non `nullable`: `declined` è una regola
             * **implicita**, cioè fallisce anche quando il campo **manca**.
             * Con `nullable` ogni richiesta senza `save` prendeva 422 — cioè
             * tutte quelle dell'app.
             */
            'save' => ['sometimes', 'declined'],
        ], [
            'save.declined' => __('Le stime si confermano da /ai/food/confirm.'),
        ]);

        $utente = $request->user();
        $file = $request->file('photo');

        $conGettoni = $this->assertQuota($utente, AiFeature::FoodPhoto);

        $stima = StimaCibo::daFoto(
            $utente,
            (string) file_get_contents($file->getRealPath()),
            (string) $file->getMimeType(),
            [
                'meal' => $dati['meal'] ?? null,
                'eaten_at' => $dati['eaten_at'] ?? null,
            ],
            $conGettoni,
        );

        StimaIlCibo::dispatch((int) $stima->getKey());

        return response()->json(['data' => $stima->perLApp()], 202);
    }

    /**
     * A che punto e' una stima — FASE 9.4.
     *
     * ── 🚨 Perche' NON sta sotto `ai.tetto` ───────────────────────────────
     *
     * Perche' chiedere «e' pronta?» non chiama nessun modello, e occupare uno
     * degli slot del semaforo per un controllo di stato vorrebbe dire togliere
     * posto a una stima vera. ⚠️ Sotto un `throttle` suo pero' si': l'app lo
     * chiede ogni secondo e mezzo.
     *
     * ── 🚨 Si controlla il PROPRIETARIO, non solo la palestra ─────────────
     *
     * Una stima e' cosa ha mangiato una persona. Lo scope di tenant fermerebbe
     * un'altra palestra e **non** un compagno di palestra: qui serve
     * l'appartenenza, ed e' esattamente la regola gia' scritta per i messaggi e
     * per il diario.
     */
    public function statoStima(Request $request, int $stima): JsonResponse
    {
        $riga = StimaCibo::query()
            ->where('user_id', $request->user()->getKey())
            ->find($stima);

        if ($riga === null) {
            return response()->json(['message' => __('Stima non trovata.')], 404);
        }

        return response()->json(['data' => $riga->perLApp()]);
    }

    /**
     * L'ultima stima non ancora finita, se c'e' — FASE 9.7.
     *
     * 🚨 **Serve a chi ha chiuso l'app mentre pensava.** Il lavoro continua sul
     * server: al rientro l'app deve **ritrovarlo**, non ricominciare — che
     * vorrebbe dire una seconda chiamata al modello per lo stesso piatto.
     *
     * 💡 L'app tiene l'id in `LocalCache`, ma non basta: chi cambia telefono, o
     * chi ha svuotato i dati, quell'id non ce l'ha. Questa rotta e' la rete di
     * sicurezza che non dipende dal telefono.
     */
    public function stimaInCorso(Request $request): JsonResponse
    {
        $riga = StimaCibo::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('stato', [StimaCibo::IN_CODA, StimaCibo::IN_LAVORAZIONE])
            ->latest('id')
            ->first();

        return response()->json(['data' => $riga?->perLApp()]);
    }

    /**
     * Chiama il modello, controlla, e **una volta sola** gli chiede di rifare.
     *
     * ── 🚨 Perche' un solo tentativo ──────────────────────────────────────────
     *
     * Un errore grave e' quasi sempre di formato — un'unita' vietata, un enum
     * sconosciuto — e il modello lo corregge alla prima richiesta. Se non lo
     * corregge alla seconda non lo correggera' alla terza: quello che
     * cambierebbe, ripetendo, e' solo il conto di fine mese.
     *
     * ⚠️ **Se anche il secondo tentativo fallisce si restituisce comunque la
     * stima**, con gli errori negli avvisi. E' l'app che decide: il foglio di
     * conferma li mostra, e la persona corregge o annulla. Rifiutare tutto
     * vorrebbe dire buttare una chiamata gia' pagata e lasciare chi scrive senza
     * niente.
     *
     * @param  callable(string): FoodEstimate  $chiamata
     * @return array{0: FoodEstimate, 1: list<string>}
     */
    private function stimaValidata(callable $chiamata): array
    {
        return $this->stimatore->esegui($chiamata);
    }

    /**
     * Scrive nel diario una stima che la persona ha **confermato** — A4.8.
     *
     * ── 🚨 Perche' esiste un endpoint invece di riusare `/food-entries` ──────
     *
     * Perche' `source` e `ai_raw` devono sopravvivere alla conferma. `FoodSource`
     * lo dice da sempre: *«quando un modello AI comincia a sbagliare le stime,
     * bisogna poter ritrovare TUTTE le voci che ha prodotto»*. Facendo scrivere
     * l'app con `POST /food-entries` ogni voce nascerebbe `manual`, e quella
     * ricerca non sarebbe piu' possibile — cioe' il giorno che un modello
     * peggiora non si saprebbe piu' quali voci rifare.
     *
     * E perche' un pasto e' **una cosa sola**: cinque `POST` separati possono
     * fallire al terzo e lasciare in diario mezza cena, che nei totali e' un
     * numero sbagliato senza nessun segno che lo sia. Qui si scrive in
     * transazione: o tutto o niente.
     *
     * 🚨 **Non chiama il modello e NON consuma quota.** La chiamata e' gia'
     * stata pagata da `foodFromText`/`foodFromPhoto` con `save: false`:
     * rifiutare di salvare cio' che si e' gia' speso sarebbe far pagare due
     * volte lo stesso pasto. Per la stessa ragione qui non c'e' `assertQuota()`.
     *
     * ⚠️ Sta comunque dietro `ai.consent`: la revoca del consenso deve fermare
     * **tutto il flusso**, non solo il pezzo che parla con Anthropic. Una voce
     * che entra in diario dopo la revoca farebbe sembrare che la revoca non
     * abbia funzionato — ed e' esattamente il difetto #3 del 12/08.
     */
    public function confirm(Request $request): JsonResponse
    {
        $dati = $request->validate([
            // 🚨 Solo le due fonti AI: questo endpoint esiste per conservare
            // l'origine, e accettare `manual` o `plan` vorrebbe dire lasciar
            // marchiare come «dal piano» una voce che il piano non contiene.
            'source' => ['required', Rule::in([FoodSource::AiText->value, FoodSource::AiPhoto->value])],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.unit' => ['nullable', 'string', 'max:16'],
            'items.*.grams' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.kcal' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.protein' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.fat' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            // ⚠️ Grammi di alcol etilico: non finisce in nessuna colonna, ma se
            // non passasse di qui la conferma perderebbe l'unico dato che rende
            // coerente una bevanda alcolica.
            'items.*.alcohol' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            // I campi che l'app rimanda indietro cosi' come li ha ricevuti: non
            // finiscono in colonne, ma restano in `ai_raw` e servono al validatore.
            'items.*.ml' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.basis' => ['nullable', Rule::in(FoodItem::BASI)],
            'items.*.state' => ['nullable', Rule::in(FoodItem::STATI)],
            'items.*.declared' => ['nullable', 'boolean'],
            'items.*.brand' => ['nullable', 'string', 'max:120'],
            'items.*.abv_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        /*
         * ⚠️ Si ricostruisce un `FoodEstimate` invece di scrivere dai dati
         * grezzi: cosi' la conferma passa dalle stesse normalizzazioni della
         * scrittura diretta — compresi i totali, che `normalizeTotals()`
         * ricalcola quando mancano. Due strade che scrivono lo stesso pasto in
         * due modi diversi e' come nascono i totali che non tornano.
         */
        $esito = $this->validatore->valida(FoodEstimate::fromArray(['items' => $dati['items']]));
        $stima = $esito['stima'];

        $voci = $this->scriviVoci(
            $request->user(),
            $stima,
            FoodSource::from($dati['source']),
            $dati,
        );

        return response()->json(['data' => [
            'estimate' => $stima->toArray(),
            'entries' => array_map(fn (FoodEntry $v): array => $this->diary->voce($v), $voci),
            'saved' => true,
        ]], 201);
    }

    // ───────────────────────── consiglio ─────────────────────────

    /**
     * Il consiglio del giorno.
     *
     * 🚨 **La cache e' su un hash del contesto, e la data ne fa parte.** Da qui
     * discendono due cose senza nessun cron: il consiglio si rigenera a
     * mezzanotte, e si rigenera quando l'utente mangia o si allena — cioe'
     * quando ha senso rifarlo. Un job notturno costerebbe una chiamata per ogni
     * utente ogni notte, comprese quelle di chi non apre l'app da un mese.
     */
    public function advice(Request $request): JsonResponse
    {
        if (! config('ai.advice.enabled')) {
            return response()->json(['data' => null]);
        }

        $utente = $request->user();

        $contesto = $this->contestoConsiglio($request);

        /*
         * 🚨 **«Rigenera» salta la cache, ed e' tutto il senso del pulsante** —
         * 16/08/2026.
         *
         * Senza questa condizione il tocco restituirebbe **lo stesso testo di
         * prima** senza spendere niente: sembrerebbe rotto, e chi lo prova due
         * volte penserebbe che il pulsante non funziona.
         *
         * ⚠️ E allora deve **pagare**: e' una chiamata vera al modello. Il
         * pulsante lo dice prima di essere toccato (`1 gettone`), che e' l'unico
         * modo onesto di far spendere qualcuno.
         */
        $manuale = $request->boolean('manuale');

        /*
         * 🚨 **L'hash si calcola UNA volta** — FASE 2-septies.
         *
         * ⚠️ Prima veniva ricalcolato in tre punti (cache, scrittura, e ora
         * anche lucchetto e `catch`): tre chiamate a `chiaveDiCache()` sullo
         * stesso array sono tre occasioni perche' un domani una diverga dalle
         * altre — e una cache che cerca con una chiave e scrive con un'altra
         * **non sbaglia mai in modo visibile**: genera semplicemente sempre.
         */
        $chiaveCache = self::chiaveDiCache($contesto);

        /*
         * ══ 🕘 LA FASCIA, CHE E' IL PRIMO DEI DUE CANCELLI — 3b-AB ═════════
         *
         * 📌 *«il consiglio del giorno si rigeneri in automatico solo 3 volte
         * al giorno (9:00, 14:00 e 22:00)»*.
         *
         * ⛔ **Prima si cercava per `context_hash`**, e nell'hash ci sono
         * `totals`, `burned` e `targets`: ogni pasto registrato era una cache
         * mancata, cioe' una chiamata al modello. Sei pasti facevano sei
         * chiamate, tutte automatiche.
         *
         * 💡 Dentro una fascia il consiglio e' **uno**, qualunque cosa si
         * registri: chi segna il pranzo alle 13:00 rilegge quello delle 9,
         * e alle 14:01 ne trova uno nuovo.
         */
        $fascia = FasciaDelConsiglio::adesso($utente);

        $cache = $manuale
            ? null
            : AiAdvice::nellaFascia($utente, $fascia, 'daily');

        if ($cache !== null) {
            return $this->rispostaConsiglio($cache, cached: true);
        }

        /*
         * 🚨 **L'interruttore del committente** — 16/08/2026.
         *
         * Chi l'ha spento non vuole che il consiglio si rigeneri da solo. ⚠️ Si
         * controlla **qui e non nel middleware**: la lettura del consiglio gia'
         * scritto deve continuare a funzionare — spegnere l'aggiornamento non
         * vuol dire cancellare quello che c'e'.
         *
         * 💡 E si controlla **dopo** la cache: se il consiglio della fascia
         * corrente esiste gia', lo si restituisce comunque. L'interruttore
         * ferma la spesa, non la lettura.
         */
        if ($utente->consiglio_automatico === false && ! $manuale) {
            return response()->json(['data' => null]);
        }

        /*
         * ══ 🎯 IL SECONDO CANCELLO: SENZA NOTIZIE NON SI PAGA — 3b-AB ══════
         *
         * 📌 *«questo puo' succedere solo dopo che apri l'app e solo dopo che
         * si e' registrato un pasto, un allenamento o il sonno»*.
         *
         * 🚨 **La fascia da sola non basta.** Mette il tetto a tre al giorno,
         * ma tre chiamate fatte per niente restano tre chiamate: chi apre
         * l'app alle 09:10 senza aver toccato niente da ieri sera non ha
         * nessuna notizia da raccontare, e riceverebbe un consiglio identico a
         * quello di ieri sera — pagato.
         *
         * 💡 E allora si restituisce **quello che c'e'**, invece di `null`:
         * l'app lo mostra con la sua data, e chi legge vede che e' di prima.
         * ⛔ Rispondere `null` la manderebbe sul suo ricordo locale, cioe'
         * sullo stesso testo, ma senza sapere di quando e'.
         */
        if (! $manuale) {
            $ultimo = AiAdvice::ultimo($utente, 'daily');

            if (! $this->qualcosaDiNuovo($request, $utente, $ultimo)) {
                return $ultimo !== null
                    ? $this->rispostaConsiglio($ultimo, cached: true)
                    : response()->json(['data' => null]);
            }
        }

        /*
         * ══ 🚨 IL LUCCHETTO — FASE 2-septies, 21/08/2026 ═══════════════════
         *
         * **Il difetto**: fra il «guardo se c'e' in cache» e lo «scrivo» passano
         * i secondi della chiamata al modello. Una seconda richiesta identica
         * arrivata in quella finestra trovava la cache **ancora vuota**, partiva
         * anche lei, e al momento di scrivere sbatteva contro
         * `ai_advices_unique` → `23000` → **500**. Gli orari nel log del 20/08
         * combaciano al secondo con le righe scritte.
         *
         * ⚠️ **Ma il 500 era il sintomo meno costoso.** Il danno vero e' che
         * *tutt'e due* avevano gia' chiamato il modello e **pagato**
         * (`consumaGettoniSeServe` sta prima della scrittura): una delle due
         * risposte si buttava. Con un utente e' rumore; con mille e' una
         * percentuale fissa di soldi.
         *
         * 💡 Il lucchetto e' la parte che **risparmia**; il `catch` piu' sotto
         * e' quella che **non fa fallire**. Servono tutt'e due e fanno due
         * lavori diversi: chi non prende il lucchetto entro `LUCCHETTO_ATTESA`
         * genera lo stesso, e li' il `catch` lo raccoglie.
         *
         * ── ⚠️ Perche' NON sul percorso manuale ─────────────────────────
         *
         * Perche' «Rigenera» esiste apposta per **saltare la cache e pagare**:
         * farlo aspettare e poi consegnargli il testo di un altro sarebbe
         * disattivare il pulsante di nascosto. 💡 Il doppio tocco accidentale lo
         * ferma gia' l'app, che disabilita il bottone mentre e' in corso.
         */
        $lucchetto = $manuale
            ? null
            : Cache::lock('ai:advice:'.$utente->getKey().':'.$fascia->etichetta(), self::LUCCHETTO_SECONDI);

        if ($lucchetto !== null) {
            try {
                $lucchetto->block(self::LUCCHETTO_ATTESA);
            } catch (LockTimeoutException) {
                /*
                 * ⚠️ **Scaduta l'attesa si va avanti lo stesso, e non e' una
                 * resa.** L'alternativa sarebbe rispondere con un errore a chi
                 * ha solo avuto la sfortuna di arrivare secondo: si preferisce
                 * spendere una chiamata in piu' che far vedere un guasto.
                 */
                $lucchetto = null;
            }

            /*
             * 🚨 **Si riguarda la cache DOPO aver aspettato.** E' tutto il
             * senso del lucchetto: chi ha aspettato trova la risposta gia'
             * scritta da chi e' arrivato primo, e non chiama il modello.
             */
            $giaFatto = AiAdvice::nellaFascia($utente, $fascia, 'daily');

            if ($giaFatto !== null) {
                $lucchetto?->release();

                return $this->rispostaConsiglio($giaFatto, cached: true);
            }
        }

        try {
            return $this->generaEScrivi($request, $utente, $fascia, $contesto, $chiaveCache, $manuale);
        } finally {
            // ⚠️ `finally`: un'eccezione dentro la generazione non deve lasciare
            // la chiave occupata per un minuto a tutti gli altri.
            $lucchetto?->release();
        }
    }

    /**
     * Genera il consiglio, lo scrive e risponde — estratto da `advice()`.
     *
     * 💡 Sta a parte solo perche' `advice()` deve poterlo avvolgere in un
     * `try/finally` che rilascia il lucchetto: con tutto in linea, il `finally`
     * avrebbe dovuto abbracciare mezzo metodo.
     *
     * @param  array<string, mixed>  $contesto
     * @param  array<string, mixed>  $chiaveCache
     */
    private function generaEScrivi(
        Request $request,
        User $utente,
        FasciaDelConsiglio $fascia,
        array $contesto,
        array $chiaveCache,
        bool $manuale,
    ): JsonResponse {
        $conGettoni = $this->assertQuota($request->user(), AiFeature::DailyAdvice);

        $testo = $this->ai->for(AiFeature::DailyAdvice)->dailyAdvice(
            $contesto,
            AiCallContext::for($utente, AiFeature::DailyAdvice),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::DailyAdvice, $conGettoni);

        /*
         * ⚠️ **Una rigenerazione SOSTITUISCE, non affianca.**
         *
         * Senza, ogni tocco lascerebbe una riga in piu' dello stesso giorno: la
         * cache ne troverebbe una a caso fra quelle con lo stesso hash, e la
         * tabella crescerebbe proprio nel momento in cui abbiamo appena smesso
         * di farla crescere.
         */
        if ($manuale) {
            /*
             * ⚠️ **Si cancella la riga della FASCIA, non quelle del giorno** —
             * 3b-AB. Prima delle 09:00 la fascia e' quella delle 22 di ieri:
             * cancellare «le righe di oggi» non toccherebbe quella che stiamo
             * per sostituire, e l'indice unico rifiuterebbe la scrittura.
             */
            AiAdvice::withoutGlobalScopes()
                ->where('user_id', $utente->getKey())
                ->where('fascia', $fascia->etichetta())
                ->delete();
        }

        try {
            $riga = AiAdvice::create([
                'tenant_id' => $utente->tenant_id,
                'user_id' => $utente->getKey(),
                /*
                 * 🚨 **Il giorno della FASCIA, non quello dell'orologio.** Un
                 * consiglio generato alle 07:00 appartiene alla fascia delle 22
                 * di *ieri*, e porta la data di ieri: e' quello che tiene il
                 * tetto a tre in ventiquattr'ore invece che a quattro.
                 */
                'date' => $fascia->giorno->etichetta,
                'fascia' => $fascia->etichetta(),
                'kind' => 'daily',
                'context_hash' => AiAdvice::hashOf($chiaveCache),
                'body' => $testo,
                'model' => $this->ai->modelFor(AiFeature::DailyAdvice),
            ]);
        } catch (QueryException $errore) {
            /*
             * ══ 🚨 CHI PERDE LA CORSA RICEVE IL CONSIGLIO, NON UN 500 ══════
             *
             * Per chi usa l'app le due richieste erano **la stessa domanda**, e
             * devono avere la stessa risposta. Un errore qui verrebbe letto
             * come «l'AI non ha funzionato», che non e' vero: ha funzionato due
             * volte.
             *
             * ⚠️ **Ma solo se e' davvero la nostra corsa.** Un `23000` che non
             * sia il nostro duplicato — un altro vincolo, una colonna sballata
             * — va **rilanciato**: un `catch` che ingoia tutto trasforma un
             * difetto in un mistero, ed e' il modo classico di rendere
             * invisibile il prossimo.
             *
             * 💡 La prova che e' la nostra corsa non e' il codice d'errore: e'
             * che **la riga adesso c'e'**. Se non c'e', il 23000 parlava
             * d'altro.
             */
            $vinta = $errore->getCode() === '23000'
                ? AiAdvice::nellaFascia($utente, $fascia, 'daily')
                : null;

            if ($vinta === null) {
                throw $errore;
            }

            return $this->rispostaConsiglio($vinta, cached: true);
        }

        /*
         * 🚨 **Si pota qui, subito dopo aver scritto** — 16/08/2026.
         *
         * Questa tabella e' una **cache**: `AiAdvice::cached()` guarda solo il
         * giorno corrente. ⚠️ Ma nessuno la potava, quindi ogni consiglio mai
         * generato restava li' per sempre — e un consiglio e' il testo piu'
         * intimo che abbiamo sul server.
         *
         * 💡 Potare **nello stesso punto in cui si scrive** invece che in un
         * comando schedulato: un cron e' una cosa che un giorno non gira, e
         * nessuno se ne accorge finche' la tabella non e' gonfia.
         */
        AiAdvice::pota((int) $utente->getKey(), $fascia);

        return $this->rispostaConsiglio($riga, cached: false);
    }

    /**
     * E' successo qualcosa da quando abbiamo scritto l'ultimo consiglio? — 3b-AB.
     *
     * ══ 📌 LA REGOLA ══════════════════════════════════════════════════════
     *
     * 📌 *«solo dopo che si e' registrato un pasto, un allenamento o il
     * sonno»*.
     *
     * ══ 🚨 PERCHE' LE FONTI SONO DUE, E NON E' UN DOPPIONE ════════════════
     *
     * | Cosa | Chi lo sa | Perche' |
     * |---|---|---|
     * | i **pasti** | il server | `food_entries` e' nostra: `created_at` dice quando e' stata *registrata*, non quando si e' mangiato |
     * | **allenamenti** e **sonno** | 🚨 solo il telefono | dopo D9 e FASE 11.6 il server non ha piu' ne' `workout_sessions` ne' i dati del sensore. **Quello che non ha non puo' accorgersi che e' cambiato** |
     *
     * ⛔ **Fidarsi dei soli pasti sarebbe un difetto silenzioso**: chi si
     * allena e non segna niente da mangiare non vedrebbe mai un consiglio
     * nuovo, e il consiglio parlerebbe di una giornata in cui *«non ti sei
     * mosso»* proprio a chi si e' appena allenato.
     *
     * 💡 `created_at` e non `eaten_at`: un pasto di ieri sera segnato stamattina
     * e' una notizia di **stamattina**. La domanda qui e' *«e' successo
     * qualcosa dopo l'ultimo consiglio»*, non *«quando si e' mangiato»*.
     *
     * ══ ⚠️ E IL CLIENT CHE NON MANDA NIENTE ═══════════════════════════════
     *
     * | `last_event_at` | Cosa vuol dire | Cosa si fa |
     * |---|---|---|
     * | **assente** | app vecchia, che questo parametro non lo conosce | 🚨 **si genera**: si comporta come prima, e la fascia mette comunque il tetto a tre |
     * | **vuoto** | app nuova che dice «non ho mai registrato niente» | ⛔ non si genera |
     * | una data | app nuova | si confronta |
     *
     * 🚨 **La distinzione fra «assente» e «vuoto» e' tutto il punto.** Trattare
     * i due casi allo stesso modo vorrebbe dire scegliere fra due difetti: o
     * un'app vecchia che non riceve mai un consiglio nuovo, o un'app nuova che
     * ne genera uno a vuoto ogni fascia.
     */
    private function qualcosaDiNuovo(Request $request, User $utente, ?AiAdvice $ultimo): bool
    {
        if (! $request->has('last_event_at')) {
            return true;
        }

        $grezzo = trim((string) $request->query('last_event_at', ''));

        $dalTelefono = $grezzo === ''
            ? null
            : Carbon::parse($grezzo);

        $ultimoPasto = FoodEntry::query()
            ->where('user_id', $utente->getKey())
            ->max('created_at');

        $dalServer = $ultimoPasto !== null ? Carbon::parse($ultimoPasto) : null;

        /*
         * 💡 La piu' recente delle due, e non «una qualsiasi delle due»: la
         * domanda e' *quando e' successa l'ultima cosa*, e la risposta e' una
         * sola anche quando le fonti sono due.
         */
        $evento = match (true) {
            $dalTelefono === null => $dalServer,
            $dalServer === null => $dalTelefono,
            default => $dalTelefono->gt($dalServer) ? $dalTelefono : $dalServer,
        };

        if ($evento === null) {
            return false;
        }

        /*
         * ⚠️ **Nessun consiglio ancora scritto**: se qualcosa e' stato
         * registrato, si genera. E' il primo consiglio di questa persona, e non
         * c'e' niente con cui confrontarlo.
         */
        return $ultimo?->created_at === null || $evento->gt($ultimo->created_at);
    }

    /**
     * La risposta del consiglio, con la stessa forma in tutti e quattro i punti
     * da cui puo' uscire.
     *
     * 💡 Quattro `response()->json` copiati sarebbero quattro posti in cui
     * dimenticare `generated_at` — che e' il campo da cui l'app decide se
     * scrivere «di ieri».
     */
    private function rispostaConsiglio(AiAdvice $riga, bool $cached): JsonResponse
    {
        return response()->json(['data' => [
            'body' => $riga->body,
            'cached' => $cached,
            'generated_at' => $riga->created_at?->toIso8601String(),
        ]]);
    }

    /**
     * La stima di un alimento **mentre il trainer compone un piano** — G5.11, D13.
     *
     * ── 🚨 Perche' sta qui e non in `NutritionPlanController` ─────────────
     *
     * Perche' il cancello e' questo: quota inclusa, poi gettoni, poi `402`.
     * Riscriverlo nel controller dei piani vorrebbe dire **due sedi della
     * stessa regola commerciale** — e due sedi divergono. Quella che
     * divergerebbe per prima sarebbe la copia, cioe' quella meno provata.
     *
     * ── 🚨 La paga il TRAINER, mai l'allievo ─────────────────────────────
     *
     * `$request->user()` e' chi sta componendo. Quando l'allievo ricevera' il
     * piano, il costo e' gia' stato sostenuto da chi l'ha scritto.
     *
     * ── ⚠️ E i valori restano modificabili ────────────────────────────────
     *
     * Questa rotta **propone**, non decide: il trainer corregge quello che
     * vuole, e la sua correzione vince sempre. Il campo `origine_valori`
     * ricorda quale delle due l'ha scritto — serve a lui per vedere cosa ha
     * gia' controllato, non a noi per discutere.
     */
    public function planFood(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $utente = $request->user();

        if (! ($utente->isTrainer() || $utente->isFreeTrainer() || $utente->isGymAdmin() || $utente->isSuperAdmin())) {
            return response()->json([
                'message' => __('Solo chi allena può usare la stima nei piani.'),
                'code' => 'not_a_trainer',
            ], 403);
        }

        $conGettoni = $this->assertQuota($utente, AiFeature::PlanFood);

        [$stima, $avvisi] = $this->stimaValidata(
            fn (string $appendice): FoodEstimate => $this->ai->for(AiFeature::PlanFood)->foodFromText(
                $dati['text'].$appendice,
                AiCallContext::for($utente, AiFeature::PlanFood),
            ),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::PlanFood, $conGettoni);

        return response()->json(['data' => [
            /*
             * 💡 Si restituiscono gli alimenti nella forma che
             * `NutritionPlanRequest` accetta, cosi' l'app puo' incollarli nel
             * piano senza tradurre niente. Una forma diversa qui vorrebbe dire
             * una conversione nell'app, cioe' un punto in piu' in cui i campi
             * possono divergere.
             */
            'items' => array_map(static fn (FoodItem $i): array => [
                'description' => $i->name,
                'qty' => $i->qty,
                'unit' => $i->unit,
                'grams' => $i->grams,
                'kcal' => $i->kcal,
                'protein' => $i->protein,
                'carbs' => $i->carbs,
                'fat' => $i->fat,
                'origine_valori' => 'ai',
            ], $stima->items),
            'warnings' => $avvisi,
        ]]);
    }

    // ───────────────────────── progressione ─────────────────────────

    /**
     * L'analisi della progressione degli esercizi di una scheda — 3b-I.A.
     *
     * ══ 🚨 IL CONTESTO LO MANDA L'APP, E NON E' UNA SCORCIATOIA ═══════════
     *
     * ⛔ Il server **non ha** lo storico delle serie: sta sul telefono
     * (`SerieDelleSedute`), perche' *«tutti i dati che possono essere anche
     * LONTANAMENTE sensibili devono restare solo on-device»*. Quindi qui non si
     * puo' ricostruire niente: o lo manda l'app, o non c'e'.
     *
     * ⚠️ **Conseguenza da non dimenticare**: il contenuto di questa richiesta e'
     * l'unico momento in cui quei numeri escono dal telefono. Non si scrivono a
     * database — ne' la domanda ne' la risposta: l'analisi torna indietro e vive
     * sul telefono. E' il motivo per cui non esiste una tabella `plan_progress`.
     *
     * 🆕 **Dal 27/08 esce anche come e' cambiata la scheda** (`cambi_alla_scheda`):
     * solo i valori prescritti — serie, ripetizioni, peso, recupero — con il
     * prima e il dopo. ⛔ Non il nome della scheda, non le note: la stessa lista
     * chiusa di sempre, allungata di una voce e scritta anche nel registro.
     *
     * ══ ⚖️ IL GATE E' L'ABBONAMENTO, NON I GETTONI ════════════════════════
     *
     * 📌 *«la versione gratuita NON DEVE guadagnare niente»*. Chi non e' abbonato
     * prende **403** anche se ha gettoni da spendere: i gettoni comprano le
     * chiamate, l'abbonamento compra le **funzioni**. 💡 Sono due monete diverse,
     * e questa e' l'unica riga in cui la differenza si vede.
     *
     * ⚠️ E il controllo sta **qui**, non solo nell'app: un pulsante grigio e'
     * un'indicazione, non una serratura.
     *
     * ══ 💡 Perche' sincrono, quando il cibo non lo e' piu' ════════════════
     *
     * `foodFromText` e' diventato asincrono perche' si scrive a pranzo e a cena,
     * cioe' tutti insieme, e sei processi PHP finivano occupati. ⚠️ Questa
     * chiamata invece la fa **un abbonato, per una scheda, non piu' di una volta
     * a settimana**: la coda costerebbe un job, una notifica e uno stato da
     * mostrare per un problema che non esiste. Se un giorno esistesse, il posto
     * dove guardare e' questo commento.
     */
    public function progressoScheda(Request $request): JsonResponse
    {
        $utente = $request->user();

        /*
         * ⚠️ **`abort` e non un'eccezione di quota.** Un 429 direbbe «riprova piu'
         * tardi», e chi non e' abbonato riprovando non otterrebbe niente. Il 403
         * con il suo codice e' quello che l'app traduce nella modale.
         */
        abort_unless($utente !== null && $this->piani->eAbbonato($utente), 403, 'Serve l\'abbonamento.');

        $dati = $request->validate([
            /*
             * 🚨 **Il tetto sta nella validazione, non nel prompt.** E' l'unica
             * cosa che tiene sotto controllo la fattura: senza `max`, una scheda
             * costruita a mano con duemila esercizi sarebbe una richiesta da
             * duemila esercizi — pagata da noi, al prezzo di un gettone.
             */
            'esercizi' => ['required', 'array', 'min:1', 'max:40'],
            'esercizi.*.id' => ['required', 'integer'],
            'esercizi.*.nome' => ['required', 'string', 'max:120'],
            'esercizi.*.sedute' => ['required', 'array', 'max:12'],
            'esercizi.*.sedute.*.data' => ['required', 'date'],
            'esercizi.*.sedute.*.carico' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'esercizi.*.sedute.*.ripetizioni' => ['nullable', 'integer', 'min:0', 'max:1000'],

            /*
             * 📐 **Come e' cambiata la scheda** — 3b-I.E, 27/08/2026.
             *
             * 📌 *«deve vedere com'era prima e com'era dopo»*.
             *
             * 🚨 **E' la differenza fra una lettura e un'osservazione.** Senza,
             * il modello vede numeri che salgono o scendono e puo' soltanto
             * ripeterli. ⛔ E soprattutto non ha modo di sapere che le
             * ripetizioni sono scese *perche' e' stata aggiunta una serie* —
             * cioe' scriverebbe un peggioramento che non e' successo.
             *
             * ⚠️ **Facoltativo**: le schede mai modificate non ce l'hanno, ed e'
             * la maggioranza.
             */
            /*
             * 🏆 **I primati li calcola il telefono** — 3b-I.F.
             *
             * 🚨 Sono numeri sullo storico **intero**: il massimo di sempre, da
             * quante sedute il carico non si muove. ⛔ Mandare mesi di sedute
             * per far fare un `max` al modello costerebbe dieci volte tanto —
             * e i modelli linguistici i confronti fra numeri li sbagliano, cioe'
             * direbbero «e' il tuo record» quando non lo e'.
             */
            'esercizi.*.primati' => ['sometimes', 'array'],
            'esercizi.*.primati.sedute_totali' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'esercizi.*.primati.dal' => ['nullable', 'date'],
            'esercizi.*.primati.carico_massimo' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'esercizi.*.primati.quando_il_massimo' => ['nullable', 'date'],
            'esercizi.*.primati.sedute_allo_stesso_carico' => ['nullable', 'integer', 'min:0', 'max:10000'],

            'esercizi.*.cambi_alla_scheda' => ['sometimes', 'array', 'max:20'],
            'esercizi.*.cambi_alla_scheda.*.cosa' => ['required', 'string', 'max:20'],
            'esercizi.*.cambi_alla_scheda.*.prima' => ['nullable', 'string', 'max:60'],
            'esercizi.*.cambi_alla_scheda.*.dopo' => ['nullable', 'string', 'max:60'],

            /*
             * 🔥 **Le calorie delle sedute** — 3b-AB, 30/08/2026.
             *
             * 📌 *«mettiamoci dentro anche le calorie consumate da
             * quell'allenamento se ci sono»*.
             *
             * ⚠️ **Le calcola il telefono** (`CalorieAllenamento`), e arrivano
             * gia' fatte: dopo la FASE 11.6 il server non ha piu' le sedute, e
             * quello che non ha non puo' calcolare.
             *
             * 🚨 **`fonte` non e' un ornamento.** `manuale` e' un numero
             * dichiarato da chi si e' allenato; `stima` e' `MET x kg x ore`, e
             * su un peso non recente sbaglia di qualche decina di kcal. ⛔ Senza
             * questo campo il modello tratterebbe la stima come una misura, e
             * scriverebbe una frase precisa su un numero che non lo e'.
             *
             * 💡 Il `max:12` regge la stessa ragione del tetto sugli esercizi:
             * e' l'unica cosa che tiene sotto controllo la fattura.
             */
            'allenamenti' => ['sometimes', 'array', 'max:12'],
            'allenamenti.*.data' => ['required', 'date'],
            'allenamenti.*.kcal' => ['required', 'integer', 'min:1', 'max:10000'],
            'allenamenti.*.fonte' => ['required', 'string', 'in:manuale,stima'],
        ]);

        $conGettoni = $this->assertQuota($utente, AiFeature::PlanProgress);

        $risposta = $this->ai->for(AiFeature::PlanProgress)->progressoScheda(
            $dati,
            AiCallContext::for($utente, AiFeature::PlanProgress),
        );

        $righe = $risposta['esercizi'] ?? [];

        $this->consumaGettoniSeServe($utente, AiFeature::PlanProgress, $conGettoni);

        /*
         * ⛔ **Si tengono solo gli id chiesti.** Un modello che inventa un id
         * farebbe comparire una riga sotto un esercizio che non c'entra — e
         * sarebbe una riga *plausibile*, cioe' del tipo che non si scopre
         * guardando.
         */
        $chiesti = array_column($dati['esercizi'], 'id');

        return response()->json([
            'data' => [
                'riassunto' => $risposta['riassunto'] ?? '',
                'esercizi' => array_values(array_filter(
                    $righe,
                    fn (array $r): bool => in_array($r['id'], $chiesti, true),
                )),
            ],
        ]);
    }

    // ───────────────────────── quota ─────────────────────────

    /**
     * Quanto resta: serve all'app per non proporre funzioni che daranno 429.
     *
     * 🚨 **È il consumo di chi sta chiedendo, non della palestra** (C20). Con
     * il conteggio per palestra, questo endpoint mostrava a ciascuno una barra
     * che si riempiva per colpa di altri — e non c'era niente che potesse
     * farci.
     */
    public function usage(Request $request): JsonResponse
    {
        $utente = $request->user();

        return response()->json(['data' => [
            /*
             * ⚠️ **Le chiavi `*_tokens` restano, e adesso portano chiamate.**
             *
             * Rinominarle romperebbe l'app gia' installata sui telefoni, che le
             * legge per disegnare la barra: quella barra sparirebbe finche' ogni
             * utente non aggiorna. 🚨 Il numero cambia scala — 400 invece di
             * 1.200.000 — ma la barra e' una percentuale, e la percentuale resta
             * giusta.
             *
             * 💡 I nomi nuovi affiancano i vecchi. `G2.5` toglie i vecchi
             * **dopo** che l'app aggiornata e' in giro, non prima.
             */
            'used_tokens' => $this->quota->usedThisMonth($utente),
            'cap_tokens' => $this->quota->capFor($utente),
            'remaining_tokens' => $this->quota->remaining($utente),
            'used_percent' => $this->quota->usedPercent($utente),

            // 🆕 G2 — i nomi veri, e il secondo contatore (D7).
            'used_calls' => $this->quota->usedThisMonth($utente),
            'cap_calls' => $this->quota->capFor($utente),
            'remaining_calls' => $this->quota->remaining($utente),
            'used_photo_calls' => $this->quota->usedThisMonth($utente, conFoto: true),
            'cap_photo_calls' => $this->quota->capFor($utente, conFoto: true),
            'remaining_photo_calls' => $this->quota->remaining($utente, conFoto: true),

            // 🆕 D16 — quanto resta nel portafoglio di chi paga per questa persona.
            'ai_credits' => $this->portafoglio->saldo($utente),

            /*
             * 🆕 16/08 — **il numero che l'app mostra nell'intestazione**.
             *
             * ── 🚨 Sono SOLO i gettoni comprati, e la dotazione del piano NON
             *    si somma piu' ────────────────────────────────────────────
             *
             * Fino alla sera del 16/08 questo campo era `quota rimasta + gettoni
             * comprati`. ⚠️ Sommandoli, l'app mostrava a chi ha un abbonamento
             * **quante chiamate gli restano incluse** — e quel numero, accanto
             * al listino, dice a chiunque sappia fare una divisione che
             * comprare un pacchetto conviene piu' che abbonarsi.
             *
             * 🎯 Decisione del committente: *«per chi ha il piano flat non deve
             * vedere quanti gettoni ha»*. La dotazione inclusa e' un **uso
             * compreso**, non un credito da contare.
             *
             * 💡 Chi ha comprato gettoni continua a vederli, ed e' giusto: quelli
             * li ha pagati a parte, sono suoi, e vuole sapere quanti gliene
             * restano.
             *
             * ── ⚠️ E si mostra SEMPRE, anche a zero — 17/08/2026 ─────────────
             *
             * La prima versione nascondeva il contatore a chi e' abbonato e non
             * ha comprato niente, per non far leggere uno zero accanto a un'AI
             * che funziona. 🚨 Provandolo sul telefono, il committente:
             * *«non si vede piu' il saldo dei miei gettoni [...] si deve vedere
             * anche se ne ho infiniti o 0»*.
             *
             * 💡 Ha ragione: **un contatore che a volte c'e' e a volte no e'
             * peggio di uno zero**. Chi lo cerca e non lo trova pensa che sia
             * rotto, e non ha modo di sapere se e' sparito perche' non serve o
             * perche' non funziona. Uno zero almeno si capisce.
             *
             * 📌 E' il motivo per cui `mostra_gettoni` non c'e' piu': la
             * decisione di mostrarlo non e' del server, e mantenerla li' voleva
             * dire tre condizioni da tenere allineate a niente.
             */
            'gettoni_disponibili' => $this->portafoglio->saldo($utente),

            // 💡 Serve all'app per dire «illimitata» invece di un numero.
            'illimitata' => $this->quota->remaining($utente) === null,
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Il cancello prima di ogni chiamata all'AI — G2, D8 + D16.
     *
     * 🚨 **La regola non vive piu' qui**: sta in `CancelloDeiGettoni`, perche'
     * da N20 non tutte le chiamate all'AI partono da questo controller —
     * l'import dei piani alimentari apre il cancello in HTTP e chiama il
     * modello in coda. Le motivazioni (l'ordine quota→gettoni→402, e perche' la
     * decisione viaggia invece di essere ricontrollata) stanno tutte li'.
     *
     * @return bool se questa chiamata andra' pagata con i gettoni
     *
     * @throws AiQuotaExceededException
     * @throws GettoniEsauritiException
     */
    private function assertQuota(?User $utente, AiFeature $funzione): bool
    {
        return $this->cancello->apri($utente, $funzione);
    }

    /** Scala i gettoni **dopo** che la chiamata e' riuscita. Vedi `CancelloDeiGettoni`. */
    private function consumaGettoniSeServe(?User $utente, AiFeature $funzione, bool $conGettoni): void
    {
        $this->cancello->consuma($utente, $funzione, $conGettoni);
    }

    /**
     * Scrive le voci di una stima. **L'unico punto che le crea**, usato sia da
     * `save: true` sia dalla conferma.
     *
     * 🚨 Sta in un metodo solo perche' le due strade devono produrre voci
     * **identiche**. Duplicare il `create()` significa che un campo aggiunto di
     * la' non arriva di qua, e la differenza si vede settimane dopo come «certe
     * voci AI non si ricalcolano» — che e' letteralmente il difetto #9 del
     * 12/08, nato cosi'.
     *
     * ⚠️ **In transazione**: un pasto e' una cosa sola. Se la terza voce di
     * cinque fallisce, mezza cena in diario e' un totale sbagliato che non
     * dichiara di esserlo.
     *
     * @param  array<string, mixed>  $dati
     * @return list<FoodEntry>
     */
    private function scriviVoci(
        ?User $utente,
        FoodEstimate $stima,
        FoodSource $fonte,
        array $dati,
    ): array {
        if ($utente === null) {
            return [];
        }

        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();

        // Gli orari dei pasti sono quelli di questa persona, non soglie fisse:
        // vedi la nota su `MealType::fromProfile()`.
        $pasto = (isset($dati['meal']) ? MealType::tryFrom((string) $dati['meal']) : null)
            ?? MealType::fromProfile($quando, $utente->profile?->meal_hours);

        return DB::transaction(static function () use ($utente, $stima, $fonte, $quando, $pasto): array {
            $voci = [];

            foreach ($stima->items as $item) {
                $voci[] = FoodEntry::create([
                    'tenant_id' => $utente->tenant_id,
                    'user_id' => $utente->getKey(),
                    'eaten_at' => $quando,
                    'meal' => $pasto,
                    'description' => $item->name,
                    // 🚨 I grammi arrivano dal modello e vincono sulla tabella di
                    // FoodUnit: il modello sa che alimento e', la tabella no.
                    'grams' => $item->grams,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'kcal' => $item->kcal,
                    'protein' => $item->protein,
                    'carbs' => $item->carbs,
                    'fat' => $item->fat,
                    'source' => $fonte,
                    'ai_raw' => $item->toArray(),
                ]);
            }

            return $voci;
        });
    }

    /**
     * Il contesto del consiglio.
     *
     * 🚨 Contiene la **data** (fa scattare la rigenerazione a mezzanotte) e i
     * numeri della giornata (la fanno scattare quando cambiano). Non contiene il
     * nome della persona: non serve al consiglio, e non ha motivo di uscire.
     *
     * @return array<string, mixed>
     */
    /**
     * Il fabbisogno che l'app ha calcolato sul telefono — S8.2.
     *
     * 🚨 **Nasce qui e muore nel prompt.** Non si scrive da nessuna parte:
     * entra nel contesto, viene mandato al modello, e finisce. L'unica traccia
     * che resta e' l'**hash** del contesto — che e' un digest, non un dato.
     *
     * ⚠️ **Si valida comunque.** Il server non ha modo di verificare che quel
     * numero sia vero — il peso da cui nasce non ce l'ha — ma un `target_kcal`
     * di 40.000 nel prompt non produrrebbe un errore: produrrebbe un
     * **consiglio alimentare assurdo**, detto con la stessa sicurezza di uno
     * giusto. I limiti sono gli stessi del pavimento e del tetto che
     * `CalorieCalculator` applica da sempre.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Le calorie bruciate, **mandate dall'app** — FASE 11.6, 21/08/2026.
     *
     * == 🚨 IL SERVER NON LE HA PIU' =======================================
     *
     * 📌 Il committente: *«Nessun allenamento deve risiedere sul server, devono
     * stare tutti nell'app»*. Sedute, serie e dichiarazioni a mano stanno
     * nell'archivio del telefono.
     *
     * ⚠️ Prima venivano da `DiaryService::forDate()`, che le calcolava da
     * `workout_sessions` e `daily_burns`. 🚨 Togliendole senza sostituirle, il
     * consiglio del giorno avrebbe detto a chi si e' allenato due ore che non
     * si e' mosso — e lo avrebbe detto **con la stessa sicurezza** di un
     * consiglio giusto.
     *
     * -- ⚠️ Perche' un tetto, e perche' cosi' basso ------------------------
     *
     * E' la stessa difesa di [targetDallApp]: questo numero finisce **dentro un
     * prompt**, e un valore assurdo non produce un errore — produce un
     * consiglio assurdo. 🚨 6.000 kcal bruciate in un giorno non le fa nessuno:
     * il vincitore del Tour de France ne fa 8.000 in una tappa di montagna.
     *
     * 💡 `null` quando l'app non lo manda: il prompt sa gia' distinguere «zero»
     * da «non lo so», ed e' la distinzione che regge mezza applicazione.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Da quanto ci si allena, **secondo il telefono** — FASE 11.6.
     *
     * 🚨 *«Non ti alleni da 5 giorni»* e' la frase che fa tornare in palestra, e
     * il consiglio del giorno la usa. ⚠️ Con i due conteggi mancanti direbbe
     * «non ti alleni da sempre» a chi si e' allenato ieri.
     *
     * 💡 Il tetto e' la solita difesa da prompt: `days_since_last` oltre l'anno
     * non aggiunge niente, e `last_30_days` sopra 30 e' impossibile per
     * costruzione.
     *
     * @return array<string, mixed>
     */
    private function allenamentoDallApp(Request $request): array
    {
        $dati = $request->validate([
            'training_last_30_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'training_days_since_last' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        if (($dati['training_last_30_days'] ?? null) === null
            && ($dati['training_days_since_last'] ?? null) === null) {
            return [];
        }

        return [
            'training' => [
                'last_30_days' => $dati['training_last_30_days'] ?? 0,

                // ⛔ `null` = «non si e' mai allenato», che e' un'altra cosa da
                // «oggi»: il prompt le tratta diversamente.
                'days_since_last' => $dati['training_days_since_last'] ?? null,
            ],
        ];
    }

    private function bruciateDallApp(Request $request): ?array
    {
        $dati = $request->validate([
            'burned_kcal' => ['nullable', 'integer', 'min:0', 'max:6000'],
        ]);

        if (($dati['burned_kcal'] ?? null) === null) {
            return null;
        }

        return [
            'kcal' => (int) $dati['burned_kcal'],

            // 🚨 Dice al modello che il numero arriva dal telefono e non da un
            // calcolo nostro: e' la stessa onesta' di `targetDallApp`.
            'source' => 'app',
        ];
    }

    /**
     * I pasti di oggi con l'ora in cui sono stati scritti, e la settimana.
     *
     * ══ 🚨 PERCHE' SERVE L'ORA IN CUI SONO STATI **SCRITTI** ══════════════
     *
     * 📌 *«se oggi ho gia' segnato tutto quello che mangero' alle 10 di mattina
     * il consiglio del giorno mi dice che ho gia' assunto 1800 kcal e sono solo
     * le 10»*.
     *
     * ⛔ **`eaten_at` non serve a niente per questo**, ed e' la scoperta che ha
     * deciso la forma di questo metodo: l'app manda
     * `eaten_at = selectedDate.toIso8601String()`, cioe' la **mezzanotte** del
     * giorno che si sta guardando. Tutte le voci di oggi hanno la stessa ora, e
     * quell'ora e' 00:00.
     *
     * 💡 `created_at` invece e' l'istante vero in cui la riga e' stata
     * scritta. Una cena con `created_at` alle 10:14 e' cibo **programmato**;
     * una cena scritta alle 21:30 e' cibo mangiato.
     *
     * ⚠️ **Si prende la piu' RECENTE fra le voci del pasto**, non la prima:
     * chi aggiunge il pane alla cena alle 21:40 sta ancora cenando, e l'ora
     * che conta e' quella dell'ultimo gesto.
     *
     * ══ 📅 E LA SETTIMANA, DALLA STESSA QUERY ════════════════════════════
     *
     * 🚨 **Una lettura sola per due risposte.** Due query — una per oggi e una
     * per la settimana — sarebbero due filtri sullo stesso intervallo, cioe'
     * due occasioni di divergere sul confine del giorno. E il confine del
     * giorno in questo progetto e' gia' costato il difetto A3.
     *
     * 💡 Il raggruppamento si fa in PHP con `GiornoLocale::etichettaDi()` e non
     * con un `GROUP BY DATE(eaten_at)`: quel `DATE()` raggrupperebbe in **UTC**,
     * e una cena delle 00:30 finirebbe nel giorno prima.
     *
     * @return array{meals: list<array<string, mixed>>, week_food: list<array<string, mixed>>}
     */
    private function laSettimanaDelCibo(User $utente, GiornoLocale $oggi): array
    {
        $fuso = $utente->fusoOrario();
        $primo = $oggi->menoGiorni(self::GIORNI_DI_STORIA);

        $voci = FoodEntry::query()
            ->forUser($utente)
            ->whereBetween('eaten_at', $primo->finestraFinoA($oggi))
            ->orderBy('eaten_at')
            ->get();

        $perGiorno = [];

        foreach ($voci as $v) {
            if ($v->eaten_at === null) {
                continue;
            }

            $perGiorno[GiornoLocale::etichettaDi($v->eaten_at, $fuso)][] = $v;
        }

        // ── i pasti di oggi ────────────────────────────────────────────────

        $perPasto = [];

        foreach ($perGiorno[$oggi->etichetta] ?? [] as $v) {
            $perPasto[$v->meal->value][] = $v;
        }

        $pasti = [];

        foreach (MealType::ordered() as $tipo) {
            $righe = $perPasto[$tipo->value] ?? [];

            if ($righe === []) {
                continue;
            }

            $totali = FoodEntry::totals($righe);

            /*
             * 🚨 **Il massimo, non l'ultimo dell'elenco**: le voci sono
             * ordinate per `eaten_at`, che qui vale mezzanotte per tutte —
             * quindi l'ordine dell'elenco non dice niente sull'ora di
             * scrittura.
             */
            $scritto = null;

            foreach ($righe as $r) {
                if ($r->created_at !== null && ($scritto === null || $r->created_at->gt($scritto))) {
                    $scritto = $r->created_at;
                }
            }

            $pasti[] = [
                'meal' => $tipo->value,
                'kcal' => (int) round($totali['kcal']),
                'p' => (int) round($totali['protein']),
                'c' => (int) round($totali['carbs']),
                'f' => (int) round($totali['fat']),

                // ⚠️ L'ora **locale**: in UTC il modello leggerebbe le 08:14
                // per una cena scritta alle 10:14 a Roma, e il ragionamento
                // sul «e' presto» partirebbe da un'ora sbagliata.
                'scritto_alle' => $scritto?->copy()->setTimezone($fuso)->format('H:i'),
            ];
        }

        // ── la settimana, senza oggi ───────────────────────────────────────

        $settimana = [];

        foreach ($perGiorno as $giorno => $righe) {
            /*
             * ⛔ **Oggi non entra nella settimana**: e' gia' in `totals` e in
             * `meals`, e ripeterlo darebbe al modello due versioni della stessa
             * giornata — una completa e una da confrontare con le altre.
             */
            if ($giorno === $oggi->etichetta) {
                continue;
            }

            $t = FoodEntry::totals($righe);

            $settimana[] = [
                'd' => $giorno,
                'kcal' => (int) round($t['kcal']),
                'p' => (int) round($t['protein']),
                'c' => (int) round($t['carbs']),
                'f' => (int) round($t['fat']),
            ];
        }

        /*
         * 💡 Dal piu' recente: il modello legge le altre serie della settimana
         * cosi' (`week_sleep`, `week_workouts`), e due ordini diversi nello
         * stesso contesto sono un modo gratuito di far sbagliare i confronti.
         */
        usort($settimana, static fn (array $a, array $b): int => strcmp($b['d'], $a['d']));

        return ['meals' => $pasti, 'week_food' => $settimana];
    }

    /**
     * TDEE, peso e altezza: quello che il server **non ha piu'** — 31/08/2026.
     *
     * 📌 Il committente: *«Deve capire il target calorico mio, il mio tdee, il
     * mio peso e il mio obbiettivo»*.
     *
     * ══ 🚨 QUESTO CAMBIA UNA DECISIONE DI S5, E VA SAPUTO ═════════════════
     *
     * `targetDallApp()` porta ancora scritto: *«Si manda solo il risultato, non
     * il peso. Il target e' un numero derivato; il peso da cui nasce resta su
     * questo telefono, che e' il punto di tutta la fase S5»*.
     *
     * ⚠️ **Da oggi il peso parte.** E' una richiesta esplicita del committente,
     * ed e' scritta qui perche' nessuno la scopra leggendo il commento di
     * sopra e la creda un difetto.
     *
     * 💡 **Il perimetro pero' non cambia**: il server lo **inoltra e non lo
     * conserva**, esattamente come fa da 16/08 con sonno, HRV e battito a
     * riposo — che sono dati dell'art. 9, cioe' molto piu' delicati di un peso.
     * Non c'e' nessuna colonna, nessuna tabella, nessun log che lo trattenga.
     *
     * ⛔ **E resta una lista bianca**: quello che l'app manda e non e' qui
     * dentro non parte. Ogni campo qui ha una riga nel prompt che dice come si
     * legge; uno che il prompt non nomina sarebbe un dato in piu' mandato per
     * niente, cioe' l'art. 5(1)(c) violato per distrazione.
     *
     * @return array<string, mixed>
     */
    private function corpoDallApp(Request $request): array
    {
        $dati = $request->validate([
            'tdee_kcal' => ['nullable', 'numeric', 'min:800', 'max:8000'],

            /*
             * ⚠️ Gli estremi sono larghi apposta: servono a fermare uno zero di
             * troppo o un valore in libbre, non a decidere chi puo' pesare
             * quanto. Un intervallo stretto qui sarebbe un giudizio sul corpo
             * scritto in una regola di validazione.
             */
            'weight_kg' => ['nullable', 'numeric', 'min:25', 'max:400'],
        ]);

        $fuori = [];

        if (($dati['tdee_kcal'] ?? null) !== null) {
            $fuori['tdee_kcal'] = (int) round((float) $dati['tdee_kcal']);
        }

        if (($dati['weight_kg'] ?? null) !== null) {
            $fuori['weight_kg'] = round((float) $dati['weight_kg'], 1);
        }

        return $fuori;
    }

    private function targetDallApp(Request $request): ?array
    {
        $dati = $request->validate([
            'target_kcal' => ['nullable', 'numeric', 'min:1000', 'max:8000'],
            'target_protein_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'target_carbs_g' => ['nullable', 'numeric', 'min:0', 'max:1200'],
            'target_fat_g' => ['nullable', 'numeric', 'min:0', 'max:400'],
        ]);

        if (($dati['target_kcal'] ?? null) === null) {
            return null;
        }

        return [
            'kcal' => (float) $dati['target_kcal'],
            'protein_g' => isset($dati['target_protein_g']) ? (float) $dati['target_protein_g'] : null,
            'carbs_g' => isset($dati['target_carbs_g']) ? (float) $dati['target_carbs_g'] : null,
            'fat_g' => isset($dati['target_fat_g']) ? (float) $dati['target_fat_g'] : null,

            // 🚨 Dice al modello **da dove viene il numero**, e non e' un
            // dettaglio: «calcolato sui tuoi dati» e «prescritto dal tuo
            // trainer» meritano un tono diverso, e il secondo non si discute.
            'source' => 'app',
        ];
    }

    /**
     * Cosa il modello deve **sapere** ma che NON deve far rigenerare il consiglio.
     *
     * ── 🚨 Il difetto, riferito provando l'app il 12/08/2026 ─────────────────
     *
     * *«Sulla pagina Oggi non mi mostra sempre il consiglio del giorno. Io direi
     * che una volta salvato lo dovrebbe mostrare sempre finché non cambia
     * qualcosa (allenamento, calorie inserite a mano, sonno o pasti).»*
     *
     * `time` cambia **ogni minuto** e `day_progress_pct` quasi altrettanto:
     * finché entravano nell'hash, **ogni apertura della schermata era una cache
     * miss**, cioè una chiamata AI nuova. Il consiglio non era «a volte
     * assente»: era ogni volta diverso, e quando la quota finiva o la chiamata
     * tardava spariva del tutto.
     *
     * 💡 **Restano nel prompt.** L'ora serve al modello — 3.000 kcal alle dieci
     * del mattino e 3.000 a fine giornata sono due situazioni opposte, e senza
     * l'ora il consiglio è *sbagliato*, non generico. Ma sapere che ore sono e
     * **rifare il consiglio perché è passato un minuto** sono due cose diverse.
     *
     * ⚠️ Quindi il consiglio si rigenera quando cambia qualcosa di **vero**:
     * pasti, allenamenti, calorie bruciate dichiarate, obiettivo. Sono tutti
     * dentro `totals`, `burned` e `targets`, che restano nell'hash.
     *
     * 🚨 **Il sonno NON può essere un innesco, ed è una conseguenza della
     * decisione D9**: dopo S1 il server non riceve più i dati del sensore, e
     * quello che non ha non può nemmeno accorgersi che è cambiato. Rigenerare
     * per un sonno che il modello non vedrà comunque sarebbe una chiamata a
     * vuoto.
     *
     * @var list<string>
     */
    private const VOLATILI = ['time', 'day_progress_pct', 'now'];

    /**
     * Quanto vive il lucchetto del consiglio, in secondi — FASE 2-septies.
     *
     * 🚨 **Deve superare la generazione piu' lenta.** Se scade mentre il
     * primo sta ancora parlando col modello, il secondo entra e la corsa torna:
     * cioe' il lucchetto sembrerebbe esserci e non servirebbe a niente.
     *
     * ⚠️ Che sia piu' lungo del timeout del client (30s) **non e' un problema
     * qui**, e vale la pena scriverlo perche' sembra esserlo: nessuno aspetta il
     * lucchetto per tutta la sua durata: si aspetta al massimo `LUCCHETTO_ATTESA`
     * e poi si va avanti comunque. Il TTL serve solo a **liberare** la chiave se
     * il processo che la teneva e' morto.
     */
    private const LUCCHETTO_SECONDI = 60;

    /**
     * Quanto si aspetta il proprio turno, in secondi.
     *
     * 💡 Tarato sul timeout del client: 12s di attesa piu' una generazione
     * stanno dentro i 30s di `receiveTimeout` dell'app. ⚠️ Chi scade **non
     * fallisce**: genera per conto suo, e al peggio si ricade nel caso di prima
     * — che ora il `catch` sul duplicato copre.
     */
    private const LUCCHETTO_ATTESA = 12;

    /**
     * Il contesto ridotto a **ciò che deve invalidare la cache**.
     *
     * @param  array<string, mixed>  $contesto
     * @return array<string, mixed>
     */
    private static function chiaveDiCache(array $contesto): array
    {
        return Arr::except($contesto, self::VOLATILI);
    }

    /**
     * I campi del recupero che si accettano, e **nessun altro**.
     *
     * 🚨 **E' una lista bianca, non un elenco di esempi.** Quello che l'app manda
     * e non e' qui dentro **non parte**: senza questa riga il contesto sarebbe
     * «tutto cio' che il telefono ha voglia di allegare», e la prima versione
     * dell'app che aggiunge un campo lo spedirebbe a un modello senza che
     * nessuno l'abbia deciso.
     *
     * 💡 I due `baseline` non sono dati in piu': sono quello che rende leggibili
     * gli altri due. Un HRV di 40 non vuol dire niente da solo — vuol dire
     * qualcosa solo contro la media di quella persona, ed e' scritto anche nel
     * prompt (regola 10).
     *
     * @var array<string, string> campo => tipo atteso
     */
    /**
     * Le serie della settimana, e **la forma esatta di ogni voce** — 20/08/2026.
     *
     * ── 🚨 Perche' una lista bianca ANCHE sulle chiavi interne ────────────
     *
     * `RECUPERO` e' una lista bianca sui nomi di primo livello, e bastava
     * finche' i valori erano numeri. ⚠️ Qui arrivano **elenchi di oggetti**, e
     * un elenco e' un posto in cui si puo' infilare qualunque cosa: senza
     * questa tabella, «quello che il telefono ha voglia di allegare» tornerebbe
     * dalla finestra dopo essere stato cacciato dalla porta.
     *
     * 💡 Ogni voce viene ricostruita campo per campo: quello che non e' qui
     * dentro non passa, e quello che c'e' passa **con il tipo giusto**.
     *
     * @var array<string, array<string, string>>
     */
    private const SETTIMANA = [
        'week_sleep' => [
            'day' => 'string',
            'hours' => 'float',
            'deep_min' => 'int',
            'rem_min' => 'int',
            'awake_min' => 'int',
        ],
        'week_hrv' => ['day' => 'string', 'v' => 'int'],
        'week_resting_hr' => ['day' => 'string', 'v' => 'int'],
        'week_workouts' => [
            'day' => 'string',
            'minutes' => 'int',
            'type' => 'string',
            'kcal' => 'int',
        ],
    ];

    /**
     * ⚠️ Sette giorni di dati sono al massimo una decina di voci per serie. Il
     * tetto serve a un client modificato, non all'uso normale.
     */
    private const VOCI_AL_MASSIMO = 30;

    /**
     * Quanti giorni indietro guarda il consiglio.
     *
     * 📌 *«diciamo tutta la settimana»*.
     *
     * 💡 Sette e non trenta: serve a dire se **oggi** e' diverso dal solito di
     * questa persona, e per quello una settimana basta. ⚠️ Un mese di righe nel
     * contesto costerebbe quattro volte tanto per rispondere alla stessa
     * domanda — e i modelli, su elenchi lunghi, guardano le estremita'.
     */
    private const GIORNI_DI_STORIA = 7;

    /**
     * Le serie della settimana che **non vengono dal sensore** — 31/08/2026.
     *
     * ══ 🚨 PERCHE' UNA COSTANTE A PARTE, E NON DUE RIGHE IN `SETTIMANA` ═══
     *
     * Perche' `settimanaDallApp()` e' chiusa dietro `sleep_ai_consent_at`, che
     * e' il consenso ai dati del **sensore** — sonno, HRV, battito, roba
     * dell'art. 9.
     *
     * ⛔ Mettere le calorie bruciate li' dentro vorrebbe dire che chi non ha
     * dato quel consenso perde anche il quadro delle calorie della settimana:
     * **due cose diverse spente dallo stesso interruttore**, e nessuno
     * capirebbe perche'.
     *
     * ⚠️ Restano comunque dietro `ai_consent_at`, che il middleware `ai.consent`
     * pretende prima di arrivare qui.
     *
     * @var array<string, array<string, string>>
     */
    private const SETTIMANA_DEL_CORPO = [
        // Le calorie bruciate **del giorno intero**, come le mostra l'app.
        'week_burned' => ['day' => 'string', 'v' => 'int'],

        // Il peso, quando c'e' una pesata.
        'week_weight' => ['day' => 'string', 'v' => 'float'],
    ];

    private const RECUPERO = [
        'hours' => 'float',
        'quality' => 'string',
        'wakings' => 'int',
        'deep_min' => 'int',
        'rem_min' => 'int',
        'hrv_ms' => 'int',
        'hrv_baseline_ms' => 'int',
        'resting_hr' => 'int',
        'resting_hr_baseline' => 'int',
    ];

    /**
     * Il recupero nel contesto del consiglio — 16/08/2026.
     *
     * ── 🚨 Perche' arriva DALL'APP e non da una tabella ────────────────────
     *
     * Perche' una tabella non c'e', ed e' voluto: sonno, battito e variabilita'
     * vivono nell'archivio locale del telefono (D9), e il server non li
     * conserva. E' la stessa strada gia' battuta dal target in
     * `targetDallApp()` — **il server inoltra, non tiene**.
     *
     * 💡 E risolve un conflitto che sembrava grosso: il consiglio non puo'
     * essere generato da un job del server, perche' il server questi dati non
     * ce li ha. Lo chiede l'app, al massimo una volta per fascia — e cosi' il
     * tetto di tre al giorno resta, senza nessuno schedulatore.
     *
     * ── ⚠️ Le due condizioni, ed ENTRAMBE servono ─────────────────────────
     *
     * | | |
     * |---|---|
     * | `ai_consent_at` | ci pensa il middleware `ai.consent`, prima di qui |
     * | `sleep_ai_consent_at` | 🚨 **si controlla qui**, ed e' un consenso a parte |
     *
     * Chi ha detto si' all'AI ma non al recupero riceve il consiglio **senza**
     * questa parte, e il prompt (regola 9) sa comportarsi di conseguenza. Un
     * consenso a pacchetto sarebbe vietato dall'art. 7 — vedi §C12 di
     * `todo-2026-08-11.md`, il documento che il vecchio commento in
     * `contestoConsiglio()` imponeva di leggere prima di riaprire questa porta.
     *
     * ── 💡 Perche' TUTTO il quadro e non solo le ore ──────────────────────
     *
     * Decisione del committente, 16/08: *«che senso ha farlo senza tutto il
     * contesto»*. Ha ragione su un punto che vale la pena scrivere: **il passo
     * legale e' gia' fatto mandando le ore.** Sonno, HRV e battito stanno nella
     * stessa categoria dell'art. 9, chiedono lo stesso consenso e gli stessi
     * documenti. Mandarne meta' avrebbe dato un consiglio peggiore a parita' di
     * esposizione.
     *
     * ⚠️ Ma «tutto il quadro» resta **una lista chiusa** (`self::RECUPERO`): la
     * minimizzazione dell'art. 5(1)(c) si misura sull'uso, e ogni campo qui
     * dentro ha una riga nel prompt che dice come si legge. Un campo che il
     * prompt non nomina sarebbe dato in piu' mandato per niente.
     *
     * @return array<string, mixed>
     */
    private function recuperoDallApp(Request $request): array
    {
        $utente = $request->user();

        if ($utente?->sleep_ai_consent_at === null) {
            return [];
        }

        $recupero = [];

        foreach (self::RECUPERO as $campo => $tipo) {
            $valore = $request->input($campo);

            // ⚠️ Un valore che non e' un numero si **scarta**, non si inoltra:
            // meglio un consiglio senza quel campo che un prompt con dentro una
            // parola al posto di una cifra.
            if ($valore === null || ($tipo !== 'string' && ! is_numeric($valore))) {
                continue;
            }

            $recupero[$campo] = match ($tipo) {
                'float' => round((float) $valore, 1),
                'int' => (int) $valore,
                default => mb_substr((string) $valore, 0, 32),
            };
        }

        // 🚨 Senza le ore il resto non si legge: un HRV da solo non dice se la
        // notte e' stata corta. Se manca il minimo, non parte niente.
        if (! isset($recupero['hours'])) {
            return [];
        }

        return ['recovery' => $recupero];
    }

    /**
     * Le serie della settimana che manda il telefono — 20/08/2026.
     *
     * ── 🚨 Il difetto che chiudono ────────────────────────────────────────
     *
     * Il consiglio riceveva **una notte sola** e nessun allenamento
     * dell'orologio. ⚠️ Il committente l'ha visto subito: *«non vede il mio
     * allenamento di ieri (dice che non mi alleno da un po')»* e *«non e' vero
     * che di solito dormo bene»*.
     *
     * 💡 Erano due sintomi della stessa cosa: **un modello a cui manca il
     * contesto non tace, lo inventa**. «Dormi bene di solito» era l'unica frase
     * possibile per chi non ha mai visto le altre sei notti.
     *
     * ⚠️ **Stesso consenso del recupero** (`sleep_ai_consent_at`): sono dati
     * sanitari letti dal telefono che partono verso un modello, ed e'
     * esattamente cio' che quel consenso copre.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function settimanaDallApp(Request $request): array
    {
        if ($request->user()?->sleep_ai_consent_at === null) {
            return [];
        }

        return $this->serieDallApp($request, self::SETTIMANA);
    }

    /**
     * Bruciate e peso della settimana — 31/08/2026.
     *
     * 📌 *«anche i giorni passati (diciamo tutta la settimana) di tutti i dati
     * che passo»*.
     *
     * 🚨 **Non passa da `settimanaDallApp()`**, e la ragione sta nella nota di
     * `SETTIMANA_DEL_CORPO`: quel metodo e' chiuso dietro il consenso al
     * **sonno**, e le calorie bruciate con il sonno non c'entrano niente.
     */
    private function settimanaDelCorpoDallApp(Request $request): array
    {
        return $this->serieDallApp($request, self::SETTIMANA_DEL_CORPO);
    }

    /**
     * Il setaccio delle serie: **una sede sola**.
     *
     * ⚠️ Era dentro `settimanaDallApp()`. Copiarlo per le serie nuove avrebbe
     * voluto dire due sanificatori per lo stesso genere di dato, e quello che
     * diverge per primo e' sempre la copia — cioe' quella meno provata.
     *
     * @param  array<string, array<string, string>>  $forme
     * @return array<string, mixed>
     */
    private function serieDallApp(Request $request, array $forme): array
    {
        $fuori = [];

        foreach ($forme as $serie => $forma) {
            $grezza = $request->input($serie);

            if (! is_array($grezza)) {
                continue;
            }

            $voci = [];

            foreach ($grezza as $voce) {
                if (count($voci) >= self::VOCI_AL_MASSIMO) {
                    break;
                }

                if (! is_array($voce)) {
                    continue;
                }

                $pulita = [];

                foreach ($forma as $campo => $tipo) {
                    $v = $voce[$campo] ?? null;

                    if ($v === null) {
                        continue;
                    }

                    if ($tipo !== 'string' && ! is_numeric($v)) {
                        continue;
                    }

                    $pulita[$campo] = match ($tipo) {
                        'float' => round((float) $v, 1),
                        'int' => (int) $v,
                        // ⚠️ Tagliato corto: `type` e `day` sono etichette, e
                        // 32 caratteri bastano per «Nuoto in acque libere».
                        default => mb_substr((string) $v, 0, 32),
                    };
                }

                /*
                 * 💡 Una voce senza `day` non serve a niente: il modello legge
                 * queste serie **come una sequenza nel tempo**, e un valore
                 * senza giorno non si puo' mettere in fila.
                 */
                if (isset($pulita['day']) && count($pulita) > 1) {
                    $voci[] = $pulita;
                }
            }

            if ($voci !== []) {
                $fuori[$serie] = $voci;
            }
        }

        return $fuori;
    }

    /**
     * Quanti tipi di allenamento si accettano dall'app, al massimo.
     *
     * 💡 Sette giorni di allenamenti sono al massimo una decina. Il tetto non
     * serve a limitare l'uso normale: serve perche' senza, un client modificato
     * potrebbe allegare mille voci a una richiesta che poi finisce in un prompt
     * pagato a token.
     */
    private const TIPI_AL_MASSIMO = 20;

    /**
     * Il **tipo** degli allenamenti, che lo sa solo il telefono — 20/08/2026.
     *
     * ── 🚨 Perche' arriva dall'app e non dal database ─────────────────────────
     *
     * Perche' sul server il tipo **non esiste**: `workout_sessions` ha date,
     * calorie e note, `workout_plans` ha un nome. ⚠️ L'unico posto dove esiste
     * «Pesi» e' l'orologio, che scrive `STRENGTH_TRAINING` in Health Connect, e
     * quello sta sul telefono.
     *
     * 📌 Richiesta del committente: *«il tipo di allenamento deve partire: se il
     * mio allenamento e' Pesi questo deve passare»*.
     *
     * ── ══ 🚨 PARTE IL CODICE, NON UN'ETICHETTA ══ ────────────────────────────
     *
     * `STRENGTH_TRAINING`, `RUNNING`, `BIKING`: **vocabolario chiuso**, in
     * maiuscolo e senza spazi. E' la ragione per cui questa strada e' stata
     * scelta al posto del nome della scheda.
     *
     * ⚠️ Il nome della scheda e' **testo libero**: il giorno che qualcuno chiama
     * una scheda «Riabilitazione spalla — fase 2», quel nome partirebbe verso un
     * modello e nessuna riga di codice potrebbe distinguerlo da «Pesi». Un
     * codice `[A-Z_]+` non puo' contenere quella frase — non per convenzione, ma
     * perche' la validazione la rifiuta.
     *
     * 💡 **La regex E' la garanzia**, non un controllo di forma: e' quello che
     * rende vera la promessa scritta in T4 e in \S3.4 dell'informativa.
     *
     * ── ⚠️ Ed e' una lista bianca, come il recupero ───────────────────────────
     *
     * Chiave = `id` della seduta, valore = codice. Quello che l'app manda e non
     * corrisponde a una seduta di questa settimana **non parte**: senza, il
     * contesto sarebbe «tutto cio' che il telefono ha voglia di allegare».
     *
     * @return array<int, string>
     */
    private function tipiDallApp(Request $request): array
    {
        /*
         * 🚨 Lo stesso consenso del recupero, e non e' una scorciatoia: e' un
         * dato sanitario letto dal telefono che parte verso un modello, cioe'
         * esattamente la cosa che quel consenso copre.
         */
        if ($request->user()?->sleep_ai_consent_at === null) {
            return [];
        }

        $grezzi = $request->input('training_types');

        if (! is_array($grezzi)) {
            return [];
        }

        $fuori = [];

        foreach ($grezzi as $idSeduta => $codice) {
            if (count($fuori) >= self::TIPI_AL_MASSIMO) {
                break;
            }

            if (! is_numeric($idSeduta) || ! is_string($codice)) {
                continue;
            }

            // ⚠️ Qui casca il nome della scheda, e deve cascare.
            if (preg_match('/^[A-Z_]{2,48}$/', $codice) !== 1) {
                continue;
            }

            $fuori[(int) $idSeduta] = $codice;
        }

        return $fuori;
    }

    private function contestoConsiglio(Request $request): array
    {
        $utente = $request->user();
        $adesso = Carbon::now();
        $oggi = $utente->giornoDiOggi($adesso);

        $giornata = $this->diary->forDate($utente, $oggi);
        $riepilogo = $this->dashboard->forToday($utente, $adesso);
        $cibo = $this->laSettimanaDelCibo($utente, $oggi);

        return [
            'date' => $oggi->etichetta,

            /*
             * 🚨 **L'ORA È PARTE DEL CONTESTO, non un dettaglio.**
             *
             * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono
             * due situazioni opposte: la prima è una giornata che sta per
             * andare fuori controllo e su cui si può ancora intervenire, la
             * seconda è una giornata chiusa su cui l'unico consiglio sensato
             * riguarda domani. Senza l'ora il modello dà lo stesso consiglio in
             * entrambi i casi, e in uno dei due è **sbagliato** — non generico:
             * sbagliato.
             *
             * `day_progress_pct` è la quota di giornata sveglia già passata
             * (dalle 6 alle 23), che è il riferimento giusto per confrontare le
             * calorie assunte con il target.
             *
             * ⚠️ **Ma NON entra nell'hash della cache** — vedi `VOLATILI`.
             * Finché ci entrava, ogni apertura della schermata era una chiamata
             * AI nuova, perché `time` cambia ogni minuto. Sapere che ore sono e
             * **rifare il consiglio perché è passato un minuto** sono due cose
             * diverse.
             */
            /*
             * ⚠️ **L'ora LOCALE** — A3. In UTC il modello riceveva «18:00» per
             * una cena delle 20 a Roma, e consigliava di stare leggeri a chi
             * aveva gia' finito di mangiare. Il consiglio non era generico: era
             * sbagliato, e sembrava soltanto poco azzeccato.
             */
            'time' => $adesso->copy()->setTimezone($utente->fusoOrario())->format('H:i'),
            'day_progress_pct' => $riepilogo['day_progress_pct'],

            'totals' => $giornata['totals'],

            /*
             * 🚨 **Il target puo' arrivare DALL'APP, e di solito e' l'unico
             * modo** — S8.2.
             *
             * Da S5 il peso non sta piu' sul server, quindi
             * `Profile::computedTargets()` non calcola piu' niente e
             * `$giornata['targets']` e' **`null` per chiunque non abbia un
             * piano alimentare attivo**. Senza questo, il consiglio del giorno
             * era muto per tutti: il modello si ritrovava le calorie assunte
             * senza nessun numero con cui confrontarle.
             *
             * 🚨 **Il server lo INOLTRA, non lo conserva.** Finisce nel prompt e
             * nell'hash del contesto, e da li' sparisce: non c'e' nessuna
             * colonna, nessuna tabella, nessun log che lo trattenga. La
             * differenza fra «passare per» e «tenere» e' tutta la fase S5.
             *
             * ⚠️ **Il piano del trainer vince comunque.** Se `targets` c'e' gia'
             * — cioe' se un piano alimentare e' attivo — quello che manda l'app
             * si ignora: e' la stessa precedenza dell'interfaccia (D8), e
             * invertirla qui darebbe un consiglio costruito su un numero
             * diverso da quello che la persona ha davanti agli occhi.
             */
            'targets' => $giornata['targets'] ?? $this->targetDallApp($request),
            'burned' => $this->bruciateDallApp($request),
            'meals_logged' => count(array_filter(
                $giornata['meals'],
                static fn (array $m): bool => $m['entries'] !== [],
            )),
            'goal' => $utente->profile?->goalForFormula(),

            /*
             * ══ 🍽️ I PASTI UNO PER UNO, E QUANDO SONO STATI SCRITTI ═══════
             *
             * 📌 Il committente, il 31/08/2026: *«Io sono uno che si programma
             * i pasti e se oggi ho gia' segnato tutto quello che mangero' alle
             * 10 di mattina il consiglio del giorno mi dice che ho gia' assunto
             * 1800 kcal e sono solo le 10... e' ovvio che non puo' essere
             * cosi'»*.
             *
             * ⛔ **E il modello non aveva modo di accorgersene.** Riceveva
             * `totals` (un totale di giornata) e `meals_logged` (un conteggio):
             * da li' una cena scritta alle 10 del mattino e' identica a una
             * cena mangiata. 🚨 Il consiglio non era generico — era **falso**,
             * e detto con sicurezza.
             *
             * 💡 Da qui i due campi che rendono la distinzione possibile: **che
             * pasto e'** e **a che ora e' stato scritto**. Un pranzo scritto
             * alle 10:14 e' cibo programmato; una cena scritta alle 21:30 e'
             * cibo mangiato. Il resto lo fa la regola 7-bis del prompt.
             *
             * ⚠️ **La distinzione la fa il modello, non il codice**, e non e'
             * pigrizia: «a che ora si cena» e' un giudizio, non un dato. ⛔
             * Scrivere in PHP che la cena e' dopo le 19 vorrebbe dire decidere
             * per chi lavora di notte, e sbagliare in silenzio.
             */
            'meals' => $cibo['meals'],

            /*
             * ══ 📅 LA SETTIMANA DEL CIBO ══════════════════════════════════
             *
             * 📌 *«anche i giorni passati (diciamo tutta la settimana) di tutti
             * i dati che passo (anche in forma compressa, per non aumentare
             * troppo il costo della chiamata)»*.
             *
             * 💡 **Compressa davvero**: un giorno per riga, quattro numeri
             * interi, chiavi di una lettera. Sette giorni costano una manciata
             * di token — meno di quanto costava una singola rigenerazione di
             * troppo delle sei che facevamo al giorno prima di 3b-AB.
             *
             * 🚨 **La calcola il server**, che `food_entries` ce l'ha: farla
             * mandare al telefono sarebbe una seconda sede della stessa
             * risposta, e la copia e' quella che diverge.
             */
            'week_food' => $cibo['week_food'],

            /*
             * ── Il resto della persona ────────────────────────────────────
             *
             * 🚨 **Qui c'erano `sleep` e `vitals`, e sono stati tolti in S1.5.**
             *
             * Non per risparmiare token: perche' quei dati **non escono piu' dal
             * telefono di chi li produce** (decisione D9). Mandarli ad Anthropic
             * o a OpenAI era un trasferimento di dati sanitari verso gli Stati
             * Uniti, e questo piano esiste per farlo sparire alla radice invece
             * di gestirlo con contratti e clausole.
             *
             * ⚠️ Il consiglio ragiona ora **solo su cibo e allenamento**, che
             * non sono dati del corpo. E' un consiglio meno informato, ed e' una
             * perdita accettata consapevolmente: vedi §C11 di
             * `todo-2026-08-11.md`.
             *
             * 🚨 Chi volesse rimetterli deve prima leggere §C12, dove c'e' la
             * ragione legale per cui non ci sono.
             */
            /*
             * ══ 🚨 L'ALLENAMENTO LO MANDA L'APP — FASE 11.6, 21/08/2026 ═══
             *
             * ⚠️ Questo blocco nasceva da `$riepilogo['training']`, cioe' da
             * `workout_sessions`. Dopo il trasloco il server le sedute non ce
             * le ha piu'.
             *
             * 🚨 **E `this_week` era gia' un doppione**: l'app manda
             * `week_workouts` da 20/08 (vedi `SETTIMANA`), con giorno, minuti,
             * tipo e calorie. Il server ricostruiva la stessa settimana dalle
             * sue righe, e le due versioni potevano gia' divergere.
             *
             * 💡 Restano i due conteggi, che ora arrivano anche loro dal
             * telefono: sono l'unica cosa che `week_workouts` non dice.
             */
            ...$this->allenamentoDallApp($request),

            'body' => $riepilogo['body'],

            /*
             * 🆕 31/08/2026 — TDEE, peso e obiettivo.
             *
             * 📌 *«Deve capire il target calorico mio, il mio tdee, il mio peso
             * e il mio obbiettivo»*.
             */
            ...$this->corpoDallApp($request),

            // 🆕 20/08 — la settimana: sonno, HRV, battito e allenamenti.
            ...$this->settimanaDallApp($request),

            /*
             * 🆕 31/08/2026 — bruciate e peso della settimana.
             *
             * 🚨 **Fuori da `settimanaDallApp`, e non e' un dettaglio**: quel
             * metodo e' chiuso dietro `sleep_ai_consent_at`, che e' il consenso
             * ai dati del **sensore**. ⛔ Mettere li' le calorie bruciate
             * vorrebbe dire che chi non ha dato il consenso al sonno perde
             * anche il grafico delle calorie — due cose diverse spente da un
             * interruttore solo.
             */
            ...$this->settimanaDelCorpoDallApp($request),

            // 🆕 16/08/2026 — il recupero, se e solo se la persona l'ha concesso.
            ...$this->recuperoDallApp($request),
        ];
    }
}
