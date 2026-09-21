<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Pages;

use App\Filament\God\Resources\Promozioni\PromozioneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromozioni extends ListRecords
{
    protected static string $resource = PromozioneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuova offerta'),
        ];
    }
}
