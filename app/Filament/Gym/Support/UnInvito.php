<?php

declare(strict_types=1);

namespace App\Filament\Gym\Support;

use App\Enums\UserRole;
use App\Models\InvitoInPalestra;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 🔗 **Il tasto «invita», scritto una volta sola** — 23/09/2026, fase F5.5.
 *
 * 📌 Il committente lo vuole in tre posti: sull'elenco degli iscritti,
 * sull'elenco dei trainer, e **dentro le rispettive card del cruscotto**
 * (F5.3). 💡 Tre copie della stessa finestra sarebbero tre testi che divergono
 * al primo cambio di regola — ed è una finestra che parla di **posti a
 * pagamento**. Vale uguale per **allievi e trainer** (cambia solo `$ruolo`).
 *
 * ══ 🆕 IL LINK PRIMA, L'EMAIL DOPO (E SOLO SE VUOI) — 23/09/2026 ══════════
 *
 * 📌 Il committente: *«non deve essere obbligatorio che metto l'email. Deve
 * subito generare un link di invito (e io lo devo vedere nella modale e poterlo
 * copiare …) e poi sotto mi deve chiedere se voglio inviarlo per email (solo lì
 * potrò inserire un indirizzo mail) ma non deve essere obbligatorio»*.
 *
 * ⛔ Prima la finestra chiedeva **prima** l'email e il link compariva solo dopo,
 * in una notifica. Adesso:
 *
 * 1. aprendo la finestra il link **nasce subito** (`mountUsing`) e si vede in un
 *    campo con il tasto **copia**;
 * 2. sotto, **facoltativo**, un indirizzo: se c'è, premendo il tasto parte la
 *    mail; se non c'è, il tasto chiude e basta.
 *
 * ⚠️ **Il prezzo, detto chiaro**: il link nasce anche se poi chiudi la finestra
 * senza usarlo, e **occupa un posto** finché non scade (sette giorni) — un
 * invito valido è un posto promesso (F5.1). La descrizione lo dice.
 *
 * ⚠️ E se i posti sono finiti il link **non** nasce: la finestra dice perché al
 * posto del link.
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
            ->modalSubmitActionLabel('Fatto')
            ->modalCancelAction(false)

            // 1. Il link nasce all'apertura.
            ->mountUsing(function (Schema $form) use ($ruolo): void {
                $form->fill(self::creaIlLink($ruolo));
            })

            ->schema([
                Hidden::make('invito_id'),
                Hidden::make('errore'),

                // Se i posti sono finiti: il perché, al posto del link.
                Text::make(fn (Get $get): string => (string) $get('errore'))
                    ->color('danger')
                    ->visible(fn (Get $get): bool => filled($get('errore'))),

                TextInput::make('link')
                    ->label('Il link')
                    ->readOnly()
                    ->copyable(copyMessage: 'Copiato')
                    ->helperText('Copialo e mandalo come preferisci: WhatsApp, SMS, dove vuoi.')
                    ->visible(fn (Get $get): bool => blank($get('errore'))),

                // 2. L'email, facoltativa, e solo qui.
                Section::make('Vuoi mandarlo anche per email?')
                    ->description('Facoltativo. Se scrivi un indirizzo, premendo «Fatto» gli mandiamo il link.')
                    ->compact()
                    ->visible(fn (Get $get): bool => blank($get('errore')))
                    ->schema([
                        TextInput::make('email')
                            ->label('Indirizzo email')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('nome@esempio.it'),
                    ]),
            ])
            ->action(function (array $data): void {
                $email = trim((string) ($data['email'] ?? ''));

                // Nessun indirizzo: il link l'hai già copiato, e basta così.
                if ($email === '' || blank($data['invito_id'] ?? null)) {
                    return;
                }

                $invito = InvitoInPalestra::query()->find($data['invito_id']);

                if ($invito === null) {
                    return;
                }

                // 💡 L'indirizzo resta sull'invito: serve a ritrovarlo nell'elenco.
                $invito->forceFill(['email' => $email])->save();

                $mandata = app(InvitiInPalestra::class)->mandaIlLink($invito);
                $davvero = $mandata && config('mail.default') !== 'log';

                Notification::make()
                    ->title(self::esito($mandata))
                    ->body($davvero ? null : 'Il link resta valido: copialo e mandalo tu.')
                    ->status($davvero ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * Crea l'invito e restituisce lo stato iniziale della finestra.
     *
     * @return array{invito_id: ?int, link: ?string, errore: ?string, email: null}
     */
    private static function creaIlLink(UserRole $ruolo): array
    {
        $utente = auth()->user();
        $palestra = $utente?->tenant;

        if (! $utente instanceof User || $palestra === null) {
            return ['invito_id' => null, 'link' => null, 'errore' => 'Non riesco a capire di quale palestra sei.', 'email' => null];
        }

        try {
            $invito = app(InvitiInPalestra::class)->invita($palestra, $utente, null, $ruolo);
        } catch (ValidationException $e) {
            /*
             * ⚠️ **Il messaggio del servizio, non uno inventato qui.** Il
             * servizio sa **perché** ha detto di no — posti finiti, tenant
             * personale — e riscriverlo qui vorrebbe dire due testi che
             * divergono al primo cambio di regola.
             */
            return ['invito_id' => null, 'link' => null, 'errore' => (string) collect($e->errors())->flatten()->first(), 'email' => null];
        }

        return [
            'invito_id' => $invito->getKey(),
            'link' => url("/invito-palestra/{$invito->token}"),
            'errore' => null,
            'email' => null,
        ];
    }

    private static function descrizione(UserRole $ruolo): string
    {
        $palestra = auth()->user()?->tenant;

        $base = $ruolo === UserRole::Trainer
            ? 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
              .'Chi lo usa entra come trainer: non paga niente, e non tocca il vostro abbonamento.'
            : 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
              .'Se finisce in una chat di gruppo, non fa entrare tutti.';

        // ⚠️ Il link nasce aprendo questa finestra, e occupa un posto finché non scade.
        $base .= ' Ogni volta che apri questa finestra nasce un link nuovo, che tiene un posto finché non scade.';

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

    /**
     * 🚨 **Si dice quando NON è partita**, anche col trasporto di prova: finché
     * `MAIL_MAILER=log` la mail finisce nel registro del server e nessuno la
     * riceve. ⛔ Un «mandata!» lì è la bugia più costosa del pannello.
     */
    private static function esito(bool $mandata): string
    {
        if ($mandata && config('mail.default') !== 'log') {
            return 'Mail partita';
        }

        return $mandata
            ? '⚠️ La posta non è ancora collegata: la mail non è partita davvero'
            : '⚠️ La mail non è partita';
    }
}
