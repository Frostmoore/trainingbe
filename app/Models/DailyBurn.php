<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le calorie bruciate in un giorno, **dichiarate dall'utente**.
 *
 * 🚨 E' l'override manuale, e per costruzione batte qualunque stima: una riga
 * qui vuol dire «per oggi il numero e' questo». `WorkoutCalorieService` la legge
 * prima di calcolare e, se c'e', non calcola.
 *
 * Una riga al giorno per persona: il vincolo `UNIQUE` rende il salvataggio un
 * aggiornamento, non una sequenza di dichiarazioni da sommare.
 */
class DailyBurn extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'date', 'kcal'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kcal' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Scrive o aggiorna il valore del giorno. */
    public static function put(User $user, Carbon $date, int $kcal): self
    {
        return static::updateOrCreate(
            ['user_id' => $user->getKey(), 'date' => $date->toDateString()],
            ['tenant_id' => $user->tenant_id, 'kcal' => $kcal],
        );
    }

    public static function forDate(User|int $user, Carbon $date): ?self
    {
        return static::query()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->whereDate('date', $date->toDateString())
            ->first();
    }
}
