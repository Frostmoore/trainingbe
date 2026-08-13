<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members;

use App\Enums\UserRole;
use App\Filament\Gym\Concerns\ScopedToTrainer;
use App\Filament\Gym\Resources\Members\Pages\CreateMember;
use App\Filament\Gym\Resources\Members\Pages\EditMember;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Members\RelationManagers\WorkoutPlansRelationManager;
use App\Filament\Gym\Resources\Members\Schemas\MemberForm;
use App\Filament\Gym\Resources\Members\Tables\MembersTable;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Gli iscritti della palestra — B3.4.
 *
 * 🚨 **Due filtri sovrapposti, e servono entrambi:**
 *  - il global scope della tenancy limita alla palestra;
 *  - `limitaAgliAssegnati()` limita il **trainer** ai propri iscritti (B3.5).
 *
 * Il primo protegge un cliente dall'altro, il secondo protegge il cliente dal
 * proprio personale: un trainer che se ne va non deve poter portare via
 * l'elenco completo. Sono due minacce diverse e nessuno dei due filtri copre
 * l'altra.
 *
 * C'e' un terzo filtro implicito: solo chi ha il ruolo `member`. Senza,
 * l'elenco degli iscritti conterrebbe anche i trainer e l'amministratore.
 */
class MemberResource extends Resource
{
    use ScopedToTrainer;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'iscritto';

    protected static ?string $pluralModelLabel = 'iscritti';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereIn('id', static::idConRuolo(UserRole::Member));

        return static::limitaAgliAssegnati($query);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Anche il binding di rotta passa dagli stessi filtri: senza, un id
        // scritto a mano nell'URL aprirebbe la scheda di un iscritto che non e'
        // di questo trainer.
        return static::getEloquentQuery();
    }

    /**
     * Gli id degli utenti con un dato ruolo **nella palestra corrente**.
     *
     * Non `->role()` di spatie: quella relazione filtra sul team corrente e
     * funzionerebbe, ma genera una sottoquery diversa a ogni versione del
     * pacchetto. Una `whereIn` esplicita e' leggibile e non cambia sotto i piedi.
     */
    protected static function idConRuolo(UserRole $ruolo): \Illuminate\Database\Query\Builder
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $ruolo->value)
            ->where('model_has_roles.tenant_id', app(TenantContext::class)->id())
            ->select('model_has_roles.model_id');
    }

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WorkoutPlansRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
