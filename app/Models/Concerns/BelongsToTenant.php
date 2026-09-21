<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

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

            /*
             * ══ 🚨 E SE NON C'È NEMMENO NEL CONTESTO, NON SI SCRIVE ═════════
             *
             * 🆕 21/09/2026, dopo il difetto del pannello: creare una scheda
             * dava *«SQLSTATE[23000]: Column 'tenant_id' cannot be null»*, con
             * mezzo SQL in faccia al committente.
             *
             * ⛔ **Quel messaggio non dice niente a nessuno**, e soprattutto non
             * dice la cosa che conta: il contesto del tenant era vuoto. La
             * causa vera era nei middleware (vedi `AdminPanelProvider`), e
             * questa riga è la **seconda serratura**: il giorno che il contesto
             * tornerà vuoto per un'altra strada — un comando, una coda, un
             * pannello nuovo — si leggerà di cosa si tratta invece di andare a
             * cercarlo nel log del database.
             *
             * 💡 Non cambia niente per chi scrive `tenant_id` a mano: quel caso
             * esce dall'`if` qui sopra senza toccare questo.
             */
            if ($model->tenantObbligatorio() && $model->getAttribute('tenant_id') === null) {
                throw new RuntimeException(sprintf(
                    'Sto creando un %s senza nessuna palestra: il contesto del tenant è vuoto. '
                    .'Passa `tenant_id` a mano, oppure esegui dentro TenantContext::runAs().',
                    class_basename($model),
                ));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 🚨 **Questo modello può esistere senza una palestra?**
     *
     * Il default è **no**, ed è il caso di diciassette modelli su diciotto: una
     * scheda, un messaggio, un abbonamento senza palestra sono righe orfane, e
     * il database le rifiuta comunque — con un errore che parla di vincoli
     * d'integrità invece che di contesto.
     *
     * ⚠️ **`User` lo sovrascrive**, e non è un'eccezione di comodo: il super
     * admin della piattaforma **non sta in nessuna palestra**, e `users.tenant_id`
     * è nullable apposta.
     */
    public function tenantObbligatorio(): bool
    {
        return true;
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
