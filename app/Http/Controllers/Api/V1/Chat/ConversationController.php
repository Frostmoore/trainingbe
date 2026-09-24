<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Chat;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProfiloPubblico;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Account\ChiPossoCercare;
use App\Services\Chat\CancelloDellaChat;
use App\Services\Chat\LimiteDeiTreMessaggi;
use App\Services\Tenancy\ChiSiVede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La chat vista dall'app — B8.4.
 *
 * 🚨 **Il contratto deve reggere anche il polling.**
 * L'app ricade su polling a 15 secondi se il socket non si apre — su rete mobile
 * capita spesso — e una chat che «non arriva» distrugge la fiducia nel prodotto
 * piu' di quasi ogni altro guasto. Per questo `messages` accetta `after` e
 * `before`: con `after` il polling chiede solo il nuovo, senza riscaricare il
 * thread ogni quindici secondi.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly CancelloDellaChat $cancello,
        private readonly LimiteDeiTreMessaggi $limite,
        private readonly ChiSiVede $chiSiVede,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $utente = $request->user();

        // 🚨 Anche l'elenco passa dalla policy, non solo il singolo filo.
        // È qui che si ferma una sessione impersonata: senza, il super admin
        // nei panni di un trainer vedrebbe tutte le sue conversazioni — e il
        // controllo sul singolo messaggio arriverebbe troppo tardi, perché
        // l'elenco mostra già con chi parla e quando.
        if (Gate::denies('viewAny', Conversation::class)) {
            return response()->json(['data' => []]);
        }

        $conversazioni = Conversation::query()
            ->forUser($utente)
            ->with(['trainer', 'member'])
            ->recentFirst()
            ->get();

        return response()->json([
            'data' => $conversazioni->map(function (Conversation $c) use ($utente): array {
                $altro = $c->otherParty($utente);

                return [
                    'id' => $c->id,
                    'with' => [
                        'id' => $altro?->id,
                        'name' => $altro?->name,
                        'avatar_url' => $altro?->avatarUrl(),
                    ],
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                    'unread' => $c->unreadFor($utente),
                ];
            })->all(),
        ]);
    }

    /**
     * A chi posso scrivere — C22.
     *
     * 🚨 **Senza questo, «scrivi al tuo trainer» era impossibile dall'app.**
     * `POST /conversations` vuole un `user_id`, ma l'app non aveva nessun modo
     * di sapere **quale**: l'elenco delle conversazioni mostra solo quelle che
     * esistono gia', e chi non ne aveva nessuna vedeva una schermata vuota
     * senza vie d'uscita — una chat in cui non si puo' cominciare a parlare.
     *
     * ⚠️ **Non è la rubrica della palestra.** Un elenco di tutti gli iscritti
     * sarebbe, per un iscritto, la rubrica di tutti gli altri clienti — cioe'
     * esattamente cio' che `coppia()` impedisce.
     *
     * ── 🆕 F7 — un iscritto vede TUTTI i trainer della sua palestra ─────────
     *
     * Il requisito B8 chiede che un iscritto possa scrivere a **qualunque**
     * trainer della propria palestra, non solo a quelli che gli sono stati
     * assegnati. ⚠️ **Non contraddice la ragione scritta qui sopra**, e la
     * distinzione è tutta in una parola: si aggiungono i **trainer**, che sono
     * personale della palestra, non altri **clienti**.
     *
     * 🚨 **Le tre cose che restano vere, e vanno rilette prima di toccare questo
     * metodo:**
     *
     * 1. un iscritto **continua a non vedere gli altri iscritti**. Mai;
     * 2. si aggiungono solo i trainer del **proprio** tenant — ci pensa
     *    `TenantScope`, ma il vincolo è scritto anche qui perché non dipenda da
     *    uno stato ambientale;
     * 3. 🚨 poter **scrivere** a un trainer non è poter **leggere** niente: i
     *    messaggi restano illeggibili a chiunque non sia i due capi del filo,
     *    e le palestre non li leggono nemmeno impersonando.
     *
     * 💡 **Solo dentro una palestra**: in un tenant personale i «trainer della
     * palestra» sarebbero la persona stessa, e questo elenco le proporrebbe di
     * scrivere a sé stessa.
     */
    public function contacts(Request $request): JsonResponse
    {
        $utente = $request->user();

        if (Gate::denies('create', Conversation::class)) {
            return response()->json(['data' => []]);
        }

        /*
         * ══ 🆕 LE DUE DIREZIONI, DALLA REGOLA UNICA — 15/09/2026 ═════════
         *
         * Un utente puo' essere entrambe le cose: un trainer che si allena ha a
         * sua volta un trainer.
         *
         * ⛔ **Mancava una meta' della regola.** Un iscritto vedeva tutti i
         * trainer della sua palestra, ma un trainer di palestra **non vedeva
         * nessun iscritto**: si leggeva solo `trainer_member`, che per lui e'
         * vuota. 🚨 Provato su staging: in una palestra con tre iscritti,
         * questa rotta gliene mostrava zero.
         *
         * 💡 Adesso le due direzioni le risponde `ChiSiVede`, dove la regola
         * sta scritta una volta sola — e dove c'e' anche il caso del trainer che
         * ha un giro suo **e** lavora in palestra.
         */
        $persone = $this->chiSiVede->trainerDi($utente)
            ->merge($this->chiSiVede->allieviDi($utente))
            ->unique('id')
            ->values();

        return response()->json([
            'data' => $persone->map(fn (User $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'avatar_url' => $p->avatarUrl(),
                // Serve all'app per dire «il tuo trainer» invece del solo nome:
                // in una palestra si conosce il ruolo, non sempre il cognome.
                'is_trainer' => $p->hasAppRole(UserRole::Trainer),
            ])->all(),
        ]);
    }

    /*
     * 📌 `canaleChiuso()` **non sta piu' qui** — 18/08, M3.2.
     *
     * E' dentro `CancelloDellaChat`, insieme a tutte le altre condizioni che
     * decidono se si puo' scrivere. ⚠️ Tenerne una copia qui avrebbe voluto dire
     * due posti in cui cambiare la stessa regola, e la copia che nessuno
     * rilegge e' sempre quella che sbaglia.
     */

    /*
     * ⛔ **`trainerDellaPalestra()` non sta piu' qui** — 15/09/2026.
     *
     * 🚨 Faceva meta' della regola — un iscritto vede i trainer della sua
     * palestra — e l'altra meta' non la faceva nessuno: un trainer di palestra
     * non vedeva nessun iscritto.
     *
     * ⚠️ E aveva un limite che si vedeva solo col trainer che ha anche un giro
     * suo: cercava con `role()` di spatie **dentro il tenant corrente**, quindi
     * trovava solo chi ha `users.tenant_id` uguale alla palestra. Chi allena li'
     * ma sta nel proprio spazio non compariva — ed e' proprio la persona che la
     * regola esiste per includere.
     *
     * 💡 Adesso la regola sta tutta in `ChiSiVede`, per le due direzioni.
     */

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return $this->nonTrovata();
        }

        $query = $c->messages()->orderByDesc('id');

        // `after` e' la strada del polling: solo cio' che e' arrivato dopo.
        if (($after = $request->integer('after')) > 0) {
            $query->where('id', '>', $after)->reorder('id');
        } elseif (($before = $request->integer('before')) > 0) {
            $query->where('id', '<', $before);
        }

        $messaggi = $query->limit(min(100, (int) $request->integer('limit', 50)))->get();

        /*
         * 🆕 **Si dice PRIMA se si può scrivere** — 19/09/2026.
         *
         * ⛔ Visto sull'emulatore: dopo D9 una conversazione con chi non ti
         * segue più resta leggibile, ma il campo per scrivere sembrava normale
         * e il rifiuto arrivava **dopo** aver scritto e premuto invio. 🚨 Su
         * una conversazione chiusa non è un dettaglio: si scrive un messaggio
         * intero credendo che parta.
         *
         * 💡 La spiegazione la scrive il server, come per il rifiuto: l'app la
         * mostra e basta.
         */
        $permesso = $this->cancello->puoScrivere($request->user(), $c);

        return response()->json([
            'puoi_scrivere' => $permesso->consentito,
            'motivo' => $permesso->spiegazione,
            /*
             * 🚨 **`toApiArray()` sa CHI sta guardando** — N16.
             *
             * Un messaggio usa e getta gia' aperto non torna piu' a chi lo ha
             * aperto, e dopo 24 ore non torna piu' nemmeno a chi lo ha mandato.
             * ⚠️ Senza il destinatario qui, la stessa risposta sarebbe valida
             * per entrambi — e uno dei due vedrebbe qualcosa che non doveva.
             */
            'data' => $messaggi->sortBy('id')->values()
                ->map(fn (Message $m): array => $m->toApiArray((int) $request->user()->id))->all(),
        ]);
    }

    public function store(Request $request, int $conversation): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return $this->nonTrovata();
        }

        /*
         * 🚨 **Il cancello, e da qui in poi e' l'unico** — M3.2.
         *
         * Copre il canale chiuso (F6.4, decisione D5), il limite dei tre
         * messaggi (M4) e l'impersonazione. ⚠️ Prima queste regole stavano in
         * tre punti diversi; ora la tabella §4.6 vive in `CancelloDellaChat` e
         * **in nessun altro posto**, perche' tre copie della stessa regola sono
         * tre modi di divergere.
         *
         * 💡 **403 e non 404**: la conversazione esiste e la persona ci sta
         * dentro — nascondere il filo la farebbe pensare a un guasto, mentre il
         * fatto e' che non puo' scrivere. E la storia resta leggibile: si
         * chiude la scrittura, non l'archivio.
         *
         * 🚨 **Vale in entrambe le direzioni.** Chiudere solo la penna
         * dell'utente lascerebbe l'altro libero di scrivere a qualcuno che non
         * puo' rispondere, che e' peggio del silenzio.
         */
        $permesso = $this->cancello->puoScrivere($request->user(), $c);

        if (! $permesso->consentito) {
            return response()->json([
                'message' => $permesso->spiegazione,
                'code' => $permesso->codice,
                // 💡 L'app deve sapere **cosa offrire**, non solo che c'e' un no.
                'permesso' => $permesso->perLApp(),
            ], 403);
        }

        /*
         * 🚨 **Da S6 il server accetta solo buste cifrate.**
         *
         * `envelope_version` e `nonce` sono `required` proprio per questo: un
         * client che mandasse ancora testo in chiaro — una versione vecchia
         * dell'app, uno script, un test dimenticato — riceve 422 invece di
         * vedersi accettare il messaggio. ⚠️ Senza questo vincolo la chat
         * tornerebbe leggibile **un messaggio alla volta**, senza che niente si
         * rompa e senza che nessuno se ne accorga.
         *
         * Il server non puo' verificare che i byte siano *davvero* una busta
         * valida — non ha le chiavi — ma puo' pretendere che ne abbiano la
         * forma.
         */
        $dati = $request->validate([
            'envelope_version' => ['required', 'integer', 'min:1', 'max:255'],
            'nonce' => ['required', 'string', 'size:32'],
            'body' => ['required', 'string', 'min:1', 'max:8000'],

            /*
             * 🚨 **Facoltativo, e il default e' `false`.** Un client vecchio
             * che non conosce il campo continua a mandare messaggi normali: il
             * difetto in quella direzione mostra un messaggio piu' a lungo del
             * previsto, nell'altra lo cancellerebbe a chi non aveva chiesto
             * niente.
             */
            'usa_e_getta' => ['nullable', 'boolean'],

            /*
             * ⚠️ Serve **solo** a scegliere la traccia quando la busta sara'
             * spenta: «Foto effimera» invece di «Messaggio effimero». Non si
             * guarda per nient'altro.
             */
            'era_foto' => ['nullable', 'boolean'],
        ]);

        /*
         * 🚨 **Messaggio e contatore nella STESSA transazione** — M4.2.
         *
         * ⚠️ Se il messaggio si salvasse e il contatore no, la persona avrebbe
         * scritto senza consumare nulla e il limite diventerebbe un
         * suggerimento. Al contrario — contatore aumentato e messaggio perso —
         * avrebbe consumato senza scrivere, che e' peggio.
         */
        $messaggio = DB::transaction(function () use ($request, $c, $dati): Message {
            $m = $c->messages()->create([
                'sender_id' => $request->user()->getKey(),
                'envelope_version' => $dati['envelope_version'],
                'nonce' => $dati['nonce'],
                'body' => $dati['body'],
                'usa_e_getta' => (bool) ($dati['usa_e_getta'] ?? false),
                'era_foto' => (bool) ($dati['era_foto'] ?? false),
            ]);

            $this->limite->consuma($request->user(), $c);

            return $m;
        });

        // 🚨 Dopo il salvataggio, non prima: se il broadcast fallisse — broker
        // giu', configurazione sbagliata — il messaggio deve comunque esistere.
        // L'app che fa polling lo troverebbe lo stesso.
        MessageSent::announce($messaggio);

        return response()->json([
            'data' => $messaggio->toApiArray((int) $request->user()->id),

            /*
             * 💡 Quanti ne restano **dopo** questo, cosi' l'app aggiorna il
             * contatore senza una seconda chiamata — e dice «te ne resta uno»
             * mentre la persona sta ancora guardando la schermata.
             *
             * 🚨 Si richiede **al cancello**, non a `LimiteDeiTreMessaggi`.
             *
             * ⚠️ Il limite conta i messaggi e basta: non sa niente di
             * abbonamenti. Chiedendolo a lui, a un abbonato sarebbe stato
             * risposto `2`, `1`, `0` — cioe' la risposta HTTP avrebbe **detto il
             * contrario** del cancello, che lo lascia scrivere senza limite.
             * L'app avrebbe mostrato un contatore che scende a zero e poi non
             * succede niente. L'ha trovato un test.
             */
            'restanti' => $this->cancello->puoScrivere($request->user(), $c->fresh())->restanti,
        ], 201);
    }

    /**
     * «L'ho aperto»: da qui in poi la busta effimera non torna piu' — N16.4.
     *
     * ── 🚨 Perche' e' una chiamata a parte e non `read` ─────────────────
     *
     * `read` vuol dire «ho guardato la lista», e la lista si guarda aprendo la
     * conversazione. ⚠️ Legarci l'usa e getta avrebbe bruciato ogni messaggio
     * effimero **nell'istante in cui la chat si apre** — cioe' prima che qualcuno
     * lo leggesse davvero, e senza che nessuno potesse farci niente.
     *
     * 💡 Questa la chiama l'app **quando si chiude il visualizzatore**: la
     * foto e' stata guardata a schermo intero, il testo e' stato scoperto. E'
     * l'unico momento in cui «visto» vuol dire davvero visto.
     *
     * ── ⚠️ La chiama solo chi RICEVE ──────────────────────────────────────
     *
     * Chi ha mandato rilegge i propri messaggi (`crypto_box` glielo permette) e
     * potrebbe riaprire il proprio: se quel tocco contasse come «visto», si
     * brucerebbe da solo la busta che ha ancora diritto di vedere per 24 ore.
     */
    public function vista(Request $request, int $conversation, int $message): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return $this->nonTrovata();
        }

        /*
         * 🚨 **Attraverso la conversazione, mai per id.** E' la stessa regola
         * di `PlanExercise`: un messaggio si raggiunge solo dal suo padre,
         * altrimenti l'id progressivo diventa una chiave universale.
         */
        $messaggio = $c->messages()->whereKey($message)->first();

        if ($messaggio === null) {
            return $this->nonTrovata();
        }

        // 💡 Chi manda non brucia la propria busta: vedi il dartdoc.
        if ((int) $messaggio->sender_id === (int) $request->user()->id) {
            return response()->json(['data' => ['visto' => false]]);
        }

        $messaggio->segnaVista();

        return response()->json(['data' => ['visto' => true]]);
    }

    /** Segna come letto tutto quello che ha scritto l'altro. */
    public function read(Request $request, int $conversation): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return $this->nonTrovata();
        }

        $c->messages()
            ->where('sender_id', '!=', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    /**
     * Apre (o riapre) la conversazione con una persona.
     *
     * 🚨 **Si apre solo verso chi si vede** — la regola sta in `ChiSiVede` e la
     * applica `coppia()`, la stessa che riempie l'elenco dei contatti.
     *
     * ⛔ E fra due iscritti non si apre niente, mai: una chat verso chiunque
     * della palestra sarebbe, per un iscritto, il modo di scrivere a tutti gli
     * altri clienti.
     */
    public function open(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $io = $request->user();

        /*
         * ══ 🆕 SI CERCA FUORI DAL PROPRIO TENANT — 16/09/2026 ════════════════
         *
         * ⛔ Prima: `User::find()` (filtrato dal `TenantScope`) più un confronto
         * fra `tenant_id`. 🚨 Quel confronto escludeva **il trainer con un giro
         * suo**, che allena in palestra ma vive nel proprio spazio: i suoi
         * iscritti se lo vedevano in elenco e ricevevano 404 al tocco.
         *
         * 💡 Chi sia raggiungibile lo decide `coppia()`, che è la stessa regola
         * dell'elenco. Qui si toglie solo il filtro che impediva perfino di
         * **guardare** la riga.
         */
        $altro = User::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->find($dati['user_id']);

        if ($altro === null) {
            return response()->json(['message' => __('Persona non trovata.')], 404);
        }

        // Nemmeno aprirne una nuova: sarebbe scrivere a nome di un altro.
        if (Gate::denies('create', Conversation::class)) {
            return response()->json(['message' => __('Conversazione non trovata.')], 404);
        }

        [$trainer, $membro] = $this->coppia($io, $altro);

        if ($trainer === null) {
            /*
             * 🚨 **Due risposte diverse, e la differenza è voluta.**
             *
             * ⚠️ Nella propria palestra l'esistenza di quella persona non è un
             * segreto — ci si vede in sala — e dire *«non c'è collegamento»*
             * spiega. Fuori invece 404: distinguere «esiste ma non ti è
             * collegato» da «non esiste» trasformerebbe questa rotta in un modo
             * per **sondare gli id** e sapere quali account esistono sulla
             * piattaforma, un id alla volta.
             */
            return $altro->tenant_id === $io->tenant_id
                ? response()->json(['message' => __('Non c\'e\' un collegamento fra voi due.')], 403)
                : response()->json(['message' => __('Persona non trovata.')], 404);
        }

        $c = Conversation::between($trainer, $membro);

        /*
         * 🚨 **M4.4 — diventare iscritti sblocca il filo che c'era gia'.**
         *
         * Se questi due si erano gia' scritti dal catalogo, la conversazione
         * esiste ed e' di tipo `informazioni`, col limite dei tre. Ora sono
         * collegati per davvero — `coppia()` l'ha appena verificato — e il
         * limite non ha piu' senso.
         *
         * ⚠️ Senza questa riga la persona si iscriverebbe e **resterebbe
         * bloccata** nella stessa conversazione, con la storia sotto gli occhi e
         * la penna ferma: il difetto piu' irritante possibile, perche' capita
         * esattamente a chi ha appena pagato.
         *
         * 💡 E il contatore non si azzera, cambia il tipo: quel numero dice
         * quante volte quella persona ha provato prima di iscriversi.
         */
        $this->limite->sblocca($c);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    /**
     * Apre un filo **dal catalogo** — M3.2, `POST /conversations/informazioni`.
     *
     * ── 🚨 Si manda l'id della SCHEDA, non quello della persona ────────────
     *
     * ⚠️ Un `user_id` in ingresso vorrebbe dire che il catalogo deve pubblicare
     * gli identificativi dei titolari di palestra — a chiunque, senza
     * autenticazione. 💡 Mandando l'id della scheda, chi e' il destinatario lo
     * decide **il server** (`ProfiloPubblico::destinatario()`), e nessun id di
     * persona esce mai.
     *
     * 🚨 E i trainer **dipendenti** restano irraggiungibili senza bisogno di un
     * controllo apposta: una scheda e' di una palestra o di un trainer
     * indipendente, e un dipendente non ha una scheda da cui partire (§4.7).
     */
    public function informazioni(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'profilo_id' => ['required', 'integer'],
        ]);

        $io = $request->user();

        $scheda = ProfiloPubblico::query()->visibili()->find($dati['profilo_id']);

        /*
         * ⚠️ `404` anche per una scheda che esiste ma non e' pubblicata: un
         * messaggio diverso direbbe a chi prova gli id quali palestre sono
         * iscritte senza essersi pubblicate.
         */
        if ($scheda === null) {
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        $destinatario = $scheda->destinatario();

        if ($destinatario === null) {
            return response()->json([
                'message' => __('Questa scheda non e\' contattabile.'),
                'code' => 'non_contattabile',
            ], 409);
        }

        $permesso = $this->cancello->puoAprireInformazioni($io, $destinatario);

        if (! $permesso->consentito) {
            return response()->json([
                'message' => $permesso->spiegazione,
                'code' => $permesso->codice,
                'permesso' => $permesso->perLApp(),
            ], 403);
        }

        /*
         * 🚨 Chi chiede e' il **member**, chi risponde e' il **trainer**.
         *
         * ⚠️ Non e' una convenzione arbitraria: `conversations` ha due colonne
         * con quei nomi e tutto il resto del codice le legge cosi'. Invertirle
         * per un filo di informazioni farebbe apparire la palestra come
         * «iscritto» in ogni elenco.
         */
        $c = Conversation::between($destinatario, $io, TipoConversazione::Informazioni);

        return response()->json(['data' => [
            'id' => $c->id,
            'tipo' => $c->tipo->value,
            'con' => [
                'nome' => $scheda->titolo,
                'tipo' => $scheda->ePalestra() ? 'palestra' : 'trainer',
            ],
            /*
             * 💡 M4.3: l'app deve poter dire quanti ne restano **prima** che la
             * persona scriva, non dopo aver premuto invio.
             *
             * 🚨 Dal cancello e non dal limite: per un abbonato dev'essere
             * `null` (nessun limite), e il limite da solo risponderebbe `3`.
             */
            'restanti' => $this->cancello->puoScrivere($io, $c)->restanti,
        ]], 201);
    }

    /**
     * 🆕 🔎 **Scrivere a una persona trovata col nome utente** — B3, 24/09/2026.
     *
     * 📌 Il committente, il 23/09: *«bisognerà inventarsi un sistema di username
     * per poter scrivere a chiunque»*.
     *
     * ══ 🚨 PERCHE' NON BASTAVANO LE DUE ROTTE CHE C'ERANO ═══════════════════
     *
     * | Rotta | Con chi | Perché non serviva |
     * |---|---|---|
     * | `POST /conversations` | chi è **già** collegato a me | ⛔ una persona trovata per nome utente non lo è |
     * | `POST /conversations/informazioni` | una **scheda pubblica** del catalogo | ⛔ un trainer trovabile può non averne nessuna |
     *
     * 💡 Questa apre un filo **di informazioni** — cioè col limite dei tre
     * messaggi di presentazione — con chi **posso trovare**. ⚠️ Il cancello è lo
     * stesso della ricerca (`ChiPossoCercare`): se non lo posso trovare, non
     * gli posso nemmeno scrivere. ⛔ Averne due vorrebbe dire una porta chiusa
     * accanto a una aperta.
     *
     * 🚨 **404 e non 403** quando non si può: distinguere «esiste ma non lo
     * puoi trovare» da «non esiste» renderebbe questa rotta un modo per
     * scoprire chi c'è, una prova alla volta.
     */
    public function daNomeUtente(Request $request, ChiPossoCercare $ricerca): JsonResponse
    {
        $dati = $request->validate([
            'utente_id' => ['required', 'integer'],
        ]);

        $io = $request->user();

        $destinatario = User::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->find($dati['utente_id']);

        if ($destinatario === null || $destinatario->username === null) {
            return response()->json(['message' => __('Persona non trovata.')], 404);
        }

        /*
         * ⚠️ Si ripercorre **la ricerca**, non si guarda solo `trovabile`: il
         * cancello della decisione 53.2 dice che un iscritto lo trovano solo i
         * suoi, e quella regola deve valere anche qui.
         */
        $trovabile = $ricerca->query($io, (string) $destinatario->username)
            ->whereKey($destinatario->getKey())
            ->exists();

        if (! $trovabile) {
            return response()->json(['message' => __('Persona non trovata.')], 404);
        }

        $permesso = $this->cancello->puoAprireInformazioni($io, $destinatario);

        if (! $permesso->consentito) {
            return response()->json([
                'message' => $permesso->spiegazione,
                'code' => $permesso->codice,
                'permesso' => $permesso->perLApp(),
            ], 403);
        }

        // 🚨 Chi chiede è il **member**, chi risponde è il **trainer**: sono i
        //    nomi delle due colonne, e invertirli sballa ogni elenco.
        $c = Conversation::between($destinatario, $io, TipoConversazione::Informazioni);

        return response()->json(['data' => [
            'id' => $c->id,
            'tipo' => $c->tipo->value,
            'con' => [
                'nome' => $destinatario->name,
                'username' => $destinatario->username,
            ],
            'restanti' => $this->cancello->puoScrivere($io, $c)->restanti,
        ]], 201);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Chi dei due e' il trainer, e solo se sono davvero collegati.
     *
     * ══ 🆕 UNA REGOLA SOLA, ED È `ChiSiVede` — 16/09/2026 ══════════════════
     *
     * ⛔ Qui c'erano tre rami scritti a mano — assegnati, assegnanti, e il ramo
     * F7.1 per i trainer della propria palestra — e `contacts()` ne aveva altri
     * suoi. 🚨 Due elenchi della stessa regola divergono sempre, e la volta
     * scorsa è successo: dopo la regola del 15/09 l'elenco dei contatti mostrava
     * il trainer con un giro suo agli iscritti della palestra, e il tocco
     * rispondeva **404** — perché quel trainer vive nel proprio tenant e questo
     * metodo pretendeva lo stesso `tenant_id`.
     *
     * 💡 Adesso la domanda è una: *lo vede o no?* — la stessa che risponde
     * all'elenco. Se compare nei contatti si apre, per costruzione.
     *
     * ⚠️ **Cosa NON è cambiato**, e non deve cambiare:
     *
     * - fra due iscritti non c'è chat, mai: `allieviDi()` di un iscritto è
     *   vuoto e `trainerDi()` porta solo trainer, mai altri iscritti;
     * - un `gym_admin` non è raggiungibile da qui: `trainerDi()` cerca il ruolo
     *   `trainer` e basta;
     * - un rapporto **disattivato** resta una coppia valida. 💡 `assignedMembers`
     *   non filtra `disattivato_il`, ed è voluto: il filo si riapre, e a dire
     *   che è chiuso ci pensa `CancelloDellaChat::canaleChiuso()` con il suo
     *   messaggio. Rispondere «non c'è collegamento» sarebbe una bugia su una
     *   storia che quelle due persone hanno davvero.
     *
     * @return array{0: ?User, 1: User}
     */
    private function coppia(User $io, User $altro): array
    {
        if ($this->chiSiVede->allieviDi($io)->contains('id', $altro->getKey())) {
            return [$io, $altro];
        }

        if ($this->chiSiVede->trainerDi($io)->contains('id', $altro->getKey())) {
            return [$altro, $io];
        }

        return [null, $altro];
    }

    /**
     * La conversazione, solo se chi chiede ci sta dentro.
     *
     * 🚨 **Due serrature, e servono per motivi diversi.**
     *
     * `forUser()` e non `find()`: il global scope limita alla palestra, ma dentro
     * una palestra ci sono decine di conversazioni altrui — comprese quelle dei
     * colleghi e quelle del titolare. Restituendo `null` si risponde 404, che
     * non distingue «non esiste» da «non è tua»: distinguerli direbbe a chi
     * prova gli id quali esistono.
     *
     * `ConversationPolicy` è la seconda: qui è ridondante **oggi**, e serve
     * perché domani non lo sia. Se qualcuno cambiasse la query qui sopra — per
     * aggiungere una funzione, per «far vedere anche al gym_admin» — la policy
     * resterebbe a dire di no. Quello che le persone si scrivono in chat non
     * deve dipendere da una sola riga di codice.
     */
    private function conversazioneDi(Request $request, int $id): ?Conversation
    {
        $conversazione = Conversation::query()
            ->forUser($request->user())
            ->find($id);

        if ($conversazione === null) {
            return null;
        }

        return Gate::allows('view', $conversazione) ? $conversazione : null;
    }

    private function nonTrovata(): JsonResponse
    {
        return response()->json(['message' => __('Conversazione non trovata.')], 404);
    }
}
