<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Chat;

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // Le due direzioni del legame: un utente puo' essere entrambe le cose
        // (un trainer che si allena ha a sua volta un trainer).
        $persone = $utente->assignedTrainers()->get()
            ->merge($utente->assignedMembers()->get())
            ->merge($this->trainerDellaPalestra($utente))
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

    /**
     * Il rapporto fra i due capi di questo filo è stato sospeso? — F6.4.
     *
     * ⚠️ Si guarda il **legame**, non l'utente: `users.is_active` chiuderebbe
     * fuori quella persona dall'**app**, e una persona che paga anche un altro
     * trainer si ritroverebbe l'account bloccato da un terzo.
     *
     * 💡 `withoutGlobalScopes()` sul pivot: il legame appartiene al tenant del
     * trainer (F6.1), quindi letto dal contesto dell'**utente** — che ha un
     * tenant personale suo — non si troverebbe. Non è un bypass: la coppia di
     * id è già la chiave più stretta possibile.
     */
    private function canaleChiuso(Conversation $c): bool
    {
        return DB::table('trainer_member')
            ->where('trainer_id', $c->trainer_id)
            ->where('member_id', $c->member_id)
            ->whereNotNull('disattivato_il')
            ->exists();
    }

    /**
     * Tutti i trainer della palestra di questa persona — F7.1.
     *
     * 🚨 **Solo se è una palestra vera.** In un tenant personale l'unico utente
     * è la persona stessa: senza questo controllo l'app le proporrebbe di
     * scrivere a sé stessa.
     *
     * ⚠️ E **mai** l'utente corrente nell'elenco, nemmeno quando è lui stesso un
     * trainer di quella palestra: `whereKeyNot()`. Un trainer che apre «a chi
     * posso scrivere» non deve trovarsi fra i contatti.
     *
     * 💡 La ricerca passa da `role()` di spatie, che filtra sul team corrente —
     * ed è quello che serve, perché il contesto **è** la palestra dell'utente
     * autenticato (`ResolveTenant`). Il `TenantScope` fa il resto.
     *
     * @return Collection<int, User>
     */
    private function trainerDellaPalestra(User $utente): Collection
    {
        $tenant = $utente->tenant;

        if ($tenant === null || $tenant->ePersonale()) {
            return collect();
        }

        return User::query()
            ->whereKeyNot($utente->getKey())
            ->where('is_active', true)
            ->role(UserRole::Trainer->value)
            ->get();
    }

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

        return response()->json([
            'data' => $messaggi->sortBy('id')->values()
                ->map(fn (Message $m): array => $m->toApiArray())->all(),
        ]);
    }

    public function store(Request $request, int $conversation): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return $this->nonTrovata();
        }

        /*
         * 🚨 **Il canale chiuso — F6.4, decisione D5.**
         *
         * Se il trainer ha disattivato questo utente, non si scrive più. ⚠️ È
         * l'unico effetto della disattivazione, e da solo copre più di quanto
         * sembri: dopo D11/D13 i piani viaggiano **dentro la chat**, quindi
         * chiudere il canale chiude anche la consegna di piani nuovi.
         *
         * 💡 **403 e non 404**: la conversazione esiste e la persona ci sta
         * dentro — nascondere il filo la farebbe pensare a un guasto, mentre il
         * fatto è che il rapporto è sospeso. E la storia resta leggibile: si
         * chiude la scrittura, non l'archivio.
         *
         * 🚨 **Vale in entrambe le direzioni.** Chiudere solo la penna
         * dell'utente lascerebbe il trainer libero di scrivere a qualcuno che
         * non può rispondere, che è peggio del silenzio.
         */
        if ($this->canaleChiuso($c)) {
            return response()->json([
                'message' => __('Questa conversazione è stata chiusa.'),
                'code' => 'conversation_closed',
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
        ]);

        $messaggio = $c->messages()->create([
            'sender_id' => $request->user()->getKey(),
            'envelope_version' => $dati['envelope_version'],
            'nonce' => $dati['nonce'],
            'body' => $dati['body'],
        ]);

        // 🚨 Dopo il salvataggio, non prima: se il broadcast fallisse — broker
        // giu', configurazione sbagliata — il messaggio deve comunque esistere.
        // L'app che fa polling lo troverebbe lo stesso.
        MessageSent::announce($messaggio);

        return response()->json(['data' => $messaggio->toApiArray()], 201);
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
     * L'iscritto scrive al proprio trainer, il trainer a un proprio assegnato:
     * fuori da quel legame non si apre niente. Una chat aperta verso chiunque
     * della palestra sarebbe, per un iscritto, il modo per scrivere a tutti gli
     * altri iscritti.
     */
    public function open(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $io = $request->user();
        $altro = User::find($dati['user_id']);

        if ($altro === null || $altro->tenant_id !== $io->tenant_id) {
            return response()->json(['message' => __('Persona non trovata.')], 404);
        }

        // Nemmeno aprirne una nuova: sarebbe scrivere a nome di un altro.
        if (Gate::denies('create', Conversation::class)) {
            return response()->json(['message' => __('Conversazione non trovata.')], 404);
        }

        [$trainer, $membro] = $this->coppia($io, $altro);

        if ($trainer === null) {
            return response()->json(['message' => __('Non c\'e\' un collegamento fra voi due.')], 403);
        }

        $c = Conversation::between($trainer, $membro);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Chi dei due e' il trainer, e solo se sono davvero collegati.
     *
     * @return array{0: ?User, 1: User}
     */
    private function coppia(User $io, User $altro): array
    {
        if ($io->assignedMembers()->where('users.id', $altro->getKey())->exists()) {
            return [$io, $altro];
        }

        if ($io->assignedTrainers()->where('users.id', $altro->getKey())->exists()) {
            return [$altro, $io];
        }

        /*
         * 🆕 **F7.1 — un iscritto può scrivere a un trainer della sua palestra
         * anche senza essere stato assegnato a lui.**
         *
         * Senza questo ramo, `contacts()` avrebbe elencato dei trainer a cui
         * `open()` avrebbe poi risposto **403**: un elenco di persone
         * irraggiungibili, che è peggio di un elenco vuoto — l'utente prova, non
         * funziona, e non capisce perché.
         *
         * ⚠️ **Le condizioni sono tre e servono tutte:**
         *
         * 1. l'altro è un `Trainer` — ⚠️ **non** un `GymAdmin` e **non** un
         *    altro iscritto. Il requisito B8 parla di trainer;
         * 2. stesso tenant — già garantito da `open()`, ripetuto qui perché
         *    `coppia()` non dipenda da chi la chiama;
         * 3. 🚨 **il tenant è una palestra vera.** In un tenant personale
         *    l'unico utente è la persona stessa, e questo ramo le farebbe aprire
         *    una conversazione con sé stessa.
         *
         * 💡 E resta vero ciò che conta: poter **scrivere** a un trainer non è
         * poter **leggere** niente. La cifratura non cambia, e la palestra non
         * legge i messaggi dei suoi trainer nemmeno impersonando.
         */
        $tenant = $io->tenant;

        if ($tenant !== null
            && ! $tenant->ePersonale()
            && $io->tenant_id === $altro->tenant_id
            && $altro->hasAppRole(UserRole::Trainer)) {
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
