<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Nutrition\FoodUnit;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una voce del diario alimentare.
 *
 * 🚨 **`grams` e' la fonte di verita'.** `qty` e `unit` sono come l'utente lo ha
 * scritto; i grammi sono cio' su cui si calcola. Il modello li deriva da solo
 * quando mancano — vedi il boot — cosi' nessun chiamante puo' salvare una voce
 * senza il dato che serve alle somme.
 */
class FoodEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'eaten_at', 'meal', 'description',
        'grams', 'qty', 'unit',
        'kcal', 'protein', 'carbs', 'fat',
        'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
        'source', 'ai_raw', 'nutrition_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'meal' => MealType::class,
            'source' => FoodSource::class,
            'ai_raw' => 'array',
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $voce): void {
            // I grammi mancanti si derivano da quantita' e unita': meglio un
            // valore derivato che una voce che non entra in nessun totale.
            if ($voce->grams === null) {
                $voce->grams = FoodUnit::toGrams($voce->qty, $voce->unit);
            }

            // Se conosciamo i valori per 100 g ma non gli assoluti, si
            // calcolano. L'AI risponde spesso solo con i primi.
            if ($voce->grams !== null && $voce->kcal === null && $voce->kcal_100 !== null) {
                $fattore = $voce->grams / 100;

                $voce->kcal = round($voce->kcal_100 * $fattore, 2);
                $voce->protein ??= $voce->protein_100 !== null ? round($voce->protein_100 * $fattore, 2) : null;
                $voce->carbs ??= $voce->carbs_100 !== null ? round($voce->carbs_100 * $fattore, 2) : null;
                $voce->fat ??= $voce->fat_100 !== null ? round($voce->fat_100 * $fattore, 2) : null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Le voci di **un giorno di chi guarda** — A3.
     *
     * 🚨 **Il parametro e' un `GiornoLocale` e non un `Carbon`, ed e' il punto
     * in cui il difetto e' stato chiuso.** Prima questo scope rifaceva
     * `startOfDay()`/`endOfDay()` sul `Carbon` ricevuto, cioe' **in UTC**: la
     * cena delle 00:30 di Roma cadeva nel giorno prima, e il diario si apriva
     * sul giorno sbagliato.
     *
     * ⚠️ Cambiare il tipo e' deliberato. Accettare ancora un `Carbon` avrebbe
     * lasciato in piedi ogni chiamata sbagliata **senza un solo segnale**; cosi'
     * invece chi passa un istante prende un errore di tipo, subito.
     */
    public function scopeOnDate(Builder $query, GiornoLocale $giorno): Builder
    {
        return $query->whereBetween('eaten_at', $giorno->finestra());
    }

    /**
     * I totali di un insieme di voci.
     *
     * Sta qui e non nei controller perche' la somma dei macro e' la stessa in
     * diario, dashboard e API: tre implementazioni sono tre numeri diversi che
     * aspettano di divergere — e' successo esattamente cosi' nell'app storica.
     *
     * @param  iterable<self>  $voci
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    public static function totals(iterable $voci): array
    {
        $t = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($voci as $v) {
            $t['kcal'] += (float) ($v->kcal ?? 0);
            $t['protein'] += (float) ($v->protein ?? 0);
            $t['carbs'] += (float) ($v->carbs ?? 0);
            $t['fat'] += (float) ($v->fat ?? 0);
        }

        return array_map(static fn (float $n): float => round($n, 2), $t);
    }
}
