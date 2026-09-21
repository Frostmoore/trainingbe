<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Filament\Gym\Support\UnInvito;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * L'elenco dei trainer.
 *
 * ══ ⚠️ PERCHÉ «INVITA» ACCANTO A «CREA», E NON AL SUO POSTO ═════════════
 *
 * `CreateAction` fa nascere l'account **dentro** la palestra. 💡 L'invito
 * invece lo riscatta la persona, con le sue credenziali e la sua email — ed è
 * l'unica strada per chi **un account ce l'ha già**, per esempio un trainer
 * indipendente che comincia a lavorare anche qui.
 *
 * 🚨 **Le due cose non sono alternative**: sono due modi di arrivarci, e
 * consumano lo stesso posto.
 *
 * 🆕 23/09/2026, F5.5 — la finestra dell'invito è `UnInvito`, scritta una volta
 * sola per i tre posti che la usano (qui, gli iscritti, il cruscotto).
 */
class ListTrainers extends ListRecords
{
    protected static string $resource = TrainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UnInvito::make(UserRole::Trainer),

            CreateAction::make()
                ->label('Crea a mano')
                ->color('gray'),
        ];
    }
}
