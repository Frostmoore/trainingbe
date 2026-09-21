<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Pages;

use App\Filament\Gym\Resources\WorkoutPlans\WorkoutPlanResource;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanImport;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Il builder di una scheda.
 *
 * ══ 🆕 📄 COL DOCUMENTO ACCANTO, SE VIENE DA UN IMPORT — 23/09/2026, F7.2 ══
 *
 * 📌 Il committente: *«il builder deve funzionare alla fine, e deve avere la
 * scheda vicino (visualizzatore pdf o immagine) per poterla confrontare con il
 * risultato dell'import»*.
 *
 * 💡 **Solo per le schede nate da un import**: per le altre non c'è niente da
 * confrontare, e una colonna vuota ruberebbe metà dello schermo al modulo.
 *
 * ⚠️ La griglia è quella **di Filament** (`Grid`, `columnSpan`), non classi di
 * Tailwind scritte a mano: il CSS del pannello è precompilato e le classi
 * generiche non le contiene. Il riquadro del documento usa invece le classi
 * `tc-*` di `resources/css/pannello-palestra.css`, dove sta anche il perché.
 */
class EditWorkoutPlan extends EditRecord
{
    protected static string $resource = WorkoutPlanResource::class;

    /**
     * ⚠️ `protected`, non `public`: Livewire non la serializza, e si ricalcola
     * a ogni richiesta — l'import di una scheda non cambia mentre la si modifica.
     */
    protected WorkoutPlanImport|false|null $importDiOrigine = null;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * 🆕 Col documento accanto, il modulo va **in una colonna sola**.
     *
     * ⚠️ Il modulo di una pagina di modifica ha due colonne («La scheda» e
     * «Giorni» affiancate): dentro i sette dodicesimi lasciati dal documento
     * diventavano due colonne da un terzo di schermo, e serie, ripetizioni,
     * recupero e carico si sovrapponevano (visto nel browser, F7.4).
     */
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $this->importDiOrigine() !== null ? $schema->columns(1) : $schema;
    }

    public function content(Schema $schema): Schema
    {
        $import = $this->importDiOrigine();

        if ($import === null) {
            return parent::content($schema);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                View::make('filament.gym.componenti.documento-importato')
                    ->viewData(['import' => $import])
                    ->columnSpan(['default' => 1, 'lg' => 5]),

                Group::make([
                    $this->getFormContentComponent(),
                    $this->getRelationManagersContentComponent(),
                ])->columnSpan(['default' => 1, 'lg' => 7]),
            ]),
        ]);
    }

    /**
     * L'import da cui è nata questa scheda, **se c'è ancora il documento**.
     *
     * ⚠️ Si cerca l'import con `workout_plan_id`, non si deduce da `source`:
     * una scheda duplicata da una importata ha `source = pdf_import` ma nessun
     * documento suo, e mostrarle quello dell'originale le attribuirebbe una
     * fonte che non è la sua.
     */
    private function importDiOrigine(): ?WorkoutPlanImport
    {
        // 💡 Una domanda sola per richiesta: la chiedono sia `form()` sia
        // `content()`. ⚠️ `false` = «già chiesto, non c'è».
        if ($this->importDiOrigine !== null) {
            return $this->importDiOrigine ?: null;
        }

        /** @var WorkoutPlan $scheda */
        $scheda = $this->getRecord();

        $import = WorkoutPlanImport::query()
            ->where('workout_plan_id', $scheda->getKey())
            ->latest('id')
            ->first();

        $this->importDiOrigine = $import?->documento() !== null ? $import : false;

        return $this->importDiOrigine ?: null;
    }
}
