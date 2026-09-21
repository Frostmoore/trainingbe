<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\PacchettiGettoni\Pages;

use App\Filament\God\Resources\PacchettiGettoni\PacchettoGettoniResource;
use Filament\Resources\Pages\ListRecords;

/**
 * 💡 Una pagina sola: i tagli sono quattro, e un elenco con dentro il modulo
 * di modifica si legge tutto insieme.
 */
class ListPacchettiGettoni extends ListRecords
{
    protected static string $resource = PacchettoGettoniResource::class;
}
