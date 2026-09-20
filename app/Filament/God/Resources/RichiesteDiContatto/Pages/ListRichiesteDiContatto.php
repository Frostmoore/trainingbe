<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\RichiesteDiContatto\Pages;

use App\Filament\God\Resources\RichiesteDiContatto\RichiestaDiContattoResource;
use Filament\Resources\Pages\ListRecords;

class ListRichiesteDiContatto extends ListRecords
{
    protected static string $resource = RichiestaDiContattoResource::class;
}
