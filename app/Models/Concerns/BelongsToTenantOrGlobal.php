<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantOrGlobalScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante di BelongsToTenant per le tabelle con `tenant_id` NULLABLE, dove NULL
 * vuol dire «riga globale della piattaforma».
 *
 * Oggi la usa solo `exercises`: la libreria esercizi di base è condivisa, e ogni
 * palestra può aggiungerci i propri. Una palestra vede quindi «i suoi + i globali»,
 * ma può modificare soltanto i suoi (il vincolo è nella policy, non qui: uno scope
 * di lettura non è il posto dove far rispettare i permessi di scrittura).
 *
 * `TenantIsolationTest` accetta questo trait come alternativa valida a
 * BelongsToTenant, ma SOLO se la colonna è davvero nullable a schema: se qualcuno
 * la rende NOT NULL, il test deve tornare a pretendere BelongsToTenant.
 *
 * @see BelongsToTenant per il caso normale
 */
trait BelongsToTenantOrGlobal
{
    public static function bootBelongsToTenantOrGlobal(): void
    {
        static::addGlobalScope(new TenantOrGlobalScope);

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

    /** Solo le righe della palestra: esclude la libreria globale. */
    public function scopeOwnedByTenant(Builder $query, Tenant|int|null $tenant = null): Builder
    {
        $tenantId = match (true) {
            $tenant instanceof Tenant => $tenant->getKey(),
            is_int($tenant) => $tenant,
            default => app(TenantContext::class)->id(),
        };

        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /** Solo la libreria della piattaforma. */
    public function scopeGlobalOnly(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('tenant_id'));
    }

    /** La riga è di proprietà della palestra corrente (quindi modificabile)? */
    public function isGlobal(): bool
    {
        return $this->getAttribute('tenant_id') === null;
    }
}
