<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pasto dentro un piano alimentare.
 *
 * Come `PlanExercise`, **non ha `tenant_id`**: si raggiunge solo attraverso il
 * piano. La regola e i suoi limiti sono scritti in `PlanExercise`.
 */
class NutritionPlanMeal extends Model
{
    use HasFactory;

    protected $fillable = ['nutrition_plan_id', 'meal', 'position', 'title', 'notes'];

    protected function casts(): array
    {
        return [
            'meal' => MealType::class,
            'position' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NutritionPlanItem::class)->orderBy('position');
    }

    /**
     * I totali prescritti da questo pasto.
     *
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    public function totals(): array
    {
        $t = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($this->items as $item) {
            $t['kcal'] += (float) ($item->kcal ?? 0);
            $t['protein'] += (float) ($item->protein ?? 0);
            $t['carbs'] += (float) ($item->carbs ?? 0);
            $t['fat'] += (float) ($item->fat ?? 0);
        }

        return array_map(static fn (float $n): float => round($n, 2), $t);
    }
}
