<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Exercises;

use App\Enums\MuscleGroup;
use App\Filament\God\Resources\Exercises\Pages\CreateExercise;
use App\Filament\God\Resources\Exercises\Pages\EditExercise;
use App\Filament\God\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\Scopes\TenantOrGlobalScope;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * La libreria esercizi della piattaforma — B2.4.
 *
 * 🚨 **Qui si vede TUTTO: i globali e quelli di ogni palestra.**
 * Non e' curiosita': gli esercizi custom nascono anche da `ExerciseMatcher`
 * (B7.3) quando un import da PDF non riconosce un nome, e senza un posto da cui
 * guardarli la libreria di ogni cliente degenera in poche settimane in decine di
 * varianti dello stesso esercizio. Da qui si vedono e si **promuovono** a globali
 * quelli che meritano di esserlo.
 *
 * Per questo la query toglie `TenantOrGlobalScope`: quello scope serve alle
 * palestre, che devono vedere i propri piu' i globali. La piattaforma deve
 * vedere l'insieme.
 */
class ExerciseResource extends Resource
{
    protected static ?string $model = Exercise::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'esercizio';

    protected static ?string $pluralModelLabel = 'esercizi';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class, TenantOrGlobalScope::class])
            ->with('tenant');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Esercizio')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    Select::make('muscle_group')
                        ->label('Gruppo muscolare')
                        ->options(MuscleGroup::options())
                        ->native(false),

                    TextInput::make('equipment')
                        ->label('Attrezzo')
                        ->maxLength(64)
                        ->datalist(['corpo libero', 'bilanciere', 'manubri', 'macchina', 'cavi', 'kettlebell', 'elastico']),

                    TextInput::make('met')
                        ->label('MET')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->step(0.1)
                        ->helperText('Dispendio metabolico. Vuoto: si usa il valore generico 5.0 del calcolo calorie.'),

                    Select::make('tenant_id')
                        ->label('Palestra')
                        ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('Nessuna — esercizio della piattaforma')
                        ->helperText('Vuoto = visibile a tutte le palestre.'),

                    Textarea::make('description')
                        ->label('Descrizione / esecuzione')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Esercizio')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Exercise $r): ?string => $r->equipment),

                TextColumn::make('muscle_group')
                    ->label('Gruppo')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?MuscleGroup $state): string => $state?->label() ?? '—')
                    ->color('gray'),

                TextColumn::make('tenant.name')
                    ->label('Di chi e\'')
                    ->placeholder('Piattaforma')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'warning')
                    ->sortable(),

                IconColumn::make('is_custom')
                    ->label('Nato da import')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('met')
                    ->label('MET')
                    ->placeholder('5,0 (generico)')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('slug_normalized')
                    ->label('Forma canonica')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->description('E\' la colonna su cui il riconoscitore dei PDF cerca.'),
            ])
            ->filters([
                SelectFilter::make('muscle_group')
                    ->label('Gruppo muscolare')
                    ->options(MuscleGroup::options()),

                SelectFilter::make('tenant_id')
                    ->label('Palestra')
                    ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Filter::make('globali')
                    ->label('Solo della piattaforma')
                    ->query(fn (Builder $q): Builder => $q->whereNull('tenant_id')),

                Filter::make('da_import')
                    ->label('Nati da un import non riconosciuto')
                    ->query(fn (Builder $q): Builder => $q->where('is_custom', true)),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * 🚨 Promuovere e' l'azione che rende utile questa pagina.
                 *
                 * «Panca piana» creata a mano da otto palestre diverse e' otto
                 * righe che dicono la stessa cosa. Portandone una a globale, le
                 * palestre successive la trovano gia' pronta — e la libreria
                 * smette di crescere per duplicazione.
                 */
                Action::make('promuovi')
                    ->label('Rendi globale')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->color('success')
                    ->visible(fn (Exercise $r): bool => ! $r->isGlobal())
                    ->requiresConfirmation()
                    ->modalHeading('Renderlo un esercizio della piattaforma?')
                    ->modalDescription('Diventera\' visibile a tutte le palestre. Resta collegato alle schede in cui e\' gia\' usato.')
                    ->action(function (Exercise $r): void {
                        $r->forceFill(['tenant_id' => null, 'is_custom' => false])->save();

                        Notification::make()
                            ->title($r->name.' e\' ora della piattaforma')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Libreria vuota')
            ->emptyStateDescription('Aggiungi gli esercizi di base: ogni palestra nuova se li trovera\' gia\' pronti.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExercises::route('/'),
            'create' => CreateExercise::route('/create'),
            'edit' => EditExercise::route('/{record}/edit'),
        ];
    }
}
