<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Pages;

use App\Filament\Gym\Resources\WorkoutPlans\WorkoutPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkoutPlans extends ListRecords
{
    protected static string $resource = WorkoutPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
