<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Pages;

use App\Filament\Gym\Resources\WorkoutPlans\WorkoutPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkoutPlan extends CreateRecord
{
    protected static string $resource = WorkoutPlanResource::class;

    /**
     * Chi l'ha scritta resta scritto: serve a sapere a chi chiedere quando una
     * scheda ha qualcosa che non torna, mesi dopo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] ??= auth()->id();

        return $data;
    }
}
