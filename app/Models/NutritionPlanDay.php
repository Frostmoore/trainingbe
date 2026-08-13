<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PuoAvereAlternative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un giorno di un piano alimentare — G4 (D2).
 *
 * ⚠️ Come `WorkoutPlanDay`: nessun `tenant_id`, perche' si arriva qui solo
 * passando dal piano.
 */
class NutritionPlanDay extends Model
{
    use PuoAvereAlternative;

    protected $fillable = [
        'nutrition_plan_id', 'alternativa_di_id', 'position', 'name', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }

    /** I pasti di questo giorno — **solo i principali** (vedi `PuoAvereAlternative`). */
    public function meals(): HasMany
    {
        return $this->hasMany(NutritionPlanMeal::class, 'nutrition_plan_day_id')
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti i pasti, alternative comprese. */
    public function mealsConAlternative(): HasMany
    {
        return $this->hasMany(NutritionPlanMeal::class, 'nutrition_plan_day_id')->orderBy('position');
    }
}
