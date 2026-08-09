<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un alimento — o un pasto intero — salvato per riusarlo.
 *
 * `is_meal` distingue i due casi: un preferito semplice produce una voce di
 * diario, un pasto ne produce tante quante ne contiene `items`. E' la funzione
 * che decide se un diario alimentare viene usato per piu' di una settimana:
 * ricomporre a mano la stessa colazione ogni mattina e' esattamente il punto in
 * cui le persone smettono.
 */
class FoodFavorite extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $attributes = [
        'is_meal' => false,
        'times_used' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'description', 'is_meal', 'items',
        'grams', 'qty', 'unit',
        'kcal', 'protein', 'carbs', 'fat',
        'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
    ];

    protected function casts(): array
    {
        return [
            'is_meal' => 'boolean',
            'items' => 'array',
            'grams' => 'float',
            'qty' => 'float',
            'kcal' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
            'kcal_100' => 'float',
            'protein_100' => 'float',
            'carbs_100' => 'float',
            'fat_100' => 'float',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crea le voci di diario corrispondenti a questo preferito.
     *
     * Ritorna sempre una lista, anche per il preferito singolo: chi chiama non
     * deve sapere se sta richiamando un alimento o una colazione intera.
     *
     * @return list<FoodEntry>
     */
    public function addToDiary(MealType $meal, ?Carbon $when = null): array
    {
        $when ??= Carbon::now();
        $create = [];

        $righe = $this->is_meal
            ? ($this->items ?? [])
            : [[
                'description' => $this->description,
                'grams' => $this->grams,
                'qty' => $this->qty,
                'unit' => $this->unit,
                'kcal' => $this->kcal,
                'protein' => $this->protein,
                'carbs' => $this->carbs,
                'fat' => $this->fat,
                'kcal_100' => $this->kcal_100,
                'protein_100' => $this->protein_100,
                'carbs_100' => $this->carbs_100,
                'fat_100' => $this->fat_100,
            ]];

        foreach ($righe as $riga) {
            $create[] = FoodEntry::create(array_merge(
                [
                    'tenant_id' => $this->tenant_id,
                    'user_id' => $this->user_id,
                    'eaten_at' => $when,
                    'meal' => $meal,
                    'source' => FoodSource::Favorite,
                ],
                array_intersect_key($riga, array_flip([
                    'description', 'grams', 'qty', 'unit',
                    'kcal', 'protein', 'carbs', 'fat',
                    'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
                ])),
            ));
        }

        // Il contatore serve a ordinare i preferiti per uso reale: un elenco
        // alfabetico di quaranta voci e' inutilizzabile dal telefono.
        $this->forceFill([
            'times_used' => $this->times_used + 1,
            'last_used_at' => $when,
        ])->save();

        return $create;
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** I piu' usati per primi: e' l'ordine con cui si cerca davvero. */
    public function scopeMostUsed(Builder $query): Builder
    {
        return $query->orderByDesc('times_used')->orderByDesc('last_used_at');
    }
}
