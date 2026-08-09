<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises\Pages;

use App\Filament\God\Resources\Exercises\ExerciseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExercises extends ListRecords
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
