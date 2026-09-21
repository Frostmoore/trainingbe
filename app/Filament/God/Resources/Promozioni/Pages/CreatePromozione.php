<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Pages;

use App\Filament\God\Resources\Promozioni\PromozioneResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromozione extends CreateRecord
{
    protected static string $resource = PromozioneResource::class;
}
