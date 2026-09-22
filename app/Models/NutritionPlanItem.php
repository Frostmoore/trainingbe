<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PuoAvereAlternative;
use App\Services\Nutrition\FoodUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un alimento prescritto dentro un pasto del piano.
 *
 * Come `NutritionPlanMeal`, **non ha `tenant_id`**: si raggiunge solo attraverso
 * il pasto, che si raggiunge solo attraverso il piano.
 */
class NutritionPlanItem extends Model
{
    use HasFactory;
    use PuoAvereAlternative;

    protected $fillable = [
        'nutrition_plan_meal_id', 'alternativa_di_id', 'position', 'description',
        'qty', 'unit', 'grams', 'kcal', 'protein', 'carbs', 'fat',
        'origine_valori',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'qty' => 'float',
            'grams' => 'float',
            'kcal' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',

            /*
             * ⚠️ **La colonna `alternatives` non esiste più** — G4.5 l'ha
             * convertita in righe, e la migrazione `2026_08_14_150000` l'ha
             * tolta.
             *
             * 🚨 Il cast era stato aggiunto il 13/08/2026, poche ore prima,
             * perché mancava del tutto e senza di lui le alternative uscivano
             * dall'API **come stringa**. È durato mezza giornata: la vera
             * risposta non era un cast, era che un'alternativa senza macro
             * proprie non è usabile — e quindi deve essere una riga.
             *
             * 💡 Le alternative si leggono ora da `alternative()`, il trait
             * `PuoAvereAlternative`.
             */
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->grams ??= FoodUnit::toGrams($item->qty, $item->unit);
        });
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(NutritionPlanMeal::class, 'nutrition_plan_meal_id');
    }

    /*
     * ⛔ Qui c'era `logAsEaten()`, che scriveva l'alimento in `food_entries`. La
     * rotta che lo usava è stata ritirata in G9.4, e il 22/09/2026 la tabella è
     * sparita (A1 di `plan_blocco_A.md`): il diario è sul telefono.
     */
}
