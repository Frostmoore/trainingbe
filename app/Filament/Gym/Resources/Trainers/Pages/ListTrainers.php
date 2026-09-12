<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListTrainers extends ListRecords
{
    protected static string $resource = TrainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->invitaUnTrainer(),
        ];
    }

    /**
     * 🆕 **Invitare un trainer con un link** — 3b-AH.1-quater (12/09/2026).
     *
     * 📌 Il committente: *«anche il trainer dovrà essere invitato dalla palestra
     * tramite link di invito»*.
     *
     * ══ ⚠️ PERCHE' ACCANTO A «CREA», E NON AL SUO POSTO ═══════════════════
     *
     * `CreateAction` fa nascere l'account **dentro** la palestra, con una
     * password che qualcuno deve comunicare. 💡 L'invito invece lo riscatta la
     * persona, con le sue credenziali e la sua email — ed è l'unica strada per
     * chi **un account ce l'ha già**, per esempio un trainer indipendente che
     * comincia a lavorare anche qui.
     *
     * 🚨 **Le due cose non sono alternative**: sono due modi di arrivarci, e
     * consumano lo stesso posto.
     */
    private function invitaUnTrainer(): Action
    {
        return Action::make('invitaTrainer')
            ->label('Invita un trainer')
            ->icon('heroicon-m-link')
            ->color('gray')
            ->modalHeading('Un invito per un trainer')
            /*
             * 💡 **I posti si dicono qui**, non dopo il tentativo: un limite
             * scoperto premendo un tasto sembra un guasto, un limite scritto
             * prima è una condizione del piano. È la stessa scelta già fatta
             * sull'invito degli iscritti.
             */
            ->modalDescription(function (): string {
                $palestra = auth()->user()?->tenant;

                $base = 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
                    .'Chi lo usa entra come trainer: non paga niente, e non tocca il vostro abbonamento.';

                if ($palestra === null) {
                    return $base;
                }

                $rimasti = app(InvitiInPalestra::class)->postiTrainerRimasti($palestra);

                return $rimasti === null
                    ? $base
                    : $base." Posti trainer liberi sul vostro piano: {$rimasti}.";
            })
            ->schema([
                TextInput::make('email')
                    ->label('A chi lo mandi (facoltativo)')
                    ->email()
                    ->helperText('Serve solo a ritrovarlo nell\'elenco: il link lo mandi tu.'),
            ])
            ->action(function (array $data): void {
                $utente = auth()->user();
                $palestra = $utente?->tenant;

                if (! $utente instanceof User || $palestra === null) {
                    return;
                }

                try {
                    $invito = app(InvitiInPalestra::class)->invita(
                        $palestra,
                        $utente,
                        $data['email'] ?: null,
                        UserRole::Trainer,
                    );
                } catch (ValidationException $e) {
                    /*
                     * ⚠️ **Il messaggio del servizio, non uno inventato qui.**
                     * Il servizio sa **perché** ha detto di no — posti finiti,
                     * tenant personale — e riscriverlo nel pannello vorrebbe
                     * dire due testi che raccontano la stessa cosa e divergono
                     * al primo cambio di regola.
                     */
                    Notification::make()
                        ->title('Invito non creato')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Invito pronto')
                    ->body(url("/invito-palestra/{$invito->token}"))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
