<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\NutritionPlans\Pages;

use App\Filament\Gym\Resources\NutritionPlans\NutritionPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNutritionPlan extends EditRecord
{
    protected static string $resource = NutritionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
