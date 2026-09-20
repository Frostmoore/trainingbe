<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Offerte;

use App\Filament\God\Resources\Offerte\Pages\ListOfferte;
use App\Models\OffertaDiPiano;
use App\Models\Scopes\TenantOrGlobalScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 🔗 **Le offerte su misura: dove si ritrovano i link** — 21/09/2026.
 *
 * 📌 *«certo che il piano cucito a mano deve essere fatto con stripe»*.
 *
 * ── 💡 Perché una pagina sua ──────────────────────────────────────────────
 *
 * Perché il link lo si crea una volta e lo si manda per mail o su WhatsApp, e
 * poi serve di nuovo: il cliente non lo trova più, la mail è finita nello spam,
 * l'accordo si riapre dopo un mese. ⛔ Senza questo elenco l'unica strada
 * sarebbe crearne un altro — e ritrovarsi due link validi per lo stesso
 * accordo.
 *
 * 🚨 **Qui si vede anche cosa è stato pagato**: `accettata_il` la scrive il
 * webhook di Stripe, quindi una riga con la spunta è un incasso avvenuto, non
 * una promessa.
 */
class OffertaDiPianoResource extends Resource
{
    protected static ?string $model = OffertaDiPiano::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $modelLabel = 'offerta';

    protected static ?string $pluralModelLabel = 'offerte';

    protected static ?string $navigationLabel = 'Offerte su misura';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** ⛔ Si creano dal piano, col pulsante «Link di pagamento». */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    /**
     * ⛔ **Non si cancellano.** Un'offerta è la traccia di un accordo, e dopo un
     * pagamento contestato è proprio quella che si va a cercare. 💡 Quella che
     * non si vuole più si **revoca**: scade adesso, e il link smette di valere.
     */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * ⚠️ `TenantOrGlobalScope` va tolto: questo pannello gira **senza** tenant,
     * e le offerte intestate a una palestra sono quelle che interessano di più.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([TenantOrGlobalScope::class])
            ->with(['plan', 'tenant']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Creata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('plan.name')
                    ->label('Piano')
                    ->description(fn (OffertaDiPiano $o): ?string => $o->plan?->code)
                    ->searchable(),

                /*
                 * ⚠️ Lo stato si calcola invece di leggere `tenant.name`, e non
                 * e' un vezzo: su un'offerta senza intestatario quel campo e'
                 * **nullo**, e Filament allora disegna il segnaposto e **salta
                 * la descrizione** — cioe' proprio l'email a cui il link e'
                 * stato mandato, che e' l'unica cosa che identifica la riga.
                 */
                TextColumn::make('tenant.name')
                    ->label('Cliente')
                    ->state(fn (OffertaDiPiano $o): string => $o->tenant?->name
                        ?? $o->email
                        ?? 'non ancora cliente')
                    ->description(fn (OffertaDiPiano $o): ?string => $o->tenant !== null ? $o->email : null),

                /*
                 * 💡 `copyable()`: è l'unica cosa che si fa davvero con una
                 * riga di questa tabella — si copia il link e si incolla in una
                 * mail. Mostrarlo per intero occuperebbe mezza tabella.
                 */
                TextColumn::make('token')
                    ->label('Link')
                    ->formatStateUsing(fn (): string => 'copia')
                    ->badge()
                    ->color('primary')
                    ->copyable()
                    ->copyableState(fn (OffertaDiPiano $o): string => $o->link())
                    ->copyMessage('Link copiato'),

                TextColumn::make('accettata_il')
                    ->label('Stato')
                    ->badge()
                    ->state(fn (OffertaDiPiano $o): string => match (true) {
                        $o->accettata_il !== null => 'Pagata',
                        $o->eScaduta() => 'Scaduta',
                        default => 'In attesa',
                    })
                    ->color(fn (OffertaDiPiano $o): string => match (true) {
                        $o->accettata_il !== null => 'success',
                        $o->eScaduta() => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('scade_il')
                    ->label('Vale fino al')
                    ->dateTime('d/m/Y')
                    ->placeholder('non scade')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('valide')
                    ->label('Solo quelle ancora valide')
                    ->query(fn (Builder $q): Builder => $q->valide()),
            ])
            ->recordActions([
                /*
                 * 🚨 Revocare = far scadere adesso. ⛔ Cancellare la riga
                 * toglierebbe la traccia dell'accordo, che è la cosa che si va
                 * a cercare quando qualcosa va storto.
                 */
                Action::make('revoca')
                    ->label('Revoca')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Il link smette di funzionare subito. La riga resta, come traccia.')
                    ->visible(fn (OffertaDiPiano $o): bool => $o->eValida())
                    ->action(fn (OffertaDiPiano $o) => $o->forceFill(['scade_il' => Carbon::now()])->save()),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna offerta')
            ->emptyStateDescription('I link si creano dal piano, col pulsante «Link di pagamento».');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOfferte::route('/'),
        ];
    }
}
