<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkoutPlanRequest;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Le schede dell'iscritto — B4.5, estese in C2.
 *
 * 🚨 **Due filtri, non uno.** Il global scope limita alla palestra; qui si
 * aggiunge `member_id = utente corrente`. Il primo da solo lascerebbe un
 * iscritto leggere la scheda di un suo compagno di palestra passando un id
 * qualsiasi — che e' l'esatto contrario di quello che ci si aspetta da un dato
 * personale.
 *
 * E si filtra su `published`: una bozza e' lavoro in corso del trainer, e finche'
 * non la pubblica non deve comparire nell'app.
 *
 * ── C2: l'iscritto si scrive le proprie schede (decisione D1) ──────────────
 *
 * Nella stessa tabella convivono due cose diverse: la scheda **prescritta dal
 * trainer**, che si esegue e non si tocca, e quella che **l'iscritto si e'
 * scritto**, che e' sua. Il discriminante e' `created_by` e sta in
 * `WorkoutPlanPolicy`; qui si usa `Gate`, non si riscrive la regola.
 */
class WorkoutPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $piani = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'data' => $piani->map(fn (WorkoutPlan $p): array => $this->riassunto($p))->all(),
        ]);
    }

    /**
     * I modelli della palestra — S7.
     *
     * ── 🚨 Serve perché l'assegnazione è uscita dal pannello ──────────────
     *
     * Il trainer manda una scheda **dalla chat, dall'app**: è l'unico posto in
     * cui esistono le chiavi per cifrarla. Ma i modelli li scrive nel pannello,
     * quindi l'app deve poterli **leggere** — e questo è l'endpoint che glieli
     * dà, per intero, pronti da serializzare dentro un messaggio.
     *
     * ⚠️ **Solo i modelli** (`member_id IS NULL`), e solo a chi allena. Un
     * iscritto che chiamasse questa rotta si vedrebbe l'intero patrimonio di
     * schede della palestra: è il lavoro del trainer, e non è materiale suo.
     *
     * 💡 I modelli **restano sul server in chiaro**, ed è deliberato (S7.3):
     * non parlano di nessuno — sono esercizi, serie e ripetizioni — e sono il
     * patrimonio della palestra. Quello che non deve restare scritto è il
     * **legame fra una persona e un programma**, e quello adesso viaggia solo
     * dentro una busta cifrata.
     */
    public function templates(Request $request): JsonResponse
    {
        $utente = $request->user();

        if (! $utente->isTrainer() && ! $utente->isGymAdmin()) {
            // 403 e non 404: qui non c'è nessun id da indovinare, quindi non
            // c'è nessun oracolo da proteggere. Dire la verità aiuta chi sta
            // scrivendo il client.
            return response()->json(['message' => __('Non sei un trainer.')], 403);
        }

        $modelli = WorkoutPlan::query()
            ->templates()
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $modelli->map(fn (WorkoutPlan $p): array => $this->dettaglio($p))->all(),
        ]);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        $piano = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->find($plan);

        if ($piano === null) {
            // 404 e non 403: distinguere «non esiste» da «non e' tua» direbbe a
            // chi prova gli id quali esistono.
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        return response()->json(['data' => $this->dettaglio($piano)]);
    }

    // ───────────────────────── C2: scrittura ─────────────────────────

    /**
     * Una scheda che l'iscritto scrive per sé.
     *
     * ⚠️ **Nasce gia' pubblicata.** Lo stato `draft` esiste perche' il trainer
     * possa lavorarci prima di consegnarla; una scheda che uno scrive per sé non
     * ha nessuno da cui essere rivista, e lasciarla in bozza la renderebbe
     * invisibile a `index()`, che filtra `published()`. Sarebbe un salvataggio
     * riuscito che produce una scheda sparita.
     */
    public function store(WorkoutPlanRequest $request): JsonResponse
    {
        $utente = $request->user();

        $piano = DB::transaction(function () use ($request, $utente): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $utente->tenant_id,
                'member_id' => $utente->getKey(),
                'created_by' => $utente->getKey(),
                'name' => $request->validated()['name'],
                'notes' => $request->validated()['notes'] ?? null,
                'status' => PlanStatus::Published,
                'source' => PlanSource::Manual,
                'published_at' => now(),
            ]);

            $this->sincronizzaRighe($piano, $request->righe(), $utente);

            return $piano;
        });

        return response()->json(
            ['data' => $this->dettaglio($piano->load(['exercises.exercise', 'creator']))],
            201,
        );
    }

    public function update(WorkoutPlanRequest $request, int $plan): JsonResponse
    {
        $utente = $request->user();
        $piano = WorkoutPlan::query()->find($plan);

        if ($piano === null || $utente->cannot('view', $piano)) {
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        // 403 e non 404: qui la scheda esiste **ed e' sua**, semplicemente non e'
        // sua da modificare. Dirle «non trovata» sarebbe una bugia su una cosa
        // che ha davanti agli occhi.
        if ($utente->cannot('update', $piano)) {
            return response()->json([
                'message' => __('Questa scheda l\'ha scritta il tuo trainer: puoi eseguirla, non modificarla.'),
                'code' => 'plan_not_editable',
            ], 403);
        }

        DB::transaction(function () use ($request, $piano, $utente): void {
            $piano->update([
                'name' => $request->validated()['name'],
                'notes' => $request->validated()['notes'] ?? null,
            ]);

            // `exercises` assente significa «non toccare gli esercizi»: l'app
            // rinomina una scheda senza dover rimandare tutte le righe.
            if ($request->has('exercises')) {
                $this->sincronizzaRighe($piano, $request->righe(), $utente);
            }
        });

        return response()->json(['data' => $this->dettaglio($piano->fresh()->load(['exercises.exercise', 'creator']))]);
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        $utente = $request->user();
        $piano = WorkoutPlan::query()->find($plan);

        if ($piano === null || $utente->cannot('view', $piano)) {
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        if ($utente->cannot('delete', $piano)) {
            // La policy nega anche quando la scheda ha allenamenti gia' fatti:
            // cancellarla lascerebbe uno storico senza origine.
            return response()->json([
                'message' => $piano->sessions()->exists()
                    ? __('Questa scheda ha allenamenti registrati: non si può eliminare.')
                    : __('Questa scheda non è tua da eliminare.'),
                'code' => 'plan_not_deletable',
            ], 403);
        }

        $piano->delete();

        return response()->json(null, 204);
    }

    /**
     * Riscrive le righe della scheda.
     *
     * ⚠️ **Cancella e riscrive.** È accettabile perché `plan_exercises` non ha
     * figli: lo storico (`session_sets`) punta a `exercises`, non a queste
     * righe. Se un giorno qualcosa vi puntasse, questo `delete()` porterebbe via
     * anche quello — scritto qui perché non lo si scopra a danno fatto.
     *
     * @param  list<array<string, mixed>>  $righe
     */
    private function sincronizzaRighe(WorkoutPlan $piano, array $righe, User $utente): void
    {
        $piano->exercises()->delete();

        $matcher = app(ExerciseMatcher::class);
        $posizione = 0;

        foreach ($righe as $riga) {
            // 🚨 Il nome si riconcilia con la libreria: se l'esercizio esiste
            // già — della palestra o della piattaforma — si riusa quello. Senza,
            // dopo un mese la libreria ha «Panca piana», «panca piana» e «Panca
            // Piana bilanciere», e il progresso su quell'esercizio risulta
            // diviso su tre righe diverse.
            $esercizio = $matcher->match((string) $riga['name'], $utente->tenant_id, $utente);

            $piano->exercises()->create([
                'exercise_id' => $esercizio->getKey(),
                'position' => $posizione++,
                'sets' => $riga['sets'] ?? null,
                'reps' => $riga['reps'] ?? null,
                'rest_sec' => $riga['rest_sec'] ?? null,
                'duration_sec' => $riga['duration_sec'] ?? null,
                'target_weight' => $riga['target_weight'] ?? null,
                'notes' => $riga['notes'] ?? null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function riassunto(WorkoutPlan $p): array
    {
        $utente = request()->user();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'notes' => $p->notes,
            'exercises_count' => $p->exercises->count(),

            // C23 — la copertina. In un elenco di sei «Full body A/B/C» e' la
            // sola cosa che le distingue a colpo d'occhio.
            'image_url' => $p->imageUrl(),
            'starts_at' => $p->starts_at?->toDateString(),
            'ends_at' => $p->ends_at?->toDateString(),
            'published_at' => $p->published_at?->toIso8601String(),

            /*
             * 🚨 Chi puo' modificarla lo dice il server.
             *
             * Senza questo campo l'app dovrebbe dedurlo confrontando
             * `created_by` con l'utente, cioe' riscrivere in Dart una regola di
             * autorizzazione che vive in `WorkoutPlanPolicy`. Due copie della
             * stessa regola divergono sempre, e la copia che sbaglia e' quella
             * che mostra all'utente un pulsante «Modifica» che il server poi
             * rifiuta.
             */
            'editable' => $utente !== null && $utente->can('update', $p),

            // Chi l'ha scritta: serve all'app per dire «scheda del tuo trainer»
            // invece di un generico elenco senza contesto.
            'author' => $p->creator === null ? null : [
                'id' => $p->creator->id,
                'name' => $p->creator->name,
                'is_me' => $p->created_by === $utente?->getKey(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dettaglio(WorkoutPlan $p): array
    {
        return array_merge($this->riassunto($p), [
            'exercises' => $p->exercises->map(fn ($r): array => [
                'id' => $r->id,
                'position' => $r->position,
                'exercise' => [
                    'id' => $r->exercise?->id,
                    'name' => $r->exercise?->name,
                    'muscle_group' => $r->exercise?->muscle_group?->value,
                    'equipment' => $r->exercise?->equipment,
                    // C23 — la miniatura accanto all'esercizio, nella scheda e
                    // nel player: durante l'allenamento un'immagine dice quale
                    // movimento e' molto piu' in fretta di un nome.
                    'image_url' => $r->exercise?->imageUrl(),
                ],
                'sets' => $r->sets,
                'reps' => $r->reps,
                'rest_sec' => $r->rest_sec,
                'duration_sec' => $r->duration_sec,
                'target_weight' => $r->target_weight,
                'notes' => $r->notes,
                'prescription' => $r->prescription(),
            ])->all(),
        ]);
    }
}
