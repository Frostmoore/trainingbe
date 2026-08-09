<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises\Pages;

use App\Filament\God\Resources\Exercises\ExerciseResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * ⚠️ Creato da qui, un esercizio senza palestra e' **globale**.
 *
 * Il trait `BelongsToTenantOrGlobal` riempie `tenant_id` dal contesto: nel
 * pannello di piattaforma il contesto e' vuoto, quindi la riga nasce
 * correttamente globale senza doverlo dire.
 */
class CreateExercise extends CreateRecord
{
    protected static string $resource = ExerciseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] ??= auth()->id();
        $data['is_custom'] = false;

        return $data;
    }
}
