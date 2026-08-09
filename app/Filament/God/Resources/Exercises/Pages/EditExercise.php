<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises\Pages;

use App\Filament\God\Resources\Exercises\ExerciseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExercise extends EditRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
