<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\NutritionPlans\Pages;

use App\Filament\Gym\Resources\NutritionPlans\NutritionPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNutritionPlans extends ListRecords
{
    protected static string $resource = NutritionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
