<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\WorkoutPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le schede dell'iscritto — B4.5.
 *
 * 🚨 **Due filtri, non uno.** Il global scope limita alla palestra; qui si
 * aggiunge `member_id = utente corrente`. Il primo da solo lascerebbe un
 * iscritto leggere la scheda di un suo compagno di palestra passando un id
 * qualsiasi — che e' l'esatto contrario di quello che ci si aspetta da un dato
 * personale.
 *
 * E si filtra su `published`: una bozza e' lavoro in corso del trainer, e finche'
 * non la pubblica non deve comparire nell'app.
 */
class WorkoutPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $piani = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise'])
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'data' => $piani->map(fn (WorkoutPlan $p): array => $this->riassunto($p))->all(),
        ]);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        $piano = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise'])
            ->find($plan);

        if ($piano === null) {
            // 404 e non 403: distinguere «non esiste» da «non e' tua» direbbe a
            // chi prova gli id quali esistono.
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        return response()->json(['data' => $this->dettaglio($piano)]);
    }

    /** @return array<string, mixed> */
    private function riassunto(WorkoutPlan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'notes' => $p->notes,
            'exercises_count' => $p->exercises->count(),
            'starts_at' => $p->starts_at?->toDateString(),
            'ends_at' => $p->ends_at?->toDateString(),
            'published_at' => $p->published_at?->toIso8601String(),
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
