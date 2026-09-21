<?php

declare(strict_types=1);

namespace App\Filament\Gym\Support;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\AbbonamentiDegliIscritti;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * ✂️ **Il tasto «togli / ridai l'abbonamento»** — 23/09/2026, F5.
 *
 * 📌 Il committente: *«mi serve il tasto per togliere l'abbonamento
 * all'allievo»*.
 *
 * 💡 Scritto una volta per i due posti che lo usano — l'elenco e la pagina
 * dell'iscritto — perché è un tasto che **cambia quanto paga la palestra**, e
 * due finestre che lo descrivono in due modi diversi sono una di troppo.
 *
 * 🚨 **La finestra dice le tre conseguenze, tutte**: resta in palestra, ricade
 * sul gratuito, libera un posto. ⛔ Un «sei sicuro?» senza conseguenze non è una
 * conferma: è un secondo clic.
 */
class LAbbonamentoDellIscritto
{
    public static function make(string $nome = 'abbonamento'): Action
    {
        return Action::make($nome)
            ->label(fn (User $record): string => self::ha($record) ? 'Togli abbonamento' : 'Ridai abbonamento')
            ->icon(fn (User $record): string => self::ha($record) ? 'heroicon-m-scissors' : 'heroicon-m-arrow-uturn-left')
            ->color(fn (User $record): string => self::ha($record) ? 'warning' : 'success')
            ->button()
            ->outlined()
            ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => self::ha($record)
                ? "Togliere l'abbonamento a {$record->name}?"
                : "Ridare l'abbonamento a {$record->name}?")
            ->modalDescription(fn (User $record): string => self::ha($record)
                ? 'Resta iscritto alla palestra: continua a scrivere ai trainer e a ricevere le schede. '
                  .'Ma torna al piano gratuito, e non occupa più un posto sul vostro piano. '
                  .'Glielo puoi ridare quando vuoi da qui.'
                : 'Torna abbonato col vostro piano, e riprende a occupare un posto.')
            ->modalSubmitActionLabel(fn (User $record): string => self::ha($record) ? 'Togli' : 'Ridai')
            ->action(function (User $record): void {
                $servizio = app(AbbonamentiDegliIscritti::class);
                $toglie = $servizio->haLAbbonamento($record);

                $toglie
                    ? $servizio->togli($record, auth()->user())
                    : $servizio->ridai($record, auth()->user());

                app(AuditLogger::class)->log(
                    AuditAction::AbbonamentoDellIscritto,
                    $record,
                    ['email' => $record->email, 'ora' => $toglie ? 'tolto' : 'ridato'],
                    tenant: $record->tenant_id,
                );

                Notification::make()
                    ->title($toglie
                        ? "{$record->name} non ha più l'abbonamento"
                        : "{$record->name} ha di nuovo l'abbonamento")
                    ->success()
                    ->send();
            });
    }

    private static function ha(User $iscritto): bool
    {
        return app(AbbonamentiDegliIscritti::class)->haLAbbonamento($iscritto);
    }
}
