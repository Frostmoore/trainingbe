<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\Profile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

/**
 * 🚨 Tre cose vanno fatte insieme, o l'iscritto nasce a meta':
 *  1. l'utente;
 *  2. il **ruolo** `member`, senza il quale non comparirebbe piu' nell'elenco
 *     (che filtra per ruolo) — un utente creato e subito invisibile;
 *  3. il **profilo**, che sta su un'altra tabella ma nella stessa pagina.
 */
class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /** @var array<string, mixed> */
    private array $profilo = [];

    /** @var list<int|string> */
    private array $trainer = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->profilo = $data['profile'] ?? [];
        $this->trainer = $data['trainers'] ?? [];

        unset($data['profile'], $data['trainers']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        $utente->assignRole(UserRole::Member->value);

        if (array_filter($this->profilo, static fn ($v): bool => $v !== null && $v !== '') !== []) {
            Profile::updateOrCreate(
                ['user_id' => $utente->getKey()],
                array_merge($this->profilo, ['tenant_id' => $utente->tenant_id]),
            );
        }

        if ($this->trainer !== []) {
            $utente->assignedTrainers()->sync($this->collegamenti());
        }
    }

    /** @return array<int|string, array<string, mixed>> */
    private function collegamenti(): array
    {
        $out = [];

        foreach ($this->trainer as $id) {
            $out[$id] = [
                'tenant_id' => $this->getRecord()->tenant_id,
                'assigned_at' => now(),
                'assigned_by' => auth()->id(),
            ];
        }

        return $out;
    }
}
