<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Il secondo filtro, oltre a quello per palestra.
     *
     * ⚠️ Aggiunto in C3 perché era l'unico modello personale a non averlo, e
     * l'assenza costringe chi scrive una query nuova a mettere il `where` a
     * mano — che è esattamente il momento in cui ci si dimentica.
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Scrive o aggiorna il valore del giorno.
     *
     * 💡 `date` e' una colonna **`date`**, cioe' gia' un'etichetta: qui serve
     * `$giorno->etichetta` e non la finestra. ⚠️ E' l'altra meta' di A3, quella
     * che si sbaglia nel verso opposto — passare un istante e prenderne la data
     * darebbe «le 22:00 di ieri», che come etichetta e' **ieri**.
     */
    public static function put(User $user, GiornoLocale $giorno, int $kcal): self
    {
        return static::updateOrCreate(
            ['user_id' => $user->getKey(), 'date' => $giorno->etichetta],
            ['tenant_id' => $user->tenant_id, 'kcal' => $kcal],
        );
    }

    /**
     * Toglie l'override: da qui in poi vale di nuovo la stima.
     *
     * 🚨 **Si cancella la riga, non si scrive zero.** Una riga a zero vuol
     * dire «oggi ho bruciato zero», che e' una dichiarazione; l'assenza di riga
     * vuol dire «non lo so», che e' il permesso a stimare. Confonderle
     * azzererebbe il margine calorico di chi voleva solo tornare alla stima.
     */
    public static function dimentica(User $user, GiornoLocale $giorno): void
    {
        static::query()
            ->where('user_id', $user->getKey())
            ->whereDate('date', $giorno->etichetta)
            ->delete();
    }

    public static function forDate(User|int $user, GiornoLocale $giorno): ?self
    {
        return static::query()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->whereDate('date', $giorno->etichetta)
            ->first();
    }
}
