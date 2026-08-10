<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use App\Services\Nutrition\DiaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * I preferiti — B5.5.
 *
 * 🚨 **E' la funzione che decide se il diario viene usato per piu' di una
 * settimana.** Ricomporre a mano la stessa colazione ogni mattina e' esattamente
 * il punto in cui le persone smettono di registrare cosa mangiano, e da li' in
 * poi tutto il resto — target, aderenza, consigli — non ha piu' dati su cui
 * lavorare.
 */
class FoodFavoriteController extends Controller
{
    public function __construct(
        private readonly DiaryService $diary,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $preferiti = FoodFavorite::query()
            ->forUser($request->user())
            ->mostUsed()
            ->limit(min(200, (int) $request->integer('limit', 100)))
            ->get();

        return response()->json([
            'data' => $preferiti->map(fn (FoodFavorite $f): array => $this->riga($f))->all(),
        ]);
    }

    /**
     * Salva una voce di diario come preferito.
     *
     * Si parte da una voce esistente invece di far compilare un modulo: chi ha
     * appena registrato qualcosa di buono vuole salvarlo con un tocco, non
     * riscriverlo.
     */
    public function fromEntry(Request $request, int $entry): JsonResponse
    {
        $voce = FoodEntry::query()->forUser($request->user())->find($entry);

        if ($voce === null) {
            return response()->json(['message' => __('Voce non trovata.')], 404);
        }

        $preferito = FoodFavorite::create([
            'tenant_id' => $voce->tenant_id,
            'user_id' => $voce->user_id,
            'description' => $voce->description,
            'is_meal' => false,
            'grams' => $voce->grams,
            'qty' => $voce->qty,
            'unit' => $voce->unit,
            'kcal' => $voce->kcal,
            'protein' => $voce->protein,
            'carbs' => $voce->carbs,
            'fat' => $voce->fat,
            'kcal_100' => $voce->kcal_100,
            'protein_100' => $voce->protein_100,
            'carbs_100' => $voce->carbs_100,
            'fat_100' => $voce->fat_100,
        ]);

        return response()->json(['data' => $this->riga($preferito)], 201);
    }

    /**
     * Salva un pasto intero di un giorno come preferito.
     *
     * @see FoodFavorite::addToDiary() per come viene poi rimesso nel diario
     */
    public function storeMeal(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'meal' => ['required', Rule::enum(MealType::class)],
            'date' => ['nullable', 'date'],
        ]);

        $pasto = MealType::tryFrom($dati['meal']);

        if ($pasto === null) {
            return response()->json(['message' => __('Pasto non valido.')], 422);
        }

        $giorno = isset($dati['date']) ? Carbon::parse($dati['date']) : Carbon::today();
        $utente = $request->user();

        $voci = FoodEntry::query()
            ->forUser($utente)
            ->onDate($giorno)
            ->where('meal', $pasto->value)
            ->get();

        if ($voci->isEmpty()) {
            return response()->json(['message' => __('Non c\'e\' niente da salvare in questo pasto.')], 422);
        }

        $totali = FoodEntry::totals($voci);

        $preferito = FoodFavorite::create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'description' => $dati['description'],
            'is_meal' => true,
            'items' => $voci->map(fn (FoodEntry $v): array => [
                'description' => $v->description,
                'grams' => $v->grams,
                'qty' => $v->qty,
                'unit' => $v->unit,
                'kcal' => $v->kcal,
                'protein' => $v->protein,
                'carbs' => $v->carbs,
                'fat' => $v->fat,
                'kcal_100' => $v->kcal_100,
                'protein_100' => $v->protein_100,
                'carbs_100' => $v->carbs_100,
                'fat_100' => $v->fat_100,
            ])->all(),
            'kcal' => $totali['kcal'],
            'protein' => $totali['protein'],
            'carbs' => $totali['carbs'],
            'fat' => $totali['fat'],
        ]);

        return response()->json(['data' => $this->riga($preferito)], 201);
    }

    /** Rimette il preferito nel diario. Un pasto intero produce piu' voci. */
    public function add(Request $request, int $favorite): JsonResponse
    {
        $preferito = FoodFavorite::query()->forUser($request->user())->find($favorite);

        if ($preferito === null) {
            return response()->json(['message' => __('Preferito non trovato.')], 404);
        }

        $dati = $request->validate([
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
        ]);

        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();
        $pasto = (isset($dati['meal']) ? MealType::tryFrom($dati['meal']) : null)
            ?? MealType::fromProfile($quando, $request->user()->profile?->meal_hours);

        $voci = $preferito->addToDiary($pasto, $quando);

        return response()->json([
            'data' => array_map(fn (FoodEntry $v): array => $this->diary->voce($v), $voci),
        ], 201);
    }

    public function destroy(Request $request, int $favorite): JsonResponse
    {
        $preferito = FoodFavorite::query()->forUser($request->user())->find($favorite);

        if ($preferito === null) {
            return response()->json(['message' => __('Preferito non trovato.')], 404);
        }

        $preferito->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function riga(FoodFavorite $f): array
    {
        return [
            'id' => $f->id,
            'description' => $f->description,
            'is_meal' => $f->is_meal,
            'items_count' => $f->is_meal ? count($f->items ?? []) : 1,
            'grams' => $f->grams,
            'qty' => $f->qty,
            'unit' => $f->unit,
            'kcal' => $f->kcal,
            'protein' => $f->protein,
            'carbs' => $f->carbs,
            'fat' => $f->fat,
            'times_used' => $f->times_used,
        ];
    }
}
