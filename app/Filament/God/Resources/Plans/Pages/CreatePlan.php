<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * 🚨 Dopo la creazione si va all'**elenco**, non alla modifica: il passo
 * successivo non è ritoccare il piano appena scritto, è darlo a qualcuno — e
 * il pulsante «Assegna» sta in elenco.
 */
class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
