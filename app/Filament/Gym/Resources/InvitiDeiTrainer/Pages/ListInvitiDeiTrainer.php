<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\InvitiDeiTrainer\Pages;

use App\Filament\Gym\Resources\InvitiDeiTrainer\InvitoDelTrainerResource;
use App\Models\User;
use App\Services\Tenancy\InvitiDelTrainer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInvitiDeiTrainer extends ListRecords
{
    protected static string $resource = InvitoDelTrainerResource::class;

    public function getTitle(): string
    {
        return 'Inviti ai tuoi allievi';
    }

    public function getSubheading(): ?string
    {
        $utente = auth()->user();

        $rimasti = $utente instanceof User
            ? app(InvitiDelTrainer::class)->postiRimasti($utente)
            : null;

        /*
         * 🚨 **Il numero dei posti sta qui, e non è decorazione.**
         *
         * ⚠️ Un invito valido **tiene un posto** finché non scade
         * (`postiRimasti()` conta anche quelli non ancora usati). 💡 Chi apre
         * questa pagina di solito ci arriva proprio perché i posti sono finiti:
         * dirglielo qui, accanto al tasto «Revoca», è dirgli come rimediare.
         *
         * ⛔ `null` vuol dire **senza limite**, non zero: scrivere «0 posti» a
         * chi non ne ha di limite sarebbe l'errore peggiore possibile su questa
         * riga.
         */
        $posti = $rimasti === null
            ? 'Non hai un limite di allievi.'
            : "Ti restano {$rimasti} posti.";

        return "Un invito valido tiene un posto finché non scade: se non serve più, revocalo. {$posti}";
    }

    /**
     * 💡 **Si invita da qui**, come nella pagina delle palestre: aprire un
     * elenco di inviti e non poterne fare uno è il difetto che ha fatto nascere
     * quella pagina.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invitaAllievo')
                ->label('Invita un allievo')
                ->icon('heroicon-m-user-plus')
                ->modalHeading('Invitare un allievo')
                ->modalDescription(
                    'Nasce un link personale. Puoi mandarlo per email da qui, oppure copiarlo '
                    .'e mandarlo come preferisci.'
                )
                ->modalSubmitActionLabel('Crea l\'invito')
                ->schema([
                    TextInput::make('email')
                        ->label('Indirizzo email')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Puoi lasciarlo vuoto: in quel caso copi il link e lo mandi tu.'),
                ])
                ->action(function (array $dati): void {
                    $trainer = auth()->user();

                    if (! $trainer instanceof User) {
                        return;
                    }

                    $servizio = app(InvitiDelTrainer::class);

                    $email = trim((string) ($dati['email'] ?? ''));
                    $invito = $servizio->invita($trainer, $email === '' ? null : $email);

                    /*
                     * ⚠️ **Se c'era un indirizzo si prova a mandarlo subito**, e
                     * si dice com'è andata. 🚨 Ma un invito nato resta valido
                     * anche se la mail non parte: il link esiste, e farlo
                     * fallire per il trasporto vorrebbe dire bruciare un posto
                     * per un guasto che non c'entra.
                     */
                    if ($email === '') {
                        Notification::make()
                            ->title('Invito creato')
                            ->body('Copia il link dalla scheda qui sotto.')
                            ->success()
                            ->send();

                        return;
                    }

                    $mandata = $servizio->mandaIlLink($invito, $trainer);
                    $davvero = $mandata && config('mail.default') !== 'log';

                    Notification::make()
                        ->title($davvero ? 'Invito creato e mandato' : 'Invito creato')
                        ->body($davvero
                            ? null
                            : 'La posta non è ancora collegata: copia il link e mandalo tu.')
                        ->status($davvero ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }
}
