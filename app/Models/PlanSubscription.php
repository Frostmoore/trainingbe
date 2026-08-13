<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'abbonamento di un tenant a un piano — F4.1.
 *
 * 🚨 **Usa `BelongsToTenant`**, e non è una formalità: `TenantIsolationTest`
 * enumera i modelli con `tenant_id` e pretende il trait. Senza, una palestra
 * vedrebbe gli abbonamenti — e quindi i piani e i prezzi — di tutte le altre.
 *
 * ── ⚠️ Perché la storia si conserva invece di sovrascrivere una colonna ────
 *
 * Perché la domanda «perché a marzo questo cliente aveva l'AI e ad aprile no?»
 * deve avere una risposta. Con una sola colonna `tenants.plan_id` quella
 * risposta sarebbe «non si sa»: l'unico stato conservato sarebbe l'ultimo.
 */
class PlanSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'plan_id', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Gli abbonamenti in corso **adesso**.
     *
     * 🚨 `ends_at IS NULL` significa **«non scade»**, non «scaduto». Leggerlo al
     * contrario spegnerebbe l'AI a ogni cliente che non ha una data di fine —
     * cioè a tutti quelli che pagano regolarmente.
     *
     * ⚠️ E `starts_at <= now()`: un abbonamento firmato oggi e valido dal primo
     * del mese prossimo non deve dare accesso da subito.
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now())
            );
    }

    public function eAttivo(): bool
    {
        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
