<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Filament\Gym\Resources\Trainers\TrainerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainer extends EditRecord
{
    protected static string $resource = TrainerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }
}
