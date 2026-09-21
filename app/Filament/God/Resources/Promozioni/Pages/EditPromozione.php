<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Pages;

use App\Filament\God\Resources\Promozioni\PromozioneResource;
use Filament\Resources\Pages\EditRecord;

class EditPromozione extends EditRecord
{
    protected static string $resource = PromozioneResource::class;
}
