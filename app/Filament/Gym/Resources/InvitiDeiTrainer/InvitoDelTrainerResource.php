<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\InvitiDeiTrainer;

use App\Filament\Gym\Resources\InvitiDeiTrainer\Pages\ListInvitiDeiTrainer;
use App\Filament\Support\VoceDiScheda;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Tenancy\InvitiDelTrainer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;

/**
 * 🔗 **Gli inviti agli allievi, per chi allena** — F10.1, 23/09/2026.
 *
 * 📌 Il committente, il 23/09: *«Questo mi servirà anche nel pannello dei
 * free_trainers. Segnalo in F10»* — riferito alla pagina degli inviti delle
 * palestre (§119). E poi: *«un free trainer potrà agire sostanzialmente come
 * una palestra»*.
 *
 * ══ 🚨 PERCHE' NON SI E' RIUSATA LA PAGINA DELLE PALESTRE ════════════════════
 *
 * ⛔ Perché **il modello è un altro**. Sembrano la stessa cosa e non lo sono:
 *
 * | | Palestra | Chi allena |
 * |---|---|---|
 * | Modello | `InvitoInPalestra` | `TrainerInvite` |
 * | Rotta pubblica | `/invito-palestra/{token}` | `/invito/{token}` |
 * | Chi entra | un iscritto **o un trainer** della palestra | **un allievo** di quella persona |
 * | Il posto che tiene | quello della palestra | quello del **piano da trainer** |
 *
 * 💡 Una pagina sola con dei rami avrebbe dovuto scegliere il modello a ogni
 * riga, e il primo campo che diverge — è già successo con `ruolo`, che qui non
 * esiste — l'avrebbe spezzata. ⚠️ **Quello che si condivide è la forma**
 * (schede, gli stessi tre tasti, gli stessi stati), non il codice.
 *
 * ── 🔑 Chi la vede ───────────────────────────────────────────────────────────
 *
 * `InvitiDelTrainer::puoAvereAllieviSuoi()`: uno spazio suo **e** un piano da
 * trainer. ⛔ Non `comandaQui()`, e non `isFreeTrainer()`:
 *
 * - un **trainer di palestra** con un piano da trainer ha allievi suoi, e deve
 *   poterli invitare — i suoi inviti vivono a casa sua, non nella palestra;
 * - un **indipendente con il piano scaduto** ricade sul gratuito da persona:
 *   niente inviti nuovi, come per tutte le altre funzioni da trainer (U.3.1).
 *
 * 🚨 È la stessa domanda che si fa l'app prima di mostrare il tasto «Invita»:
 * due risposte diverse alla stessa domanda, a seconda di dove si guarda, sono
 * il difetto che il 16/09 ha fatto riscrivere `limitaAgliAssegnati`.
 */
class InvitoDelTrainerResource extends Resource
{
    protected static ?string $model = TrainerInvite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $modelLabel = 'invito';

    protected static ?string $pluralModelLabel = 'inviti';

    protected static ?string $navigationLabel = 'Inviti ai tuoi allievi';

    protected static ?string $breadcrumb = 'Inviti';

    protected static ?string $slug = 'inviti-allievi';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $utente = auth()->user();

        return $utente instanceof User
            && app(InvitiDelTrainer::class)->puoAvereAllieviSuoi($utente);
    }

    /** ⛔ Gli inviti si creano dal tasto in cima, non da un modulo di risorsa. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * 🚨 **Solo i propri, e si filtra per `trainer_id`.**
     *
     * ⚠️ Non basta lo scope del tenant: nello spazio di un trainer indipendente
     * che ha anche degli allievi può esserci più di una persona, e un invito è
     * di **chi l'ha fatto**. 💡 `withoutGlobalScopes` perché un invito può
     * essere stato creato mentre il contesto era un altro — il suo `tenant_id`
     * è sempre quello di casa del trainer (F6.1), ma la richiesta corrente può
     * arrivare da una palestra dove la stessa persona allena.
     */
    public static function getEloquentQuery(): Builder
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            // Nessun utente: il vuoto, non tutto. Come in `ScopedToTrainer`.
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('trainer_id', $utente->getKey())
            ->with('accettatoDa');
    }

    /** Quanti sono ancora validi, cioè quanti posti tengono. */
    public static function getNavigationBadge(): ?string
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            return null;
        }

        $n = TrainerInvite::withoutGlobalScopes()
            ->where('trainer_id', $utente->getKey())
            ->validi()
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // A schede come tutti gli altri elenchi del pannello: 📌 *«le tabelle
            // mi fanno cagare, se hanno tante funzioni diventa un troiaio»*.
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('email')
                        ->label('A chi')
                        ->weight('bold')
                        ->placeholder('Nessuna email: il link l\'hai copiato tu')
                        ->description('Invito per un tuo allievo'),

                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->state(fn (TrainerInvite $record): string => self::stato($record))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'Valido' => 'success',
                            'Usato' => 'info',
                            default => 'gray',
                        })),

                    VoceDiScheda::make('Creato il', TextColumn::make('created_at')->label('Creato il')->dateTime('d/m/Y H:i')),

                    // Solo finché vale: su un invito usato o revocato la scadenza non dice più niente.
                    VoceDiScheda::make('Scade il', TextColumn::make('expires_at')->label('Scade il')->dateTime('d/m/Y'))
                        ->visible(fn (?TrainerInvite $record): bool => $record?->eValido() ?? true),

                    VoceDiScheda::make('Usato da', TextColumn::make('accettatoDa.name')->label('Usato da')->placeholder('—'))
                        ->visible(fn (?TrainerInvite $record): bool => $record?->used_at !== null),
                ]),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options([
                        'validi' => 'Validi',
                        'usati' => 'Usati',
                        'finiti' => 'Scaduti, revocati o rifiutati',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'validi' => $query->validi(),
                        'usati' => $query->whereNotNull('used_at'),
                        'finiti' => $query->whereNull('used_at')->where(fn (Builder $q) => $q
                            ->whereNotNull('revoked_at')
                            ->orWhereNotNull('rifiutato_il')
                            ->orWhere('expires_at', '<=', now())),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('copia')
                    ->button()
                    ->outlined()
                    ->label('Copia link')
                    ->icon('heroicon-m-clipboard-document')
                    ->color('gray')
                    ->visible(fn (TrainerInvite $record): bool => $record->eValido())
                    /*
                     * 💡 Copia **nel browser**, e copia **questo** link: niente
                     * giro dal server, e soprattutto nessun invito nuovo. ⛔ Un
                     * tasto «copia» che ne genera un altro brucerebbe un posto
                     * a ogni tocco.
                     *
                     * ⚠️ `/invito/{token}` e non `/invito-palestra/{token}`: è
                     * l'altro modello, e l'altra pagina.
                     */
                    ->alpineClickHandler(fn (TrainerInvite $record): string => 'window.navigator.clipboard.writeText('
                        .Js::from(url("/invito/{$record->token}"))
                        .'); $tooltip(\'Copiato\', { theme: $store.theme, timeout: 2000 })'),

                Action::make('manda')
                    ->button()
                    ->outlined()
                    ->label('Manda per email')
                    ->icon('heroicon-m-envelope')
                    ->color('gray')
                    ->visible(fn (TrainerInvite $record): bool => $record->eValido())
                    ->modalHeading('Mandare questo invito per email?')
                    ->modalDescription('Parte lo stesso link, non uno nuovo.')
                    ->modalSubmitActionLabel('Manda')
                    ->fillForm(fn (TrainerInvite $record): array => ['email' => $record->email])
                    ->schema([
                        TextInput::make('email')->label('Indirizzo email')->email()->required()->maxLength(255),
                    ])
                    ->action(function (TrainerInvite $record, array $data): void {
                        $record->forceFill(['email' => trim((string) $data['email'])])->save();

                        $trainer = auth()->user();

                        $mandata = $trainer instanceof User
                            && app(InvitiDelTrainer::class)->mandaIlLink($record, $trainer);

                        $davvero = $mandata && config('mail.default') !== 'log';

                        /*
                         * ⚠️ **Onesto come nella pagina delle palestre**: con
                         * `MAIL_MAILER=log` la mail finisce nel registro e non
                         * parte. 🚨 Dire «mandata» sarebbe la bugia che fa
                         * aspettare una persona per giorni.
                         */
                        Notification::make()
                            ->title($davvero ? 'Mail partita' : '⚠️ La mail non è partita davvero')
                            ->body($davvero ? null : 'La posta non è ancora collegata: copia il link e mandalo tu.')
                            ->status($davvero ? 'success' : 'warning')
                            ->send();
                    }),

                Action::make('revoca')
                    ->button()
                    ->outlined()
                    ->label('Revoca')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (TrainerInvite $record): bool => $record->eValido())
                    ->requiresConfirmation()
                    ->modalHeading('Revocare questo invito?')
                    ->modalDescription('Il link smette subito di funzionare, e il posto che teneva torna libero. Chi lo apre vedrà che l\'invito non vale più.')
                    ->modalSubmitActionLabel('Revoca')
                    ->action(function (TrainerInvite $record): void {
                        app(InvitiDelTrainer::class)->revoca($record);

                        Notification::make()->title('Invito revocato')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun invito')
            ->emptyStateDescription('Gli inviti che crei dall\'app, o da qui, compaiono in questo elenco.');
    }

    /** Lo stato in una parola. ⚠️ L'ordine conta: un invito usato non è «scaduto». */
    public static function stato(TrainerInvite $invito): string
    {
        return match (true) {
            $invito->used_at !== null => 'Usato',
            $invito->revoked_at !== null => 'Revocato',
            $invito->rifiutato_il !== null => 'Rifiutato',
            $invito->expires_at->isPast() => 'Scaduto',
            default => 'Valido',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitiDeiTrainer::route('/'),
        ];
    }
}
