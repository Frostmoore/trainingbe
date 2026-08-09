<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Filament\Gym\Resources\Trainers\TrainerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainers extends ListRecords
{
    protected static string $resource = TrainerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
