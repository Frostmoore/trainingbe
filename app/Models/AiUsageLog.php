<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiFeature;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Una chiamata AI, con quello che e' costata.
 *
 * Come `AuditLog`: niente `updated_at`, niente modifiche. Un contatore che si
 * puo' correggere non e' un contatore.
 */
class AiUsageLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cost_millicents' => 'integer',
            'duration_ms' => 'integer',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** I token che contano per la quota: input + output. */
    public function billableTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeInMonth(Builder $query, ?Carbon $month = null): Builder
    {
        $month ??= Carbon::now();

        return $query->whereBetween('created_at', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        ]);
    }

    /**
     * I token consumati da una palestra nel mese.
     *
     * 🚨 `withoutGlobalScopes()` e un `where` esplicito: il conteggio deve
     * funzionare anche dal pannello di piattaforma, che gira **senza contesto**
     * — e li' il global scope non filtrerebbe niente, restituendo il totale di
     * tutti i clienti come se fosse di uno solo.
     */
    public static function tokensForTenant(int $tenantId, ?Carbon $month = null): int
    {
        return (int) static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->inMonth($month)
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    public static function costForTenant(int $tenantId, ?Carbon $month = null): int
    {
        return (int) static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->inMonth($month)
            ->sum('cost_millicents');
    }

    /** Il costo in euro/dollari leggibile: i millesimi di centesimo diviso 100.000. */
    public static function millicentsToCurrency(int $millicents): float
    {
        return round($millicents / 100_000, 2);
    }
}
