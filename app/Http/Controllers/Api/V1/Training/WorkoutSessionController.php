<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Media;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use App\Services\Training\ExerciseMatcher;
use App\Services\Training\WorkoutCalorieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gli allenamenti dell'iscritto — B4.5.
 *
 * 🚨 **Ogni metodo carica la sessione con `sessioneDi()`**, che filtra su
 * `user_id`. Non e' ridondante rispetto al global scope: quello limita alla
 * palestra, e dentro una palestra ci sono centinaia di persone. Un `find($id)`
 * nudo restituirebbe l'allenamento di chiunque.
 */
class WorkoutSessionController extends Controller
{
    public function __construct(
        private readonly WorkoutCalorieService $calorie,
        private readonly ExerciseMatcher $matcher,
    ) {}

    // ───────────────────────── elenco ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $sessioni = WorkoutSession::query()
            ->forUser($request->user())
            ->with(['plan', 'sets.exercise'])
            ->orderByDesc('started_at')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return response()->json([
            'data' => collect($sessioni->items())
                ->map(fn (WorkoutSession $s): array => $this->riassunto($s))->all(),
            'meta' => [
                'current_page' => $sessioni->currentPage(),
                'last_page' => $sessioni->lastPage(),
                'total' => $sessioni->total(),
            ],
        ]);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        $s = $this->sessioneDi($request, $session, ['sets.exercise', 'plan']);

        if ($s === null) {
            return $this->nonTrovata();
        }

        return response()->json(['data' => $this->dettaglio($s)]);
    }

    // ───────────────────────── ciclo di vita ─────────────────────────

    /**
     * Apre una sessione.
     *
     * `started_at` si accetta dal client perche' l'app puo' aver registrato
     * l'inizio mentre era offline e sincronizzare dopo. Ma non si accetta nel
     * futuro: un orario avanti sballerebbe la durata e quindi le calorie.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'workout_plan_id' => ['nullable', 'integer'],
            'started_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $utente = $request->user();
        $pianoId = null;

        if (isset($dati['workout_plan_id'])) {
            // La scheda deve essere **sua**: un id di un'altra persona non deve
            // finire agganciato al proprio allenamento.
            $piano = WorkoutPlan::query()
                ->forMember($utente)
                ->find($dati['workout_plan_id']);

            if ($piano === null) {
                return response()->json(['message' => __('Scheda non trovata.')], 422);
            }

            $pianoId = $piano->id;
        }

        $sessione = WorkoutSession::create([
            'user_id' => $utente->getKey(),
            'workout_plan_id' => $pianoId,
            'started_at' => isset($dati['started_at']) ? Carbon::parse($dati['started_at']) : now(),
            'notes' => $dati['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->dettaglio($sessione->load('sets'))], 201);
    }

    /**
     * Registra o aggiorna una serie.
     *
     * 🚨 **E' un UPSERT, non un INSERT.** L'app rimanda lo stesso salvataggio
     * quando la rete va e viene: senza `updateOrCreate` sul vincolo (sessione,
     * esercizio, numero) la stessa serie comparirebbe due volte nello storico, e
     * il volume di allenamento risulterebbe doppio.
     */
    public function storeSet(Request $request, int $session): JsonResponse
    {
        $s = $this->sessioneDi($request, $session);

        if ($s === null) {
            return $this->nonTrovata();
        }

        $dati = $request->validate([
            // 🚨 O l'id, o il nome — C2.4.
            //
            // In sala capita di continuo che la scheda non corrisponda alla
            // realta': una macchina occupata, un esercizio sostituito al volo.
            // Il player dell'app storica permette di scrivere un nome nuovo e
            // l'esercizio nasce da solo; costringere a un id significherebbe
            // farsi creare prima l'esercizio in una seconda richiesta, che puo'
            // fallire lasciando la serie non registrata.
            'exercise_id' => ['required_without:exercise_name', 'integer'],
            'exercise_name' => ['required_without:exercise_id', 'string', 'min:2', 'max:160'],
            'set_number' => ['required', 'integer', 'min:1', 'max:99'],
            'reps' => ['nullable', 'integer', 'min:0', 'max:999'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'duration_sec' => ['nullable', 'integer', 'min:0', 'max:36000'],
            'rest_sec' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        if (isset($dati['exercise_id'])) {
            // L'esercizio dev'essere visibile a questa palestra: lo scope
            // «tenant o globale» lo garantisce.
            if (Exercise::find($dati['exercise_id']) === null) {
                return response()->json(['message' => __('Esercizio non trovato.')], 422);
            }

            $esercizioId = (int) $dati['exercise_id'];
        } else {
            // Il nome si riconcilia con la libreria prima di creare: «panca
            // piana bilanciere» è la «panca piana» che la palestra ha già.
            $esercizioId = $this->matcher
                ->match($dati['exercise_name'], $request->user()->tenant_id, $request->user())
                ->getKey();
        }

        $serie = SessionSet::updateOrCreate(
            [
                'workout_session_id' => $s->id,
                'exercise_id' => $esercizioId,
                'set_number' => $dati['set_number'],
            ],
            [
                'reps' => $dati['reps'] ?? null,
                'weight' => $dati['weight'] ?? null,
                'duration_sec' => $dati['duration_sec'] ?? null,
                'rest_sec' => $dati['rest_sec'] ?? null,
                'done_at' => now(),
            ],
        );

        return response()->json(['data' => [
            'id' => $serie->id,
            'exercise_id' => $serie->exercise_id,
            'set_number' => $serie->set_number,
            'reps' => $serie->reps,
            'weight' => $serie->weight,
            'volume' => $serie->volume(),
        ]], 201);
    }

    /**
     * Chiude la sessione e fa partire la stima.
     *
     * Se la sessione e' gia' chiusa non si riapre e non si ristima: un secondo
     * «finish» arriva quando l'app ritenta, e deve essere innocuo.
     */
    public function finish(Request $request, int $session): JsonResponse
    {
        $s = $this->sessioneDi($request, $session, ['sets.exercise', 'plan']);

        if ($s === null) {
            return $this->nonTrovata();
        }

        if ($s->isOpen()) {
            $dati = $request->validate([
                'ended_at' => ['nullable', 'date', 'before_or_equal:now'],
                'notes' => ['nullable', 'string', 'max:2000'],

                /*
                 * 🚨 Il peso arriva DALL'APP e non si conserva — S5.4.
                 *
                 * Il server non ha piu' `body_metrics` (decisione D9-bis),
                 * quindi senza questo campo la stima delle calorie userebbe il
                 * peso di ripiego per tutti. Il valore serve al calcolo di
                 * questa richiesta e **non viene salvato da nessuna parte**:
                 * transita, come previsto da «il server e' un tramite».
                 */
                'weight_kg' => ['nullable', 'numeric', 'min:25', 'max:400'],
            ]);

            $s->forceFill([
                'ended_at' => isset($dati['ended_at']) ? Carbon::parse($dati['ended_at']) : now(),
                'notes' => $dati['notes'] ?? $s->notes,
            ])->save();

            $this->calorie->estimateAndStore(
                $s,
                $request->user(),
                isset($dati['weight_kg']) ? (float) $dati['weight_kg'] : null,
            );
        }

        return response()->json(['data' => $this->dettaglio($s->refresh()->load('sets.exercise'))]);
    }

    /**
     * Sovrascrittura manuale delle calorie.
     *
     * `kcal: null` disfa la correzione e rimette il valore in mano alla stima —
     * senza costringere l'utente a ricordarsi il numero di prima.
     */
    public function updateKcal(Request $request, int $session): JsonResponse
    {
        $s = $this->sessioneDi($request, $session, ['sets.exercise']);

        if ($s === null) {
            return $this->nonTrovata();
        }

        $dati = $request->validate([
            'kcal' => ['present', 'nullable', 'integer', 'min:0', 'max:20000'],
        ]);

        $this->calorie->setManual($s, $dati['kcal']);

        return response()->json(['data' => $this->dettaglio($s->refresh())]);
    }

    public function destroy(Request $request, int $session): JsonResponse
    {
        $s = $this->sessioneDi($request, $session);

        if ($s === null) {
            return $this->nonTrovata();
        }

        $s->delete();

        return response()->json(null, 204);
    }

    // ───────────────────────── interni ─────────────────────────

    /** @param list<string> $with */
    private function sessioneDi(Request $request, int $id, array $with = []): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->forUser($request->user())
            ->with($with)
            ->find($id);
    }

    private function nonTrovata(): JsonResponse
    {
        return response()->json(['message' => __('Allenamento non trovato.')], 404);
    }

    /** @return array<string, mixed> */
    private function riassunto(WorkoutSession $s): array
    {
        return [
            'id' => $s->id,
            'plan' => $s->plan !== null ? ['id' => $s->plan->id, 'name' => $s->plan->name] : null,
            'started_at' => $s->started_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'duration_minutes' => $s->durationMinutes(),
            'is_open' => $s->isOpen(),
            'kcal' => $s->kcal_burned,
            'kcal_source' => $s->kcal_source?->value,
            'notes' => $s->notes,
            'photos' => $this->fotoDi($s),
        ];
    }

    /**
     * Le foto scattate durante questo allenamento — C5.
     *
     * ⚠️ Sta anche nel **riassunto** e non solo nel dettaglio: lo storico a
     * schede usa la prima come miniatura, ed è ciò che lo rende leggibile a
     * colpo d'occhio. Metterla solo nel dettaglio costringerebbe l'app a una
     * chiamata per ogni scheda dell'elenco.
     *
     * 🚨 Le foto si caricano **tutte in una query** e si tengono in memoria per
     * la durata della richiesta. Una query per sessione, su un elenco di
     * cinquanta allenamenti, sarebbero cinquanta viaggi al database per
     * disegnare cinquanta miniature.
     *
     * @return list<array<string, mixed>>
     */
    private function fotoDi(WorkoutSession $s): array
    {
        $this->caricaFoto($s->user_id);

        return $this->fotoPerSessione[$s->getKey()] ?? [];
    }

    /**
     * @var array<int, list<array<string, mixed>>>|null
     */
    private ?array $fotoPerSessione = null;

    private function caricaFoto(int $userId): void
    {
        if ($this->fotoPerSessione !== null) {
            return;
        }

        $this->fotoPerSessione = Media::query()
            ->where('model_type', (new User)->getMorphClass())
            ->where('model_id', $userId)
            ->where('collection_name', 'workout')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Media $m): int => (int) $m->getCustomProperty('workout_session_id'))
            ->map(fn ($gruppo): array => $gruppo->map(fn (Media $m): array => [
                'id' => $m->id,
                'url' => url("/api/v1/photos/{$m->id}/file"),
            ])->all())
            ->all();
    }

    /** @return array<string, mixed> */
    private function dettaglio(WorkoutSession $s): array
    {
        return array_merge($this->riassunto($s), [
            'sets' => $s->sets->map(fn (SessionSet $r): array => [
                'id' => $r->id,
                'exercise' => [
                    'id' => $r->exercise?->id,
                    'name' => $r->exercise?->name,

                    /*
                     * 🆕 **Il MET viaggia con l'esercizio** — FASE 11.2, 21/08/2026.
                     *
                     * 🚨 Da quando gli allenamenti stanno sul telefono, il calcolo
                     * delle calorie (`MET x kg x ore`) gira **li'**. Ma il catalogo
                     * degli esercizi resta sul server — e' roba condivisa, non e' di
                     * nessuno (`plan_tutto_sul_telefono.md` §2.2) — quindi il MET
                     * deve arrivare insieme all'esercizio, o l'app dovrebbe chiedere
                     * il catalogo ogni volta che ricalcola.
                     *
                     * ⚠️ `null` per i pochi esercizi che non ce l'hanno (1 su 121) e
                     * per quelli scritti a mano dalle palestre: l'app usa il ripiego
                     * generico, esattamente come faceva `metOf()` qui.
                     */
                    'met' => $r->exercise?->met,
                ],
                'set_number' => $r->set_number,
                'reps' => $r->reps,
                'weight' => $r->weight,
                'duration_sec' => $r->duration_sec,
                'rest_sec' => $r->rest_sec,
                'volume' => $r->volume(),
                'done_at' => $r->done_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
