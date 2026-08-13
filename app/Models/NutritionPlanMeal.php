<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MealType;
use App\Models\Concerns\PuoAvereAlternative;
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
    use PuoAvereAlternative;

    protected $fillable = [
        'nutrition_plan_id', 'nutrition_plan_day_id', 'alternativa_di_id',
        'meal', 'position', 'title', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'meal' => MealType::class,
            'position' => 'integer',
        ];
    }

    /**
     * Il giorno si ricava dal piano quando chi scrive non lo dice — G4.
     *
     * 🚨 Stessa ragione di `PlanExercise::booted()`: la colonna e' `NOT NULL`
     * perche' un pasto senza giorno non lo mostra nessuno, e i punti che
     * scrivono pasti sono troppi perche' tutti se lo ricordino. Il giorno si
     * ricava dal **piano che la riga dichiara gia'**.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $riga): void {
            // Verso 1: dal piano al giorno.
            if ($riga->nutrition_plan_day_id === null && $riga->nutrition_plan_id !== null) {
                $piano = NutritionPlan::withoutGlobalScopes()->find($riga->nutrition_plan_id);

                $riga->nutrition_plan_day_id = $piano?->giornoPredefinito()?->getKey();

                return;
            }

            /*
             * 🚨 **Verso 2: dal giorno al piano.** Vedi la nota lunga su
             * `PlanExercise::booted()`: un `Repeater` annidato scrive solo la
             * chiave della relazione da cui pende, e `nutrition_plan_id` e'
             * `NOT NULL`.
             */
            if ($riga->nutrition_plan_id === null && $riga->nutrition_plan_day_id !== null) {
                $giorno = NutritionPlanDay::query()->find($riga->nutrition_plan_day_id);

                $riga->nutrition_plan_id = $giorno?->nutrition_plan_id;
            }
        });
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(NutritionPlanDay::class, 'nutrition_plan_day_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }

    /**
     * Gli alimenti del pasto — **senza le alternative** (G4, D2).
     *
     * 🚨 Da qui passa anche `totals()`: contare le alternative nel totale
     * gonfierebbe ogni pasto. Un pranzo con due alternative varrebbe tre
     * pranzi, e nessuno se ne accorgerebbe finche' un trainer non fa il conto a
     * mano e ci scrive.
     */
    public function items(): HasMany
    {
        return $this->hasMany(NutritionPlanItem::class)
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti gli alimenti, alternative comprese. */
    public function itemsConAlternative(): HasMany
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
