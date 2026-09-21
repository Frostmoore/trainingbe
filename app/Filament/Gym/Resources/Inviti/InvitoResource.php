<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Inviti;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Inviti\Pages\ListInviti;
use App\Filament\Support\VoceDiScheda;
use App\Models\InvitoInPalestra;
use App\Services\Tenancy\InvitiInPalestra;
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
 * 🔗 **Gli inviti fatti, e la revoca** — 23/09/2026.
 *
 * 📌 Il committente: *«mi serve anche una pagina con la lista degli inviti
 * fatti così li posso revocare»*.
 *
 * 💡 **Perché serve davvero**: un invito valido **occupa un posto** finché non
 * scade (F5.1), e da §118 un link nasce ogni volta che si apre la finestra
 * «Invita». Senza questa pagina un link mandato alla persona sbagliata, o
 * aperto per sbaglio, teneva un posto per sette giorni senza modo di
 * liberarlo.
 *
 * 🔒 `BelongsToTenant` sul modello: la palestra vede **solo i suoi** — gli
 * indirizzi email dei clienti di un'altra palestra non passano di qui.
 *
 * ⏳ Il gemello per i **free trainer** (i loro inviti agli allievi,
 * `TrainerInvite`) va nel loro pannello: segnato in F10.
 */
class InvitoResource extends Resource
{
    protected static ?string $model = InvitoInPalestra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $modelLabel = 'invito';

    protected static ?string $pluralModelLabel = 'inviti';

    protected static ?string $navigationLabel = 'Inviti';

    protected static ?string $breadcrumb = 'Inviti';

    protected static ?string $slug = 'inviti';

    protected static ?int $navigationSort = 3;

    /** Come iscritti e trainer: gli inviti li gestisce chi amministra la palestra. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isGymAdmin() === true;
    }

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invitante', 'accettatoDa']);
    }

    /** Quanti sono ancora validi, cioè quanti posti tengono. */
    public static function getNavigationBadge(): ?string
    {
        $n = InvitoInPalestra::query()->validi()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * 🆕 **A schede, non a tabella** — 23/09/2026.
             *
             * 📌 Il committente: *«Fai anche questa a cards, le tabelle mi
             * fanno cagare, se hanno tante funzioni diventa un troiaio»*. Lo
             * stesso schema degli elenchi di iscritti e trainer (`contentGrid`
             * + `Stack` + `VoceDiScheda`), e i tre tasti in fondo alla scheda.
             *
             * `recordUrl(null)`: toccare la scheda non apre niente — l'unico
             * modo di agire è il tasto giusto.
             */
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    // In testa: per chi è, e a chi è stato mandato.
                    TextColumn::make('ruolo')
                        ->label('Per')
                        ->formatStateUsing(fn (?UserRole $state): string => $state === UserRole::Trainer ? 'Invito per un trainer' : 'Invito per un iscritto')
                        ->placeholder('Invito per un iscritto')
                        ->weight('bold')
                        ->description(fn (InvitoInPalestra $record): string => $record->email ?? 'nessuna email: il link l\'hai copiato tu'),

                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->state(fn (InvitoInPalestra $record): string => self::stato($record))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'Valido' => 'success',
                            'Usato' => 'info',
                            default => 'gray',
                        })),

                    VoceDiScheda::make('Creato il', TextColumn::make('created_at')->label('Creato il')->dateTime('d/m/Y H:i')),

                    // Solo finché vale: su un invito usato o revocato la scadenza non dice più niente.
                    VoceDiScheda::make('Scade il', TextColumn::make('expires_at')->label('Scade il')->dateTime('d/m/Y'))
                        ->visible(fn (?InvitoInPalestra $record): bool => $record?->eValido() ?? true),

                    VoceDiScheda::make('Creato da', TextColumn::make('invitante.name')->label('Creato da')->placeholder('—')),

                    // Chi l'ha usato, solo se è stato usato: sugli altri sarebbe una riga di «—».
                    VoceDiScheda::make('Usato da', TextColumn::make('accettatoDa.name')->label('Usato da')->placeholder('—'))
                        ->visible(fn (?InvitoInPalestra $record): bool => $record?->used_at !== null),
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

                SelectFilter::make('ruolo')
                    ->label('Per')
                    ->options([UserRole::Member->value => 'Iscritti', UserRole::Trainer->value => 'Trainer']),
            ])
            ->recordActions([
                /*
                 * 🆕 📌 *«Nella pagina degli inviti devo poter rispedirlo per
                 * email o ri-copiarlo ovviamente, sennò non serve a nulla»*.
                 *
                 * 💡 Copia **nel browser** (lo stesso meccanismo del tasto copia
                 * di Filament): niente giro dal server, e il link è quello
                 * dell'invito, non uno nuovo.
                 */
                Action::make('copia')
                    ->label('Copia link')
                    ->icon('heroicon-m-clipboard-document')
                    ->color('gray')
                    ->visible(fn (InvitoInPalestra $record): bool => $record->eValido())
                    ->alpineClickHandler(fn (InvitoInPalestra $record): string => 'window.navigator.clipboard.writeText('
                        .Js::from(url("/invito-palestra/{$record->token}"))
                        .'); $tooltip(\'Copiato\', { theme: $store.theme, timeout: 2000 })'),

                Action::make('manda')
                    ->label('Manda per email')
                    ->icon('heroicon-m-envelope')
                    ->color('gray')
                    ->visible(fn (InvitoInPalestra $record): bool => $record->eValido())
                    ->modalHeading('Mandare questo invito per email?')
                    ->modalDescription('Parte lo stesso link, non uno nuovo.')
                    ->modalSubmitActionLabel('Manda')
                    ->fillForm(fn (InvitoInPalestra $record): array => ['email' => $record->email])
                    ->schema([
                        TextInput::make('email')->label('Indirizzo email')->email()->required()->maxLength(255),
                    ])
                    ->action(function (InvitoInPalestra $record, array $data): void {
                        $record->forceFill(['email' => trim((string) $data['email'])])->save();

                        $mandata = app(InvitiInPalestra::class)->mandaIlLink($record);
                        $davvero = $mandata && config('mail.default') !== 'log';

                        // ⚠️ Onesto come in `UnInvito`: con la posta sul registro non è partita davvero.
                        Notification::make()
                            ->title($davvero ? 'Mail partita' : '⚠️ La mail non è partita davvero')
                            ->body($davvero ? null : 'La posta non è ancora collegata: copia il link e mandalo tu.')
                            ->status($davvero ? 'success' : 'warning')
                            ->send();
                    }),

                Action::make('revoca')
                    ->label('Revoca')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (InvitoInPalestra $record): bool => $record->eValido())
                    ->requiresConfirmation()
                    ->modalHeading('Revocare questo invito?')
                    ->modalDescription('Il link smette subito di funzionare, e il posto che teneva torna libero. Chi lo apre vedrà che l\'invito non vale più.')
                    ->modalSubmitActionLabel('Revoca')
                    ->action(function (InvitoInPalestra $record): void {
                        app(InvitiInPalestra::class)->revoca($record);

                        Notification::make()->title('Invito revocato')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun invito')
            ->emptyStateDescription('Gli inviti che crei da «Invita un iscritto» o «Invita un trainer» compaiono qui.');
    }

    /** Lo stato in una parola. ⚠️ L'ordine conta: un invito usato non è «scaduto». */
    public static function stato(InvitoInPalestra $invito): string
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
            'index' => ListInviti::route('/'),
        ];
    }
}
