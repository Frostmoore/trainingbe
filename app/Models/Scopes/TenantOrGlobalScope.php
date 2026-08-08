<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Come TenantScope, ma lascia passare anche le righe con `tenant_id` NULL.
 *
 * NULL significa «riga di libreria», di proprietà della piattaforma e visibile a
 * tutte le palestre (es. gli esercizi di base). Applicare TenantScope a una
 * tabella così nasconderebbe la libreria a chiunque, perché nessuna riga globale
 * avrebbe mai il tenant_id della richiesta.
 *
 * Il gruppo di parentesi è obbligatorio: senza, un `orWhere` in coda a una query
 * che ha già dei filtri li scavalcherebbe e mostrerebbe righe di altre palestre.
 * È il classico buco da OR non parentesizzato, e qui costerebbe una fuga di dati.
 *
 * A contesto vuoto non filtra, per le stesse ragioni di TenantScope.
 */
final class TenantOrGlobalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        $builder->where(function (Builder $query) use ($column, $tenantId): void {
            $query->where($column, $tenantId)->orWhereNull($column);
        });
    }
}
