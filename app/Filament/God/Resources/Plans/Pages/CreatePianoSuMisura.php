<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PianoSuMisuraResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePianoSuMisura extends CreateRecord
{
    protected static string $resource = PianoSuMisuraResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
