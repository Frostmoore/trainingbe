<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\AuditAction;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    /** @var array<string, mixed> */
    private array $profilo = [];

    /** @var list<int|string>|null */
    private ?array $trainer = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
                ->after(fn (User $record) => app(AuditLogger::class)->log(
                    AuditAction::UserDeleted,
                    $record,
                    ['email' => $record->email],
                    tenant: $record->tenant_id,
                )),
            RestoreAction::make(),
        ];
    }

    /**
     * Il profilo e i trainer stanno su altre tabelle: vanno caricati a mano nel
     * modulo, altrimenti la pagina si apre vuota su quei campi e il primo
     * salvataggio li cancella.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        $data['profile'] = $utente->profile?->only([
            'sex', 'birthdate', 'height_cm', 'activity_level', 'goal', 'target_weight_kg', 'notes',
        ]) ?? [];

        $data['trainers'] = $utente->assignedTrainers()->pluck('users.id')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->profilo = $data['profile'] ?? [];
        $this->trainer = $data['trainers'] ?? [];

        unset($data['profile'], $data['trainers']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        if ($this->profilo !== []) {
            Profile::updateOrCreate(
                ['user_id' => $utente->getKey()],
                array_merge($this->profilo, ['tenant_id' => $utente->tenant_id]),
            );
        }

        if ($this->trainer !== null) {
            $utente->assignedTrainers()->sync($this->collegamenti($utente));
        }
    }

    /** @return array<int|string, array<string, mixed>> */
    private function collegamenti(User $utente): array
    {
        $out = [];

        foreach ($this->trainer ?? [] as $id) {
            $out[$id] = [
                'tenant_id' => $utente->tenant_id,
                'assigned_at' => now(),
                'assigned_by' => auth()->id(),
            ];
        }

        return $out;
    }
}
