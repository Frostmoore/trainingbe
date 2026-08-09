<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Pages;

use App\Filament\God\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Nessuna azione di creazione: gli utenti nascono dentro una palestra.
 * Il perche' e' scritto per esteso in `UserResource`.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
