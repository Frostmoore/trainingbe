<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La libreria esercizi vista dall'app — B4.5.
 *
 * Restituisce i globali **piu'** quelli della palestra: e' `TenantOrGlobalScope`
 * a farlo, senza che questo controller debba saperne niente. L'iscritto la usa
 * per cercare un esercizio quando registra una serie fuori scheda.
 */
class ExerciseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Exercise::query()->ordered();

        if (($termine = trim((string) $request->query('q'))) !== '') {
            $query->search($termine);
        }

        if (($gruppo = $request->query('muscle_group')) !== null) {
            $query->where('muscle_group', $gruppo);
        }

        $esercizi = $query->limit(min(200, (int) $request->integer('limit', 100)))->get();

        return response()->json([
            'data' => $esercizi->map(fn (Exercise $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'muscle_group' => $e->muscle_group?->value,
                'equipment' => $e->equipment,
                // Serve all'app per distinguere «esercizio della piattaforma» da
                // «aggiunto dalla mia palestra», che e' l'unica differenza
                // percepibile fra i due.
                'is_global' => $e->isGlobal(),
            ])->all(),
        ]);
    }
}
