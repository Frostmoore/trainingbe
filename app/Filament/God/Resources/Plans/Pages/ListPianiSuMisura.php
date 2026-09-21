<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PianoSuMisuraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPianiSuMisura extends ListRecords
{
    protected static string $resource = PianoSuMisuraResource::class;

    public function getSubheading(): ?string
    {
        return 'I piani cuciti per un cliente solo: stessi campi dei piani di listino. Si vendono con un link di pagamento, non dal sito.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Cuci un piano su misura'),
        ];
    }
}
