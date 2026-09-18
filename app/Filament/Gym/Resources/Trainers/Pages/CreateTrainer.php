<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Models\User;
use App\Services\Tenancy\DaiUnaCasaAlTrainer;
use Filament\Resources\Pages\CreateRecord;

/**
 * 🚨 Il ruolo va assegnato subito: l'elenco dei trainer filtra per ruolo, e un
 * utente creato senza sparirebbe dalla pagina da cui lo si e' appena creato.
 *
 * ══ 🆕 🏠 E NASCE CON UNA CASA SUA — decisione 2, 18/09/2026 ══════════════
 *
 * 📌 Il committente: *«ogni trainer ha sempre uno spazio suo»*.
 *
 * ⛔ Prima nasceva **dentro** la palestra, e restava lì: non poteva lavorare in
 * una seconda, e se la palestra lo congedava non aveva dove tornare. La
 * migrazione `ogni_trainer_ha_una_casa` sistema quelli che c'erano; 🚨 senza
 * questa riga il pannello ne avrebbe fatti di nuovi sbagliati il giorno dopo.
 *
 * 💡 **Stesso servizio della migrazione**, `DaiUnaCasaAlTrainer`: una sola
 * strada per dare una casa a un trainer. Il ruolo si assegna **prima**, nel
 * contesto della palestra, e il trasloco della casa non lo tocca.
 */
class CreateTrainer extends CreateRecord
{
    protected static string $resource = TrainerResource::class;

    protected function afterCreate(): void
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        $utente->assignRole(UserRole::Trainer->value);

        app(DaiUnaCasaAlTrainer::class)($utente->fresh());
    }
}
