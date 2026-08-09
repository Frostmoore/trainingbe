<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Pages;

use App\Enums\AuditAction;
use App\Filament\God\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var bool Se questo salvataggio ha disattivato l'utente. */
    private bool $disattivato = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (User $record): void {
                    app(AuditLogger::class)->log(
                        AuditAction::UserDeleted,
                        $record,
                        ['email' => $record->email],
                        tenant: $record->tenant_id,
                    );
                }),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * La disattivazione va tracciata, ma si riconosce solo confrontando prima e
     * dopo: `is_active` e' una casella come le altre nel modulo, e a salvataggio
     * avvenuto non c'e' piu' modo di sapere se e' cambiata in questo giro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->disattivato = ($this->getRecord()->is_active === true)
            && (($data['is_active'] ?? true) === false);

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->disattivato) {
            return;
        }

        $record = $this->getRecord();

        app(AuditLogger::class)->log(
            AuditAction::UserDeactivated,
            $record,
            ['email' => $record->email],
            tenant: $record->tenant_id,
        );
    }
}
