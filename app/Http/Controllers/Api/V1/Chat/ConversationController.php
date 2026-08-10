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
     * ⚠️ **Sono solo le persone collegate**, le stesse che `open()` accetta: i
     * propri trainer per un iscritto, i propri assegnati per un trainer. Un
     * elenco di tutta la palestra sarebbe, per un iscritto, la rubrica di tutti
     * gli altri iscritti — cioe' esattamente cio' che `coppia()` impedisce.
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

        $dati = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $messaggio = $c->messages()->create([
            'sender_id' => $request->user()->getKey(),
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
