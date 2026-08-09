<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\NutritionPlans\Pages;

use App\Filament\Gym\Resources\NutritionPlans\NutritionPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNutritionPlan extends CreateRecord
{
    protected static string $resource = NutritionPlanResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] ??= auth()->id();

        return $data;
    }
}
