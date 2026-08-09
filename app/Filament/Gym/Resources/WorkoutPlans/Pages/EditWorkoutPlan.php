<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Pages;

use App\Filament\Gym\Resources\WorkoutPlans\WorkoutPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkoutPlan extends EditRecord
{
    protected static string $resource = WorkoutPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
