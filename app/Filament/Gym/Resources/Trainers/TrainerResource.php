<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\Pages\CreateTrainer;
use App\Filament\Gym\Resources\Trainers\Pages\EditTrainer;
use App\Filament\Gym\Resources\Trainers\Pages\ListTrainers;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * I trainer della palestra — B3.3.
 *
 * 🚨 **Solo il gym_admin.** Un trainer non gestisce gli altri trainer: potrebbe
 * assegnarsi gli iscritti dei colleghi, che e' esattamente il confine che lo
 * scoping di B3.5 esiste per difendere.
 */
class TrainerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'trainer';

    protected static ?string $pluralModelLabel = 'trainer';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isGymAdmin() === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Trainer->value)
                ->where('model_has_roles.tenant_id', app(TenantContext::class)->id())
                ->select('model_has_roles.model_id'));
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nome e cognome')->required()->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: 'users',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where(
                                'tenant_id',
                                app(TenantContext::class)->id(),
                            ),
                        ),

                    TextInput::make('username')
                        ->label('Nome utente')
                        ->maxLength(30)
                        ->unique(table: 'users', ignoreRecord: true)
                        ->helperText('Puo\' usarlo per entrare al posto dell\'email.'),

                    TextInput::make('phone')->label('Telefono')->tel()->maxLength(32),

                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state)),

                    Toggle::make('is_active')
                        ->label('Attivo')
                        ->default(true)
                        ->helperText('Disattivato: non entra piu\' nel pannello. Gli iscritti assegnati restano.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Trainer')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $r): ?string => $r->email),

                TextColumn::make('assigned_members_count')
                    ->label('Iscritti seguiti')
                    ->counts('assignedMembers')
                    ->alignCenter()
                    ->sortable(),

                IconColumn::make('is_active')->label('Attivo')->boolean()->alignCenter(),

                TextColumn::make('last_login_at')
                    ->label('Ultimo accesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('mai')
                    ->toggleable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessun trainer')
            ->emptyStateDescription('Aggiungine uno per poter assegnare gli iscritti.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainers::route('/'),
            'create' => CreateTrainer::route('/create'),
            'edit' => EditTrainer::route('/{record}/edit'),
        ];
    }
}
