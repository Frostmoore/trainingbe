<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthMetric;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una misura dell'orologio: HRV, battito a riposo, battito medio — D3.
 */
class HealthReading extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'source', 'metric', 'measured_at', 'day', 'value',
    ];

    protected function casts(): array
    {
        return [
            'metric' => HealthMetric::class,
            'measured_at' => 'datetime',
            'day' => 'date',
            'value' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    public function scopeOfMetric(Builder $query, HealthMetric $metric): Builder
    {
        return $query->where('metric', $metric->value);
    }

    public function scopeBetweenDays(Builder $query, Carbon $da, Carbon $a): Builder
    {
        return $query->whereBetween('day', [$da->toDateString(), $a->toDateString()]);
    }

    /**
     * L'ultima misura di un tipo, e la media dei giorni precedenti.
     *
     * 🚨 **Il valore da solo non dice niente.** Un HRV di 42 ms è ottimo per
     * qualcuno e allarmante per qualcun altro: conta lo **scostamento dalla
     * media di quella persona**. Restituirli insieme è ciò che impedisce a chi
     * legge di interpretare un numero assoluto come se fosse un voto.
     *
     * @return array{value: float, day: string, average: float|null, delta_pct: float|null}|null
     */
    public static function latestWithBaseline(User $utente, HealthMetric $metric, int $giorniBase = 7): ?array
    {
        $ultima = static::query()
            ->forUser($utente)
            ->ofMetric($metric)
            ->orderByDesc('measured_at')
            ->first();

        if ($ultima === null) {
            return null;
        }

        // La media **esclude** la misura di oggi: confrontarla con una media che
        // la contiene la avvicinerebbe artificialmente allo zero, e uno
        // scostamento vero sembrerebbe piccolo.
        $media = static::query()
            ->forUser($utente)
            ->ofMetric($metric)
            ->where('day', '<', $ultima->day->toDateString())
            ->where('day', '>=', $ultima->day->copy()->subDays($giorniBase)->toDateString())
            ->avg('value');

        $valore = (float) $ultima->value;
        $media = $media === null ? null : (float) $media;

        return [
            'value' => $valore,
            'day' => $ultima->day->toDateString(),
            'average' => $media === null ? null : round($media, 1),
            'delta_pct' => ($media === null || $media <= 0)
                ? null
                : round(($valore - $media) / $media * 100, 1),
        ];
    }
}
