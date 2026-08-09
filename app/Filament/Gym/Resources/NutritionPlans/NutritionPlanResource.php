<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\NutritionPlans;

use App\Enums\AuditAction;
use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\NutritionPlans\Pages\CreateNutritionPlan;
use App\Filament\Gym\Resources\NutritionPlans\Pages\EditNutritionPlan;
use App\Filament\Gym\Resources\NutritionPlans\Pages\ListNutritionPlans;
use App\Models\NutritionPlan;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Nutrition\CalorieCalculator;
use App\Services\Nutrition\FoodUnit;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * L'editor dei piani alimentari — B5.4.
 *
 * Tre livelli annidati (piano → pasti → alimenti) e non un campo libero, perche'
 * il piano deve essere **calcolabile**: senza struttura non si puo' dire quanto
 * l'iscritto vi abbia aderito, che e' la domanda per cui la palestra paga.
 */
class NutritionPlanResource extends Resource
{
    protected static ?string $model = NutritionPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCake;

    protected static ?string $modelLabel = 'piano alimentare';

    protected static ?string $pluralModelLabel = 'piani alimentari';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('member');

        $utente = auth()->user();

        if (! $utente instanceof User || $utente->isSuperAdmin() || $utente->isGymAdmin()) {
            return $query;
        }

        if (! $utente->isTrainer()) {
            return $query->whereRaw('1 = 0');
        }

        $assegnati = $utente->assignedMembers()->pluck('users.id')->all();

        return $query->where(
            fn (Builder $q) => $q->whereNull('member_id')->orWhereIn('member_id', $assegnati),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Il piano')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nome')->required()->maxLength(160)->columnSpanFull(),

                    Select::make('member_id')
                        ->label('Assegnato a')
                        ->options(fn (): array => static::iscritti())
                        ->searchable()
                        ->placeholder('Nessuno — e\' un modello riutilizzabile'),

                    Select::make('status')
                        ->label('Stato')
                        ->options(PlanStatus::options())
                        ->default(PlanStatus::Draft->value)
                        ->required()
                        ->native(false),

                    Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Obiettivi giornalieri')
                ->description('L\'app li usa come target. Se restano vuoti, li calcola dal profilo dell\'iscritto.')
                ->columns(4)
                ->schema([
                    TextInput::make('target_kcal')
                        ->label('kcal')
                        ->numeric()
                        ->minValue(800)
                        ->maxValue(8000)
                        ->live(onBlur: true)
                        // I macro si propongono da soli: compilarli a mano
                        // significa, nella pratica, non compilarli.
                        ->afterStateUpdated(function (?int $state, callable $set): void {
                            if ($state === null || $state < 800) {
                                return;
                            }

                            $m = app(CalorieCalculator::class)->macros($state, 'maintain');

                            $set('target_protein_g', $m['protein_g']);
                            $set('target_carbs_g', $m['carbs_g']);
                            $set('target_fat_g', $m['fat_g']);
                        }),

                    TextInput::make('target_protein_g')->label('Proteine (g)')->numeric()->minValue(0),
                    TextInput::make('target_carbs_g')->label('Carboidrati (g)')->numeric()->minValue(0),
                    TextInput::make('target_fat_g')->label('Grassi (g)')->numeric()->minValue(0),
                ]),

            Section::make('Pasti')
                ->schema([
                    Repeater::make('meals')
                        ->label('')
                        ->relationship()
                        ->orderColumn('position')
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel('Aggiungi pasto')
                        ->itemLabel(fn (array $state): ?string => isset($state['meal'])
                            ? (MealType::tryFrom($state['meal'])?->label() ?? 'Pasto')
                            : null)
                        ->defaultItems(0)
                        ->schema([
                            Select::make('meal')
                                ->label('Pasto')
                                ->options(MealType::options())
                                ->required()
                                ->native(false),

                            TextInput::make('title')->label('Titolo')->maxLength(160),

                            Repeater::make('items')
                                ->label('Alimenti')
                                ->relationship()
                                ->orderColumn('position')
                                ->reorderable()
                                ->addActionLabel('Aggiungi alimento')
                                ->defaultItems(0)
                                ->columns(6)
                                ->schema([
                                    TextInput::make('description')
                                        ->label('Alimento')
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpan(3),

                                    TextInput::make('qty')->label('Quantita')->numeric()->minValue(0),

                                    Select::make('unit')
                                        ->label('Unita')
                                        ->options(FoodUnit::options())
                                        ->native(false),

                                    TextInput::make('kcal')->label('kcal')->numeric()->minValue(0),

                                    Textarea::make('alternatives')
                                        ->label('Alternative ammesse')
                                        ->rows(2)
                                        ->columnSpanFull()
                                        ->helperText('«oppure 150 g di merluzzo». Senza questo campo finirebbe nelle note, dove nessun calcolo lo legge.'),
                                ]),

                            Textarea::make('notes')->label('Note del pasto')->rows(2)->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Piano')->searchable()->sortable()->weight('bold'),

                TextColumn::make('member.name')
                    ->label('Assegnato a')
                    ->placeholder('Modello')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => $state->label())
                    ->color(fn (PlanStatus $state): string => $state->color()),

                TextColumn::make('target_kcal')->label('kcal')->alignCenter()->placeholder('—'),

                TextColumn::make('meals_count')->label('Pasti')->counts('meals')->alignCenter(),

                TextColumn::make('updated_at')->label('Modificato')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(PlanStatus::options()),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('assegna')
                    ->label('Assegna')
                    ->icon('heroicon-m-user-plus')
                    ->color('success')
                    ->visible(fn (NutritionPlan $r): bool => $r->isTemplate())
                    ->schema([
                        Select::make('member_id')
                            ->label('A chi')
                            ->options(fn (): array => static::iscritti())
                            ->searchable()
                            ->required(),
                    ])
                    ->modalDescription('Ne verra\' creata una copia sua: le modifiche successive non toccheranno il modello.')
                    ->action(function (NutritionPlan $r, array $data): void {
                        $membro = User::find($data['member_id']);

                        if ($membro === null) {
                            return;
                        }

                        $r->assignTo($membro, auth()->user());

                        Notification::make()
                            ->title("Copiato su {$membro->name}")
                            ->body('E\' una bozza: pubblicalo quando e\' pronto.')
                            ->success()
                            ->send();
                    }),

                Action::make('pubblica')
                    ->label(fn (NutritionPlan $r): string => $r->status === PlanStatus::Published ? 'Archivia' : 'Pubblica')
                    ->icon(fn (NutritionPlan $r): string => $r->status === PlanStatus::Published
                        ? 'heroicon-m-archive-box'
                        : 'heroicon-m-paper-airplane')
                    ->color(fn (NutritionPlan $r): string => $r->status === PlanStatus::Published ? 'warning' : 'success')
                    ->visible(fn (NutritionPlan $r): bool => ! $r->isTemplate())
                    ->requiresConfirmation()
                    ->action(function (NutritionPlan $r): void {
                        if ($r->status === PlanStatus::Published) {
                            $r->update(['status' => PlanStatus::Archived]);

                            return;
                        }

                        $r->publish();

                        app(AuditLogger::class)->log(
                            AuditAction::NutritionPlanPublished,
                            $r,
                            ['member_id' => $r->member_id, 'name' => $r->name],
                            tenant: $r->tenant_id,
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Nessun piano alimentare')
            ->emptyStateDescription('Crea un modello riutilizzabile, oppure un piano per un iscritto.');
    }

    /** @return array<int, string> */
    private static function iscritti(): array
    {
        $tenantId = app(TenantContext::class)->id();

        $query = User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Member->value)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->select('model_has_roles.model_id'));

        $utente = auth()->user();

        if ($utente instanceof User && $utente->isTrainer() && ! $utente->isGymAdmin()) {
            $query->whereIn('id', $utente->assignedMembers()->pluck('users.id')->all());
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNutritionPlans::route('/'),
            'create' => CreateNutritionPlan::route('/create'),
            'edit' => EditNutritionPlan::route('/{record}/edit'),
        ];
    }
}
