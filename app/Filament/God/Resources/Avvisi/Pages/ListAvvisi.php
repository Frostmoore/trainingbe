<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Avvisi\Pages;

use App\Filament\God\Resources\Avvisi\AvvisoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAvvisi extends ListRecords
{
    protected static string $resource = AvvisoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuovo avviso'),
        ];
    }
}
