<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Pages;

use App\Filament\God\Resources\Tenants\TenantResource;
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
        ];
    }
}
