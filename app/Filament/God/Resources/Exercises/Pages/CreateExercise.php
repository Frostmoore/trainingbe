<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises\Pages;

use App\Filament\God\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
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

    /** 🆕 F9.5 — il campo «Palestre» si applica qui: decide anche di chi è l'esercizio. */
    protected function afterSave(): void
    {
        $this->applicaLePalestre();
    }

    protected function afterCreate(): void
    {
        $this->applicaLePalestre();
    }

    private function applicaLePalestre(): void
    {
        /** @var Exercise $esercizio */
        $esercizio = $this->getRecord();

        ExerciseResource::applicaLePalestre($esercizio, (array) ($this->data['palestre_scelte'] ?? []));
    }

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
