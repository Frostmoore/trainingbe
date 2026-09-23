<?php

declare(strict_types=1);

namespace App\Filament\Gym\Support;

use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Tenancy\InvitiDelTrainer;
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
 * 🔗 **«Invita un allievo», per chi allena** — F10.1, 24/09/2026.
 *
 * 📌 Il committente, provando il pannello di una trainer indipendente:
 *
 * > *«Se clicco su "Invita un iscritto" mi dice che solo la palestra può
 * > invitare. Se clicco su Invita Allievo nella pagina degli inviti mi apre la
 * > modale sbagliata, lì dovrebbe apparire subito il link di invito e il campo
 * > email se voglio mandare l'email. Deve funzionare come quelli delle
 * > palestre. I due tasti di invito sono diversi, non ha senso.»*
 *
 * ══ 🚨 TRE DIFETTI, UNA CAUSA SOLA ═══════════════════════════════════════════
 *
 * ⛔ Nel pannello c'era **un tasto solo**, `UnInvito`, e parla con
 * `InvitiInPalestra`. A un trainer indipendente quel servizio risponde *«un
 * tenant personale non è una palestra»* — giustissimo, ma il tasto gli veniva
 * mostrato lo stesso.
 *
 * ⛔ E la pagina degli inviti che ho scritto il 23/09 aveva una finestra
 * **sua**, più povera: chiedeva l'email **prima** e il link non lo mostrava.
 * 🚨 Cioè la forma che il committente aveva già fatto cambiare il 23/09 per le
 * palestre — rifatta uguale a fianco.
 *
 * 💡 Questa classe è il gemello di `UnInvito`: **stessa forma, altro servizio**.
 *
 * | | `UnInvito` | `UnInvitoAllievo` |
 * |---|---|---|
 * | Servizio | `InvitiInPalestra` | `InvitiDelTrainer` |
 * | Modello | `InvitoInPalestra` | `TrainerInvite` |
 * | Link | `/invito-palestra/{token}` | `/invito/{token}` |
 * | Il posto che tiene | quello della palestra | quello del **piano da trainer** |
 *
 * ⚠️ **Due classi e non una con dei rami**: il servizio, il modello e la rotta
 * sono diversi in ogni riga che conta. 💡 Quello che deve restare uguale è
 * **ciò che vede chi invita**, ed è per questo che questa classe ricalca
 * l'altra riga per riga invece di reinventarla.
 */
class UnInvitoAllievo
{
    public static function make(string $nome = 'invitaAllievo'): Action
    {
        return Action::make($nome)
            ->label('Invita un allievo')
            ->icon('heroicon-m-link')
            ->color('primary')
            ->modalHeading('Un invito per un tuo allievo')
            ->modalDescription(fn (): string => self::descrizione())
            ->modalSubmitActionLabel('Fatto')
            ->modalCancelAction(false)

            // 1. Il link nasce all'apertura, come nella finestra delle palestre.
            ->mountUsing(function (Schema $form): void {
                $form->fill(self::creaIlLink());
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

                $invito = TrainerInvite::withoutGlobalScopes()->find($data['invito_id']);
                $trainer = auth()->user();

                if ($invito === null || ! $trainer instanceof User) {
                    return;
                }

                // 💡 L'indirizzo resta sull'invito: serve a ritrovarlo nell'elenco.
                $invito->forceFill(['email' => $email])->save();

                $mandata = app(InvitiDelTrainer::class)->mandaIlLink($invito, $trainer);
                $davvero = $mandata && config('mail.default') !== 'log';

                Notification::make()
                    ->title($davvero ? 'Mail partita' : '⚠️ La mail non è partita davvero')
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
    private static function creaIlLink(): array
    {
        $trainer = auth()->user();

        if (! $trainer instanceof User) {
            return ['invito_id' => null, 'link' => null, 'errore' => 'Non riesco a capire chi sei.', 'email' => null];
        }

        try {
            $invito = app(InvitiDelTrainer::class)->invita($trainer);
        } catch (ValidationException $e) {
            /*
             * ⚠️ **Il messaggio del servizio, non uno inventato qui.** Il
             * servizio sa **perché** ha detto di no — posti finiti, niente
             * piano da trainer — e riscriverlo qui vorrebbe dire due testi che
             * divergono al primo cambio di regola.
             */
            return [
                'invito_id' => null,
                'link' => null,
                'errore' => (string) collect($e->errors())->flatten()->first(),
                'email' => null,
            ];
        }

        return [
            'invito_id' => $invito->getKey(),
            // ⚠️ `/invito/` e non `/invito-palestra/`: è l'altro modello.
            'link' => url("/invito/{$invito->token}"),
            'errore' => null,
            'email' => null,
        ];
    }

    private static function descrizione(): string
    {
        $base = 'Vale per una persona sola, una volta sola, e scade dopo sette giorni. '
            .'Se finisce in una chat di gruppo, non fa entrare tutti. '
            .'Ogni volta che apri questa finestra nasce un link nuovo, che tiene un posto finché non scade.';

        $trainer = auth()->user();

        if (! $trainer instanceof User) {
            return $base;
        }

        $rimasti = app(InvitiDelTrainer::class)->postiRimasti($trainer);

        /*
         * ⛔ `null` vuol dire **senza limite**, non zero: scrivere «0 posti» a
         * chi non ne ha di limite sarebbe l'errore peggiore possibile su una
         * finestra che parla di posti a pagamento.
         */
        return $rimasti === null
            ? $base.' Non hai un limite di allievi.'
            : $base." Ti restano {$rimasti} posti.";
    }
}
