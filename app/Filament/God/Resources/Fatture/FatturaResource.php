<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Fatture;

use App\Filament\God\Resources\Fatture\Pages\ListFatture;
use App\Models\Fattura;
use App\Models\Tenant;
use App\Services\Fatturazione\EmissioneDelleFatture;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * 🧾 **Le fatture**, viste dalla piattaforma — fase F8.4-bis, 23/09/2026.
 *
 * 📌 D2: *«Basta che mi generi l'xml e il pdf. Il pdf lo deve poter scaricare
 * anche il cliente, l'xml solo io»*. ⛔ **Questa è l'unica pagina con l'XML.**
 *
 * | Colonna / azione | Perché |
 * |---|---|
 * | «In attesa» in cima | una fattura in attesa è un pagamento **non ancora fatturato**: è la cosa da guardare |
 * | «Emetti» | dopo che la palestra ha completato i dati, se non l'ha già fatto da sola salvandoli |
 * | XML / PDF | uno per riga, e l'XML del mese in un file solo (`ListFatture`) |
 *
 * ⛔ **Niente «modifica» né «elimina»**: una fattura emessa non cambia. Se è
 * sbagliata serve una nota di credito, che non esiste ancora (debito dichiarato).
 */
class FatturaResource extends Resource
{
    protected static ?string $model = Fattura::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'fattura';

    protected static ?string $pluralModelLabel = 'fatture';

    protected static ?string $navigationLabel = 'Fatture';

    // ⚠️ Senza, Filament ricava lo slug dal modello: «fatturas».
    protected static ?string $slug = 'fatture';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
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

    /** Tutte, di tutte le palestre: lo scope della palestra qui non c'entra. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with('tenant');
    }

    /** Quante aspettano: un pagamento senza fattura è la cosa da non dimenticare. */
    public static function getNavigationBadge(): ?string
    {
        $n = Fattura::withoutGlobalScopes()->inAttesa()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Numero')
                    ->formatStateUsing(fn (Fattura $record): string => $record->numeroPerEsteso() ?? '—')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('data')->label('Data')->date('d/m/Y')->placeholder('—')->sortable(),

                TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Fattura::EMESSA ? 'Emessa' : 'In attesa dei dati')
                    ->color(fn (string $state): string => $state === Fattura::EMESSA ? 'success' : 'warning'),

                /*
                 * 💡 Il cliente **della fattura** (fotografato), non quello di
                 * oggi: se la palestra ha cambiato ragione sociale, la fattura
                 * vecchia dice ancora quella vecchia. Per una in attesa, il nome
                 * della palestra.
                 */
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->state(fn (Fattura $record): string => (string) ($record->cessionario['ragione_sociale']
                        ?? $record->tenant?->name
                        ?? 'palestra non più presente'))
                    ->description(fn (Fattura $record): ?string => $record->tenant?->name !== ($record->cessionario['ragione_sociale'] ?? null)
                        ? $record->tenant?->name
                        : null)
                    ->wrap(),

                TextColumn::make('descrizione')->label('Cosa')->limit(60)->wrap()->toggleable(),

                TextColumn::make('totale_cent')
                    ->label('Totale')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2, ',', '.').' €')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('bollo_cent')
                    ->label('Bollo')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '2,00 €' : '—')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('stato')->label('Stato')->options([
                    Fattura::EMESSA => 'Emesse',
                    Fattura::IN_ATTESA => 'In attesa dei dati',
                ]),
                SelectFilter::make('anno')->label('Anno')->options(fn (): array => Fattura::withoutGlobalScopes()
                    ->whereNotNull('anno')->distinct()->orderByDesc('anno')->pluck('anno', 'anno')->all()),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-m-document-arrow-down')
                    ->visible(fn (Fattura $record): bool => $record->eEmessa())
                    ->url(fn (Fattura $record): string => route('filament.god.fattura-pdf', ['fattura' => $record->getKey()])),

                Action::make('xml')
                    ->label('XML')
                    ->icon('heroicon-m-code-bracket')
                    ->color('gray')
                    ->visible(fn (Fattura $record): bool => $record->eEmessa())
                    ->url(fn (Fattura $record): string => route('filament.god.fattura-xml', ['fattura' => $record->getKey()])),

                Action::make('emetti')
                    ->label('Emetti')
                    ->icon('heroicon-m-check-badge')
                    ->color('warning')
                    ->visible(fn (Fattura $record): bool => ! $record->eEmessa())
                    ->requiresConfirmation()
                    ->modalHeading('Emettere questa fattura?')
                    ->modalDescription(fn (Fattura $record): string => self::cosaManca($record)
                        ?? 'Prende il prossimo numero e la data di oggi. Dopo non si può più modificare.')
                    ->action(function (Fattura $record): void {
                        try {
                            $emessa = app(EmissioneDelleFatture::class)->emetti($record);

                            Notification::make()->title('Fattura '.$emessa->numeroPerEsteso().' emessa')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Non emessa')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                // In attesa per prime, poi dalla più recente.
                ->orderByRaw("case when stato = 'in_attesa' then 0 else 1 end")
                ->orderByDesc('anno')
                ->orderByDesc('numero')
                ->orderByDesc('id'))
            ->emptyStateHeading('Nessuna fattura')
            ->emptyStateDescription('Le fatture nascono da sole quando una palestra paga: canone, consumo o gettoni.');
    }

    /** Cosa manca alla palestra per emettere, in parole; `null` se niente. */
    public static function cosaManca(Fattura $fattura): ?string
    {
        $palestra = Tenant::withoutGlobalScopes()->find($fattura->tenant_id);

        if ($palestra === null) {
            return 'La palestra non esiste più: la fattura non si può emettere.';
        }

        $mancano = $palestra->datiDiFatturazioneMancanti();

        return $mancano === [] ? null : 'Alla palestra mancano '.implode(', ', $mancano).'. Finché non li inserisce, la fattura non si può emettere.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFatture::route('/'),
        ];
    }
}
