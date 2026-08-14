<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports\Pages;

use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use App\Jobs\ParseWorkoutPdf;
use App\Models\WorkoutPlanImport;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPlanImports extends ListRecords
{
    protected static string $resource = PlanImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Carica un PDF')
                ->modalHeading('Importa una scheda da PDF')
                ->modalSubmitActionLabel('Carica e leggi')
                ->using(function (array $data): WorkoutPlanImport {
                    $import = WorkoutPlanImport::create([
                        'uploaded_by' => auth()->id(),
                        // ⛔ Sempre `null`: da un import esce un **modello**.
                        // Vedi la nota in `PlanImportResource`.
                        'member_id' => null,
                    ]);

                    // Il file arriva come oggetto caricato (`storeFiles(false)`)
                    // e va a medialibrary, non sul disco pubblico: e' la scheda
                    // di una persona, non un allegato qualsiasi.
                    $import->addMedia($data['pdf']->getRealPath())
                        ->usingFileName($data['pdf']->getClientOriginalName())
                        ->toMediaCollection(WorkoutPlanImport::COLLECTION);

                    ParseWorkoutPdf::dispatch($import->id);

                    Notification::make()
                        ->title('PDF in lettura')
                        ->body('Quando e\' pronto lo trovi qui come «Da rivedere».')
                        ->success()
                        ->send();

                    return $import;
                }),
        ];
    }
}
