<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Pages;

use App\Filament\God\Resources\Users\UserResource;
use App\Filament\God\Resources\Users\Widgets\LaStoriaDellaPersona;
use App\Filament\Support\MandaIlReset;
use App\Mail\MessaggioDalPannello;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 👤 **La scheda di una persona** — 22/09/2026, fasi F4.2 → F4.5.
 *
 * ⚠️ **Una pagina che si guarda, non un modulo che si compila.** I numeri che
 * servono a decidere — quanto ha speso, quanti gettoni brucia, che abbonamento
 * ha — non si modificano, e un form che li mostrasse fra campi modificabili
 * inviterebbe a toccarli. ✅ Le modifiche restano in `EditUser`, dietro il suo
 * pulsante.
 *
 * ══ 🚨 PERCHÉ ESISTE, VISTO CHE C'ERA GIÀ «MODIFICA» ═════════════════════
 *
 * Perché `EditUser` risponde a *«cosa posso cambiargli»*, e la domanda vera di
 * chi fa supporto è ***«chi è questa persona»***: da quanto c'è, cosa ha
 * pagato, se è abbonata, perché non riesce a entrare. ⛔ Prima di oggi quelle
 * risposte stavano in cinque tabelle diverse e in nessuna pagina.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            MandaIlReset::perUtente(),

            /*
             * ══ ✉️ «MANDA UNA MAIL» — F4.5 ══════════════════════════════
             *
             * 📌 Il committente: una sezione per **scrivergli** dalla sua
             * scheda.
             *
             * 💡 È un'azione e non una sezione dell'infolist per una ragione
             * pratica: una casella di testo sempre aperta in mezzo ai dati
             * viene compilata per sbaglio, e soprattutto sposta in basso tutto
             * quello che si è venuti a leggere.
             *
             * 🚨 **Non è un invio in massa**, e il perché sta per esteso in
             * `MessaggioDalPannello`: le liste hanno regole loro (consenso,
             * disiscrizione) che qui non ci sono di proposito.
             */
            Action::make('manda_una_mail')
                ->label('Manda una mail')
                ->icon('heroicon-m-envelope')
                ->color('info')
                ->modalHeading(fn (User $record): string => 'Scrivi a '.$record->name)
                ->modalDescription(fn (User $record): string => 'Arriva a '.$record->email
                    .'. ⚠️ Va a lui soltanto, e la firma col tuo nome.')
                ->modalSubmitActionLabel('Manda')
                ->schema([
                    TextInput::make('oggetto')
                        ->label('Oggetto')
                        ->required()
                        ->maxLength(150),

                    Textarea::make('testo')
                        ->label('Messaggio')
                        ->required()
                        ->rows(10)
                        ->maxLength(5000)
                        ->helperText('Gli a capo si vedono. ⚠️ Non è HTML: quello che scrivi arriva come l\'hai scritto.'),
                ])
                ->action(function (User $record, array $data): void {
                    try {
                        Mail::to($record->email)->send(new MessaggioDalPannello(
                            $record,
                            $data['oggetto'],
                            $data['testo'],
                            auth()->user()?->name,
                        ));
                    } catch (Throwable $e) {
                        report($e);

                        /*
                         * 🚨 **Il messaggio vero, non uno generico.** Chi
                         * guarda è un amministratore: se il trasporto ha detto
                         * «connection refused», è l'unica informazione che gli
                         * dice cosa fare.
                         */
                        Notification::make()
                            ->title('La mail non è partita')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    /*
                     * ⚠️ **Nel log, non nel registro di audit.** Il registro
                     * traccia i gesti che cambiano qualcosa; questo non cambia
                     * niente. 💡 Ma serve saperne l'esistenza il giorno che
                     * qualcuno chiede «chi mi ha scritto questa roba».
                     */
                    Log::info('Mail dal pannello.', [
                        'a' => $record->getKey(),
                        'da' => auth()->id(),
                        'oggetto' => $data['oggetto'],
                    ]);

                    Notification::make()
                        ->title('Mail mandata a '.$record->name)
                        ->body(config('mail.default') === 'log'
                            ? '⚠️ La posta non è ancora collegata: il messaggio è finito nel log, non nella sua casella.'
                            : 'È partita.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * 📊 I grafici stanno **sotto** la scheda — F4.3.
     *
     * 💡 Chi apre questa pagina cerca prima un dato preciso (l'email, il
     * piano, il saldo); l'andamento nel tempo è la domanda successiva. ⛔ Un
     * grafico in cima spingerebbe sotto la piega proprio le righe per cui si è
     * aperta la pagina.
     */
    protected function getFooterWidgets(): array
    {
        return [
            LaStoriaDellaPersona::class,
        ];
    }
}
