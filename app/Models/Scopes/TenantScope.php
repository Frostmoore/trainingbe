<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra ogni query sul tenant corrente.
 *
 * A contesto vuoto NON filtra, di proposito: migration, seeder, comandi artisan
 * e il pannello /god girano legittimamente senza tenant. La sicurezza non sta
 * qui — sta nel fatto che ogni richiesta HTTP autenticata imposta sempre il
 * contesto (middleware ResolveTenant) e che /god è protetto da policy.
 *
 * Se lo scope negasse tutto a contesto vuoto, ogni comando di manutenzione
 * richiederebbe un bypass, e i bypass diventerebbero rumore di fondo: il modo
 * classico in cui un controllo di sicurezza smette di essere letto.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $tenantId,
        );
    }
}
