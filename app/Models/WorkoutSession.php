<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KcalSource;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un allenamento fatto (o in corso).
 *
 * `ended_at` null = sessione aperta. E' lo stato normale mentre l'utente si sta
 * allenando, non un'anomalia: l'app apre la sessione all'inizio e la chiude alla
 * fine, e fra i due momenti passano quaranta minuti in cui il telefono puo'
 * anche perdere la rete.
 */
class WorkoutSession extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'workout_plan_id',
        'started_at', 'ended_at', 'kcal_burned', 'kcal_source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'kcal_burned' => 'integer',
            'kcal_source' => KcalSource::class,
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(SessionSet::class)->orderBy('set_number');
    }

    // ───────────────────────── stato ─────────────────────────

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Durata in minuti.
     *
     * Su una sessione ancora aperta conta da `started_at` a **adesso**: e' il
     * numero che l'app mostra mentre l'allenamento e' in corso, e restituire
     * `null` costringerebbe ogni chiamante a rifare lo stesso calcolo.
     */
    public function durationMinutes(): int
    {
        $fine = $this->ended_at ?? Carbon::now();

        return max(0, (int) round($this->started_at->diffInSeconds($fine) / 60));
    }

    /**
     * Il valore salvato e' stato scritto da una persona?
     *
     * 🚨 E' il controllo che protegge la regola «il manuale batte la stima»: chi
     * ricalcola deve chiedere **questo** e non «c'e' gia' un numero?».
     */
    public function hasManualKcal(): bool
    {
        return $this->kcal_source === KcalSource::Manual && $this->kcal_burned !== null;
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Le sessioni di **un giorno di chi guarda** — A3.
     *
     * ⚠️ Stessa regola di `FoodEntry::scopeOnDate()`: il confine del giorno e'
     * quello della persona, non quello di UTC. Chi si allena alle 22:30 di Roma
     * finiva nel giorno dopo, e le calorie bruciate comparivano nella giornata
     * sbagliata.
     */
    public function scopeOnDate(Builder $query, GiornoLocale $giorno): Builder
    {
        return $query->whereBetween('started_at', $giorno->finestra());
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }
}
