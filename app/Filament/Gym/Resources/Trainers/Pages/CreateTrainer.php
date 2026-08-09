<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

/**
 * 🚨 Il ruolo va assegnato subito: l'elenco dei trainer filtra per ruolo, e un
 * utente creato senza sparirebbe dalla pagina da cui lo si e' appena creato.
 */
class CreateTrainer extends CreateRecord
{
    protected static string $resource = TrainerResource::class;

    protected function afterCreate(): void
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        $utente->assignRole(UserRole::Trainer->value);
    }
}
