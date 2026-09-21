<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PlanResource;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    public function getSubheading(): ?string
    {
        return 'I piani in vendita e quelli ritirati. «In vendita» vuol dire che si comprano dal sito e dal pannello; «Attivi» è quanti ce l\'hanno adesso. I piani per un cliente solo stanno in «Offerte su misura».';
    }
}
