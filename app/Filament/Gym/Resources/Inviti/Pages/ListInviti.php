<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Inviti\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Inviti\InvitoResource;
use App\Filament\Gym\Support\UnInvito;
use Filament\Resources\Pages\ListRecords;

class ListInviti extends ListRecords
{
    protected static string $resource = InvitoResource::class;

    public function getTitle(): string
    {
        return 'Inviti';
    }

    public function getSubheading(): ?string
    {
        return 'Un invito valido tiene un posto finché non scade: se non serve più, revocalo.';
    }

    /** 💡 Gli stessi due tasti degli elenchi: da qui si invita anche. */
    protected function getHeaderActions(): array
    {
        return [
            UnInvito::make(UserRole::Member, 'invitaIscritto'),
            UnInvito::make(UserRole::Trainer, 'invitaTrainer'),
        ];
    }
}
