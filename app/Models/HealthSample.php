<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SleepStage;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un tratto di sonno rilevato dall'orologio — B9.1.
 */
class HealthSample extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'source', 'night', 'started_at', 'ended_at', 'stage',
    ];

    protected function casts(): array
    {
        return [
            'night' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'stage' => SleepStage::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutes(): int
    {
        return max(0, (int) round($this->started_at->diffInSeconds($this->ended_at) / 60));
    }

    /**
     * A quale notte appartiene un istante.
     *
     * 🚨 Un campione delle 02:00 appartiene alla notte del giorno **precedente**.
     * Senza questa regola, chi va a letto alle 23:30 avrebbe il sonno spezzato
     * su due giorni e nessuna delle due notti risulterebbe sufficiente.
     *
     * Lo spartiacque e' mezzogiorno: chiunque dorma a cavallo di mezzogiorno sta
     * facendo qualcosa che questo sistema non e' in grado di interpretare
     * comunque.
     */
    public static function nightOf(Carbon $istante): Carbon
    {
        return $istante->hour < 12
            ? $istante->copy()->subDay()->startOfDay()
            : $istante->copy()->startOfDay();
    }

    public function scopeForUser(Builder $query, User|int $utente): Builder
    {
        return $query->where('user_id', $utente instanceof User ? $utente->getKey() : $utente);
    }

    public function scopeForNight(Builder $query, Carbon $notte): Builder
    {
        return $query->whereDate('night', $notte->toDateString());
    }
}
