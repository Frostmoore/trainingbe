<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'UNICO modo ammesso di dichiarare un modello che appartiene a una palestra.
 *
 * Fa due cose: applica il global scope e riempie `tenant_id` alla creazione.
 * `TenantIsolationTest` enumera per riflessione tutti i modelli e fallisce
 * nominando la classe se uno ha la colonna `tenant_id` ma non usa questo trait
 * (o BelongsToTenantOrGlobal) — è il gate della fase B1.
 *
 * @see BelongsToTenantOrGlobal per le tabelle con righe globali (es. exercises)
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Filtro esplicito su un tenant, usato dal pannello /god che gira senza
     * contesto. Non rimuove il global scope: se un contesto c'è, resta.
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        return $query->where(
            $this->qualifyColumn('tenant_id'),
            $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
        );
    }
}
