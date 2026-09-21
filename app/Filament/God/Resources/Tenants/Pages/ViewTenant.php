<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Pages;

use App\Filament\God\Resources\Tenants\TenantResource;
use App\Filament\God\Resources\Tenants\Widgets\IlGiroDiSoldiDellaPalestra;
use App\Filament\Support\MandaIlReset;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * 🏢 **La scheda di una palestra** — 22/09/2026, fase F3.
 *
 * 📌 *«Qui ci voglio tutti i dati importanti della palestra»*.
 *
 * ⚠️ **Una pagina che si guarda, non un modulo che si compila**: i numeri che
 * servono a decidere — quanto paga, quanto ci costa, chi c'è dentro — non si
 * modificano, e un form che li mostrasse fra campi modificabili inviterebbe a
 * toccarli.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            /*
             * 🔑 Lo stesso tasto dell'elenco (F3.6): chi apre la scheda
             * per capire un problema non deve tornare indietro per risolverlo.
             */
            MandaIlReset::make(
                fn (Tenant $r): ?User => $r->aChiScrivere(),
                'reset_del_titolare',
            )->label('Reset del titolare'),
        ];
    }

    /**
     * 📊 **Il grafico sta sotto i numeri** — F3.5.
     *
     * 💡 Chi apre la scheda cerca prima un dato preciso — quanto paga,
     * quando scade — e solo dopo si chiede come si muove nel tempo. ⛔ Un
     * grafico in cima spingerebbe sotto la piega le righe per cui si è aperta
     * la pagina.
     */
    protected function getFooterWidgets(): array
    {
        return [
            IlGiroDiSoldiDellaPalestra::class,
        ];
    }
}
