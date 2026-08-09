<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FoodSource;
use App\Services\Nutrition\FoodUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un alimento prescritto dentro un pasto del piano.
 *
 * Come `NutritionPlanMeal`, **non ha `tenant_id`**: si raggiunge solo attraverso
 * il pasto, che si raggiunge solo attraverso il piano.
 */
class NutritionPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'nutrition_plan_meal_id', 'position', 'description',
        'qty', 'unit', 'grams', 'kcal', 'protein', 'carbs', 'fat', 'alternatives',
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

    /**
     * Registra questo alimento nel diario come effettivamente mangiato.
     *
     * 🚨 `source = plan` e `nutrition_plan_id` valorizzato: e' cio' che rende
     * l'aderenza al piano una join invece di una stima. Se questo metodo
     * scrivesse `manual`, il collegamento fra prescrizione e consuntivo — cioe'
     * il motivo per cui le due tabelle sono separate — andrebbe perso.
     */
    public function logAsEaten(User $member, ?Carbon $when = null): FoodEntry
    {
        $pasto = $this->meal;

        return FoodEntry::create([
            'tenant_id' => $member->tenant_id,
            'user_id' => $member->getKey(),
            'eaten_at' => $when ?? Carbon::now(),
            'meal' => $pasto->meal,
            'description' => $this->description,
            'grams' => $this->grams,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'kcal' => $this->kcal,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
            'source' => FoodSource::Plan,
            'nutrition_plan_id' => $pasto->nutrition_plan_id,
        ]);
    }
}
