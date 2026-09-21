<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises\Pages;

use App\Filament\God\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExercise extends EditRecord
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

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
