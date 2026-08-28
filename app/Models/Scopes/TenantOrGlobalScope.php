<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Contracts\LibreriaCondivisaConGliIscritti;
use App\Models\User;
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
        $visibili = $this->tenantVisibili($model, $tenantId);

        $builder->where(function (Builder $query) use ($column, $visibili): void {
            $query->whereIn($column, $visibili)->orWhereNull($column);
        });
    }

    /**
     * I tenant le cui righe si possono leggere — 3b-M, 28/08/2026.
     *
     * ══ 🚨 SI ALLARGA SOLO DOVE QUALCUNO L'HA SCRITTO ═════════════════════
     *
     * ⛔ Senza il controllo sull'interfaccia, la prima tabella che domani usa
     * `BelongsToTenantOrGlobal` si ritroverebbe le righe di altri tenant
     * **senza che nessuno l'abbia deciso**. ⚠️ E nessun test se ne
     * accorgerebbe: vedrebbe righe **in piu'**, non in meno — cioe' il verso in
     * cui un difetto di isolamento non fa rumore.
     *
     * ══ ⚠️ E SOLO QUANDO IL CONTESTO E' QUELLO DI CHI GUARDA ══════════════
     *
     * 🚨 Il contesto e' **ambientale**, l'utente no. In un pannello di
     * piattaforma, o durante un'impersonificazione, il contesto puo' essere il
     * tenant di **un'altra** palestra: aggiungerci le librerie personali di chi
     * ha fatto il login mescolerebbe due mondi.
     *
     * 💡 Quando i due non coincidono si torna esattamente al comportamento di
     * prima. ⛔ Fuori da una richiesta HTTP — comandi, code, seeder —
     * `auth()->user()` e' `null` e non si allarga niente.
     *
     * @return list<int>
     */
    private function tenantVisibili(Model $model, int $tenantId): array
    {
        if (! $model instanceof LibreriaCondivisaConGliIscritti) {
            return [$tenantId];
        }

        $utente = auth()->user();

        if (! $utente instanceof User || $utente->tenant_id !== $tenantId) {
            return [$tenantId];
        }

        return $utente->librerieVisibili();
    }
}
