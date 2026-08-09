<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\AuditLogs;

use App\Enums\AuditAction;
use App\Filament\God\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il registro, in sola lettura.
 *
 * 🚨 **Esiste perche' senza di lui «tracciata» sarebbe una parola.** Un
 * registro che nessuno puo' leggere non e' una garanzia: e' una tabella. Se un
 * cliente chiede «chi e' entrato nel mio account il 3 marzo?», la risposta deve
 * potersi dare in dieci secondi, non con una query a mano sul database di
 * produzione.
 *
 * Non ha creazione, modifica ne' cancellazione — e non solo perche' non servono:
 * `AuditLog` blocca `updating` e `deleting` a livello di modello. Qui manca
 * anche l'interfaccia, cosi' non c'e' nemmeno il pulsante che poi fallirebbe.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'voce di registro';

    protected static ?string $pluralModelLabel = 'registro';

    protected static ?string $navigationLabel = 'Registro azioni';

    protected static ?int $navigationSort = 9;

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([TenantScope::class])
            ->with(['tenant', 'actor']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Azione')
                    ->badge()
                    ->formatStateUsing(fn (AuditAction $state): string => $state->label())
                    ->color(fn (AuditAction $state): string => $state->color()),

                TextColumn::make('actor_label')
                    ->label('Chi')
                    ->description(fn (AuditLog $r): ?string => $r->actor_email)
                    ->placeholder('sistema')
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Palestra')
                    ->placeholder('Piattaforma')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('auditable_id')
                    ->label('Su cosa')
                    ->formatStateUsing(fn (?int $state, AuditLog $r): string => $state === null
                        ? '—'
                        : class_basename((string) $r->auditable_type)." #{$state}")
                    ->toggleable(),

                TextColumn::make('ip')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Azione')
                    ->options(AuditAction::options())
                    ->multiple(),

                SelectFilter::make('tenant_id')
                    ->label('Palestra')
                    ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Filter::make('solo_impersonazioni')
                    ->label('Solo impersonazioni')
                    ->query(fn (Builder $q): Builder => $q->whereIn('action', [
                        AuditAction::ImpersonationStarted->value,
                        AuditAction::ImpersonationStopped->value,
                    ])),
            ])
            ->recordActions([
                Action::make('dettaglio')
                    ->label('Dettaglio')
                    ->icon('heroicon-m-eye')
                    ->modalHeading(fn (AuditLog $r): string => $r->action->label())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->schema([
                        TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('actor_label')->label('Chi')->placeholder('sistema'),
                        TextEntry::make('actor_email')->label('Email')->placeholder('—'),
                        TextEntry::make('ip')->label('IP')->placeholder('—'),
                        TextEntry::make('user_agent')->label('Client')->placeholder('—'),
                        KeyValueEntry::make('payload')->label('Dettagli')->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Registro vuoto')
            ->emptyStateDescription('Qui finiscono impersonazioni, sospensioni e le altre azioni sensibili.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
