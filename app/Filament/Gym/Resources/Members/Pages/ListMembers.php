<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

            /*
             * ── 🔗 Il link d'invito — 3b-V.1 ──────────────────────────────
             *
             * 📌 *«non mi piace il codice per iscriversi in palestra,
             * preferisco un link di invito»* — 28/08. E il 29/08: *«Il link
             * d'invito deve essere monouso»*.
             *
             * 🚨 **Sta PRIMA del codice**, ed e' l'unica cosa che dice quale
             * delle due strade e' quella buona: sono affiancate, e chi apre
             * questa pagina deve capire in un colpo d'occhio quale usare.
             */
            Action::make('link_invito')
                ->label('Manda un invito')
                ->icon('heroicon-m-link')
                ->modalHeading('Un invito per una persona')
                ->modalDescription(
                    'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
                    .'Se finisce in una chat di gruppo, non fa entrare tutti.'
                )
                ->schema([
                    TextInput::make('email')
                        ->label('A chi lo mandi (facoltativo)')
                        ->email()
                        /*
                         * ⚠️ **Facoltativa, e serve solo a RICONOSCERLO.** Il
                         * link non viene spedito da noi e non e' legato a
                         * quell'indirizzo: senza, l'elenco degli inviti sarebbe
                         * una colonna di codici indistinguibili.
                         */
                        ->helperText('Serve solo a ritrovarlo nell\'elenco: il link lo mandi tu.'),
                ])
                ->action(function (array $data): void {
                    $utente = auth()->user();
                    $palestra = $utente?->tenant;

                    if (! $utente instanceof User || $palestra === null) {
                        return;
                    }

                    $invito = app(InvitiInPalestra::class)->invita(
                        $palestra,
                        $utente,
                        $data['email'] ?: null,
                    );

                    /*
                     * 💡 **Il link si mostra e si copia, non si manda.** Mandare
                     * le email d'invito vorrebbe dire un mittente, un template,
                     * la reputazione del dominio e la gestione dei rimbalzi —
                     * cioe' un pezzo di prodotto. ⚠️ La palestra ha gia' il
                     * canale con cui parla ai suoi iscritti (WhatsApp, di
                     * solito): il link ci si incolla dentro.
                     */
                    Notification::make()
                        ->title('Invito pronto')
                        /*
                         * ⚠️ **`/invito-palestra` e non `/invito`**: quello è
                         * già degli inviti dei **trainer** (F6.2). Con lo stesso
                         * percorso, il link di una palestra avrebbe aperto la
                         * loro pagina — che non trova il token e scrive «invito
                         * non più valido». 🚨 Un invito buono che si presenta
                         * come scaduto, senza nessun errore da nessuna parte.
                         */
                        ->body(url("/invito-palestra/{$invito->token}"))
                        ->success()
                        ->persistent()
                        ->send();
                }),

            /*
             * ⚰️ Il codice resta — V.4.1.
             *
             * ⛔ **Non si toglie il giorno stesso.** Le palestre che l'hanno gia'
             * dato ai loro iscritti smetterebbero di far entrare la gente, e il
             * difetto arriverebbe in segreteria, non a noi.
             *
             * ⚠️ Ma qui e' dichiarato quale dei due e' il migliore, altrimenti
             * restano due strade equivalenti e si continua a usare la peggiore.
             */
            Action::make('codice_invito')
                ->label('Codice della palestra')
                ->icon('heroicon-m-ticket')
                ->color('gray')
                ->modalHeading('Il codice, per chi ce l\'ha gia\'')
                ->modalDescription(fn (): string => 'Chiunque lo conosca entra, quante volte vuole. '
                    .'Per una persona nuova conviene il link d\'invito. Codice: '
                    .(auth()->user()?->tenant?->join_code ?? '—'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
        ];
    }
}
