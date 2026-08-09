<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\MealType;
use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\NutritionPlan;
use App\Services\Nutrition\DiaryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Il diario alimentare — B5.5.
 *
 * Tutte le somme passano da `DiaryService`, mai da qui: e' la regola che impedisce
 * a diario, dashboard e app di rispondere tre numeri diversi.
 */
class DiaryController extends Controller
{
    public function __construct(
        private readonly DiaryService $diary,
    ) {}

    // ───────────────────────── la giornata ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $giorno = $this->giorno($request);

        return response()->json(['data' => $this->diary->forDate($request->user(), $giorno)]);
    }

    public function targets(Request $request): JsonResponse
    {
        $giorno = $this->giorno($request);

        return response()->json([
            'data' => $this->diary->targetsFor($request->user(), $giorno),
        ]);
    }

    // ───────────────────────── voci ─────────────────────────

    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'meal' => ['nullable', 'string'],
            'eaten_at' => ['nullable', 'date'],
            'grams' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'qty' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:16'],
            'kcal' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'protein' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:2000'],
        ]);

        $utente = $request->user();
        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();

        $voce = FoodEntry::create(array_merge($dati, [
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'eaten_at' => $quando,
            // Il pasto si deduce dall'ora quando l'app non lo dice: chiedere
            // «che pasto era?» per una banana alle 16 e' un attrito che fa
            // smettere di registrare.
            'meal' => $this->pasto($dati['meal'] ?? null, $quando),
        ]));

        return response()->json(['data' => $this->diary->voce($voce)], 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $voce = $this->voceDi($request, $entry);

        if ($voce === null) {
            return response()->json(['message' => __('Voce non trovata.')], 404);
        }

        $dati = $request->validate([
            'description' => ['sometimes', 'string', 'max:255'],
            'meal' => ['sometimes', 'string'],
            'eaten_at' => ['sometimes', 'date'],
            'grams' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:20000'],
            'qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:16'],
            'kcal' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:20000'],
            'protein' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'carbs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'fat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
        ]);

        if (isset($dati['meal'])) {
            $dati['meal'] = $this->pasto($dati['meal'], $voce->eaten_at);
        }

        $voce->fill($dati)->save();

        return response()->json(['data' => $this->diary->voce($voce->refresh())]);
    }

    public function destroy(Request $request, int $entry): JsonResponse
    {
        $voce = $this->voceDi($request, $entry);

        if ($voce === null) {
            return response()->json(['message' => __('Voce non trovata.')], 404);
        }

        $voce->delete();

        return response()->json(null, 204);
    }

    // ───────────────────────── bruciate dichiarate ─────────────────────────

    /**
     * L'override manuale delle calorie bruciate del giorno.
     *
     * 🚨 Sostituisce, non si somma: e' una dichiarazione complessiva («oggi ho
     * bruciato 800»), e sommarla alle sessioni raddoppierebbe la giornata di chi
     * corregge il numero dopo essersi allenato.
     */
    public function storeBurn(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'kcal' => ['required', 'integer', 'min:0', 'max:20000'],
        ]);

        $giorno = isset($dati['date']) ? Carbon::parse($dati['date']) : Carbon::today();

        $riga = DailyBurn::put($request->user(), $giorno, $dati['kcal']);

        return response()->json(['data' => [
            'date' => $riga->date->toDateString(),
            'kcal' => $riga->kcal,
        ]], 201);
    }

    // ───────────────────────── piano attivo ─────────────────────────

    public function plan(Request $request): JsonResponse
    {
        $piano = NutritionPlan::activeFor($request->user());

        if ($piano === null) {
            return response()->json(['data' => null]);
        }

        $piano->load('meals.items');

        return response()->json(['data' => [
            'id' => $piano->id,
            'name' => $piano->name,
            'notes' => $piano->notes,
            'targets' => [
                'kcal' => $piano->target_kcal,
                'protein_g' => $piano->target_protein_g,
                'carbs_g' => $piano->target_carbs_g,
                'fat_g' => $piano->target_fat_g,
            ],
            'starts_at' => $piano->starts_at?->toDateString(),
            'ends_at' => $piano->ends_at?->toDateString(),
            'meals' => $piano->meals->map(fn ($pasto): array => [
                'id' => $pasto->id,
                'meal' => $pasto->meal->value,
                'label' => $pasto->meal->label(),
                'title' => $pasto->title,
                'notes' => $pasto->notes,
                'totals' => $pasto->totals(),
                'items' => $pasto->items->map(fn ($i): array => [
                    'id' => $i->id,
                    'description' => $i->description,
                    'qty' => $i->qty,
                    'unit' => $i->unit,
                    'grams' => $i->grams,
                    'kcal' => $i->kcal,
                    'protein' => $i->protein,
                    'carbs' => $i->carbs,
                    'fat' => $i->fat,
                    'alternatives' => $i->alternatives,
                ])->all(),
            ])->all(),
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    private function giorno(Request $request): Carbon
    {
        $d = $request->query('date');

        return is_string($d) && $d !== '' ? Carbon::parse($d) : Carbon::today();
    }

    private function voceDi(Request $request, int $id): ?FoodEntry
    {
        return FoodEntry::query()->forUser($request->user())->find($id);
    }

    private function pasto(?string $valore, ?Carbon $quando): MealType
    {
        $tipo = $valore !== null ? MealType::tryFrom($valore) : null;

        return $tipo ?? MealType::fromHour((int) ($quando ?? now())->format('G'));
    }
}
