<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Pages;

use App\Enums\PlanStatus;
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

        /*
         * ══ 🚨 NASCE GIÀ «PUBBLICATA», E NON È UNA SCORCIATOIA — F5.14 ═════
         *
         * ⛔ Il campo «Stato» non esiste più nel modulo (R2: non ci sono
         * destinatari, quindi non c'è nessuno per cui «pubblicare»). ⚠️ Ma la
         * colonna ha `default('draft')`, e senza questa riga ogni scheda
         * scritta dal pannello nascerebbe **bozza per sempre**: nessun campo la
         * porterebbe più avanti, e l'API — che ancora filtra su `published` —
         * non la servirebbe a nessuno.
         *
         * 💡 È il passo 2 di §6.6 del piano: il pannello smette di usare la
         * colonna, ma finché c'è, la si lascia coerente. I passi 3 e 4 (API e
         * migration) vengono dopo, quando le app in giro avranno smesso di
         * leggerla.
         */
        $data['status'] ??= PlanStatus::Published->value;

        return $data;
    }
}
