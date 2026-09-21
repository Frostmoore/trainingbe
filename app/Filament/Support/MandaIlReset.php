<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * 🔑 **«Manda il reset password»** — 22/09/2026, fase F4.6.
 *
 * 📌 Il committente lo ha chiesto **quattro volte**, su quattro pagine diverse:
 * palestre, utenti, iscritti, trainer. 💡 Per questo è una classe e non un
 * pulsante copiato quattro volte: una regola che vive in quattro copie è una
 * regola che fra un mese ne ha tre giuste e una vecchia.
 *
 * ══ 🚨 IL PANNELLO NON VEDE E NON SCEGLIE LA PASSWORD ════════════════════
 *
 * ⛔ Un campo «password nuova» in un pannello di amministrazione significa che
 * **chi fa supporto conosce la password del cliente** — e che quella password
 * ha viaggiato in un modulo, in un log di richiesta, e nella memoria di chi
 * l'ha scelta. 💡 Qui si manda un **link**: la password la sceglie chi la usa,
 * e noi non la sappiamo nemmeno per un istante.
 *
 * ══ ⚠️ E IL LINK SI VEDE A SCHERMO, DI PROPOSITO ═════════════════════════
 *
 * 📧 Oggi `MAIL_MAILER=log`: la mail «parte» finendo in un file. ⛔ Un pulsante
 * che dicesse soltanto «mail inviata» sarebbe, allo stato attuale, un pulsante
 * che **non fa niente di visibile** — e nessuno se ne accorgerebbe.
 *
 * ✅ Per questo la notifica mostra il link: lo si copia e lo si manda a mano
 * finché non c'è Zeptomail. 🚨 La notifica è `persistent()` — un link che
 * sparisce dopo cinque secondi è un link perso, e il precedente nel frattempo
 * è già stato invalidato.
 *
 * 🔒 **Chi lo preme finisce nel registro** (`ResetPasswordMandato`): per
 * un'ora chi legge quella casella entra al posto di quella persona, ed è un
 * potere che deve lasciare traccia.
 */
class MandaIlReset
{
    /**
     * @param  callable(mixed): ?User  $persona  da quale record si ricava la
     *                                           persona: su un utente è il
     *                                           record stesso, su una palestra
     *                                           è il suo titolare
     */
    public static function make(callable $persona, string $nome = 'manda_il_reset'): Action
    {
        return Action::make($nome)
            ->label('Manda il reset')
            ->icon('heroicon-m-key')
            ->color('warning')
            ->button()
            ->outlined()
            ->visible(fn (mixed $record): bool => $persona($record) instanceof User)
            ->requiresConfirmation()
            ->modalHeading(function (mixed $record) use ($persona): string {
                $chi = $persona($record);

                return 'Mandare il link per la password a '.($chi?->name ?? 'questa persona').'?';
            })
            ->modalDescription(function (mixed $record) use ($persona): string {
                $chi = $persona($record);

                return 'Il link arriva a '.($chi?->email ?? 'il suo indirizzo').', vale un\'ora e si usa '
                    .'una volta sola. ⚠️ Le richieste precedenti smettono di valere. '
                    .'La password attuale resta buona finché non ne sceglie una nuova. Finisce nel registro.';
            })
            ->modalSubmitActionLabel('Manda il link')
            ->action(function (mixed $record) use ($persona): void {
                $chi = $persona($record);

                if (! $chi instanceof User) {
                    Notification::make()
                        ->title('Nessuna persona a cui mandarlo')
                        ->body('Questa scheda non ha un account collegato.')
                        ->danger()
                        ->send();

                    return;
                }

                $link = app(LaReimpostazioneDellaPassword::class)->apriPer($chi, auth()->user());

                app(AuditLogger::class)->log(
                    AuditAction::ResetPasswordMandato,
                    $chi,
                    ['email' => $chi->email],
                    tenant: $chi->tenant_id,
                );

                /*
                 * ⚠️ **Il corpo è testo semplice, non HTML.** `Notification::
                 * body()` accetta `Closure|string|null`: passargli un
                 * `HtmlString` fa esplodere l'azione con un TypeError, e lo fa
                 * **solo quando qualcuno preme il pulsante** — cioè nel posto
                 * peggiore in cui scoprirlo.
                 */
                Notification::make()
                    ->title('Link per la password creato')
                    ->body(
                        'Mandato a '.$chi->email.'. Vale '.LaReimpostazioneDellaPassword::MINUTI.' minuti. '
                        .'⚠️ La posta non è ancora collegata: copia questo link e mandaglielo tu. '
                        .$link
                    )
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /** Sulle pagine dove il record **è già** la persona. */
    public static function perUtente(string $nome = 'manda_il_reset'): Action
    {
        return self::make(fn (mixed $record): ?User => $record instanceof User ? $record : null, $nome);
    }
}
