<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\AuditAction;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    /** @var array<string, mixed> */
    private array $profilo = [];

    /** @var list<int|string>|null */
    private ?array $trainer = null;

    /**
     * ══ 🆕 🚨 «DISISCRIVI», NON «ELIMINA» — D4, 19/09/2026 ═══════════════
     *
     * ⛔ **Qui c'era `DeleteAction`**, e cancellava l'account della persona:
     * niente ritorno al trainer, niente ripresa dell'abbonamento, e una
     * palestra poteva far sparire chi prima di iscriversi era un utente libero
     * con i suoi dati. Trovato nel giro del 18/09.
     *
     * 📌 Il committente: *«Una palestra deve poter AL MASSIMO disiscrivere un
     * allievo! Non deve poter cancellare del tutto il suo account, anche
     * perche' l'account personale non dipende in alcun modo dalla palestra»*.
     *
     * 💡 Disiscrivere e' **la stessa cosa che fa la persona** con «Dissocia
     * palestra»: passa da `EsciDaUnaPalestra`, quindi torna nel suo spazio,
     * ritrova l'abbonamento sospeso e il trainer che aveva tenuto il posto.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('disiscrivi')
                ->label('Disiscrivi')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
                ->requiresConfirmation()
                ->modalHeading(fn (User $record): string => "Disiscrivere {$record->name}?")
                ->modalDescription('Esce dalla palestra e torna nel suo spazio personale, con il suo account e i suoi dati. Per rientrare gli servira\' un nuovo invito.')
                ->modalSubmitActionLabel('Disiscrivi')
                ->action(function (User $record): void {
                    $palestra = $record->tenant_id;

                    app(EsciDaUnaPalestra::class)($record);

                    app(AuditLogger::class)->log(
                        AuditAction::IscrittoDisiscritto,
                        $record,
                        ['email' => $record->email],
                        tenant: $palestra,
                    );

                    Notification::make()->title("{$record->name} non e' piu' iscritto.")->success()->send();

                    $this->redirect(MemberResource::getUrl('index'));
                }),
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
