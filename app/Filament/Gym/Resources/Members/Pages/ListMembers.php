<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Filament\Gym\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Solo il gym_admin crea iscritti: un trainer che ne crea uno lo
            // creerebbe fuori dai propri assegnati e non lo vedrebbe piu'.
            CreateAction::make()
                ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true),

            // Il codice d'invito e' la strada normale: l'iscritto si registra
            // da solo dall'app e arriva gia' dentro la palestra giusta.
            Action::make('codice_invito')
                ->label('Codice d\'invito')
                ->icon('heroicon-m-ticket')
                ->color('gray')
                ->modalHeading('Come si iscrive una persona')
                ->modalDescription(fn (): string => 'Basta che scarichi l\'app e digiti questo codice: '
                    .(auth()->user()?->tenant?->join_code ?? '—'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
        ];
    }
}
