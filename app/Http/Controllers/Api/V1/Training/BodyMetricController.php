<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\BodyMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Peso e misure — B4.5.
 *
 * Una riga al giorno: il salvataggio e' un `updateOrCreate` perche' la seconda
 * pesata dello stesso giorno e' una correzione, non un dato nuovo. Trattarla
 * come dato nuovo produrrebbe grafici con oscillazioni che non sono successe.
 */
class BodyMetricController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $da = $request->query('from');
        $a = $request->query('to');

        $query = BodyMetric::query()
            ->forUser($request->user())
            ->orderByDesc('date');

        if (is_string($da)) {
            $query->whereDate('date', '>=', Carbon::parse($da));
        }

        if (is_string($a)) {
            $query->whereDate('date', '<=', Carbon::parse($a));
        }

        $righe = $query->limit(min(730, (int) $request->integer('limit', 180)))->get();

        return response()->json([
            'data' => $righe->map(fn (BodyMetric $m): array => $this->riga($m))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'body_fat_pct' => ['nullable', 'numeric', 'min:1', 'max:80'],
            'waist_cm' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'chest_cm' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'arm_cm' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'thigh_cm' => ['nullable', 'numeric', 'min:10', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $utente = $request->user();
        $giorno = isset($dati['date']) ? Carbon::parse($dati['date']) : Carbon::today();

        unset($dati['date']);

        $misura = BodyMetric::updateOrCreate(
            ['user_id' => $utente->getKey(), 'date' => $giorno->toDateString()],
            array_merge($dati, ['tenant_id' => $utente->tenant_id]),
        );

        return response()->json(['data' => $this->riga($misura)], 201);
    }

    /** @return array<string, mixed> */
    private function riga(BodyMetric $m): array
    {
        return [
            'id' => $m->id,
            'date' => $m->date->toDateString(),
            'weight_kg' => $m->weight_kg,
            'body_fat_pct' => $m->body_fat_pct,
            'waist_cm' => $m->waist_cm,
            'chest_cm' => $m->chest_cm,
            'arm_cm' => $m->arm_cm,
            'thigh_cm' => $m->thigh_cm,
            'notes' => $m->notes,
        ];
    }
}
