<?php

declare(strict_types=1);

namespace App\Filament\Gym\Support;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * 🔗 **Il tasto «invita», scritto una volta sola** — 23/09/2026, fase F5.5.
 *
 * 📌 Il committente lo vuole in tre posti: sull'elenco degli iscritti,
 * sull'elenco dei trainer, e **dentro le rispettive card del cruscotto**
 * (F5.3). 💡 Tre copie della stessa finestra sarebbero tre testi che divergono
 * al primo cambio di regola — ed è una finestra che parla di **posti a
 * pagamento**.
 *
 * ══ 🆕 COSA CAMBIA RISPETTO A PRIMA ══════════════════════════════════════
 *
 * 1. 📧 **Si può mandare per email.** Prima il link si copiava e basta: la
 *    ragione era che non avevamo un trasporto. Adesso ce l'abbiamo (F4.0), e
 *    ⚠️ finché `MAIL_MAILER=log` la notifica lo dice a chiare lettere invece
 *    di far credere che sia partito.
 * 2. 🔗 **Un link nuovo ogni volta.** Era già così — `invita()` crea sempre una
 *    riga nuova — ma non lo diceva nessuno: ⛔ chi premeva due volte poteva
 *    credere di aver mandato lo stesso link a due persone, e che quindi solo
 *    una delle due sarebbe entrata.
 *
 * ══ ⚠️ E I POSTI SI DICONO PRIMA ═════════════════════════════════════════
 *
 * Un limite scoperto premendo un tasto sembra un guasto; un limite scritto
 * prima è una condizione del piano.
 */
class UnInvito
{
    public static function make(UserRole $ruolo, string $nome = 'invita'): Action
    {
        $eTrainer = $ruolo === UserRole::Trainer;

        return Action::make($nome)
            ->label($eTrainer ? 'Invita un trainer' : 'Invita un iscritto')
            ->icon('heroicon-m-link')
            ->color($eTrainer ? 'gray' : 'primary')
            ->modalHeading($eTrainer ? 'Un invito per un trainer' : 'Un invito per una persona')
            ->modalDescription(fn (): string => self::descrizione($ruolo))
            ->modalSubmitActionLabel('Crea il link')
            ->schema([
                TextInput::make('email')
                    ->label('A chi lo mandi')
                    ->email()
                    ->maxLength(255)
                    ->helperText('Serve a ritrovarlo nell\'elenco, e a spedirlo se scegli di farlo.'),

                /*
                 * ⚠️ **Spento di default, e non è pigrizia.** Finché la posta
                 * non è collegata la mail finisce nel log: accenderlo da solo
                 * farebbe credere a tutti che sia partita.
                 *
                 * 💡 E anche dopo, il canale vero di una palestra è WhatsApp:
                 * il link copiato resta la strada più usata.
                 */
                Toggle::make('manda')
                    ->label('Mandaglielo per email')
                    ->default(false)
                    ->helperText('Se lo lasci spento, copi tu il link dalla notifica.'),
            ])
            ->action(function (array $data) use ($ruolo): void {
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
                        $ruolo,
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

                $link = url("/invito-palestra/{$invito->token}");

                $mandata = ($data['manda'] ?? false) === true
                    && app(InvitiInPalestra::class)->mandaIlLink($invito);

                Notification::make()
                    ->title('Invito pronto')
                    ->body(self::esito($mandata, (bool) ($data['manda'] ?? false), $link))
                    ->success()
                    /*
                     * 🚨 `persistent()`: un link che sparisce dopo cinque
                     * secondi è un link perso, e il prossimo sarà un altro —
                     * questo non si può più recuperare da nessuna parte.
                     */
                    ->persistent()
                    ->send();
            });
    }

    private static function descrizione(UserRole $ruolo): string
    {
        $palestra = auth()->user()?->tenant;

        $base = $ruolo === UserRole::Trainer
            ? 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
              .'Chi lo usa entra come trainer: non paga niente, e non tocca il vostro abbonamento.'
            : 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
              .'Se finisce in una chat di gruppo, non fa entrare tutti.';

        // 🔗 Il chiarimento che prima mancava: vedi il blocco in testa.
        $base .= ' ⚠️ Ogni volta che premi nasce un link NUOVO: quelli di prima restano validi finché non scadono.';

        if ($palestra === null) {
            return $base;
        }

        $posti = app(InvitiInPalestra::class)->iPosti($palestra, $ruolo);

        if ($posti['totali'] === null) {
            return $base;
        }

        $cosa = $ruolo === UserRole::Trainer ? 'trainer' : 'iscritti';

        return $base." Posti {$cosa}: {$posti['occupati']} occupati su {$posti['totali']}.";
    }

    private static function esito(bool $mandata, bool $voleva, string $link): string
    {
        if ($mandata) {
            return "È partita la mail. Se preferisci, questo è il link: {$link}";
        }

        if ($voleva) {
            /*
             * 🚨 **Si dice che NON è partita.** ⛔ Un «mandata!» su una mail
             * finita nel log è la bugia più costosa del pannello: la palestra
             * aspetta, la persona non riceve niente, e nessuno dei due sa
             * perché.
             */
            return "⚠️ La mail non è partita (la posta non è ancora collegata). Copia il link e mandaglielo tu: {$link}";
        }

        return $link;
    }
}
