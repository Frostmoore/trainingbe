<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Offerte\Pages;

use App\Filament\God\Resources\Offerte\OffertaDiPianoResource;
use Filament\Resources\Pages\ListRecords;

class ListOfferte extends ListRecords
{
    protected static string $resource = OffertaDiPianoResource::class;
}
