<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PlanResource;
use Filament\Resources\Pages\EditRecord;

/**
 * ⛔ Nessun pulsante «elimina»: `plan_subscriptions` punta a `plans.id`, e
 * cancellare un piano lascerebbe senza piano chi ce l'ha.
 */
class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
