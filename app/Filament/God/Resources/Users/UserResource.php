<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users;

use App\Filament\God\Resources\Users\Pages\EditUser;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Filament\God\Resources\Users\Schemas\UserForm;
use App\Filament\God\Resources\Users\Tables\UsersTable;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Tutti gli utenti della piattaforma, di ogni palestra.
 *
 * 🚨 **Solo il super admin**, con il controllo ripetuto qui e non solo nel
 * pannello: e' la stessa regola di `TenantResource`, e vale doppio in una
 * risorsa che espone gli account di tutti i clienti insieme.
 *
 * **Perche' vede tutto.** Il pannello `/god` gira senza contesto di palestra e
 * `TenantScope` non filtrerebbe comunque. Ma `getEloquentQuery()` lo toglie
 * **esplicitamente**: dipendere dall'assenza di qualcosa e' fragile — basterebbe
 * che un giorno qualcuno aggiunga `ResolveTenant` a questo pannello e l'elenco
 * si svuoterebbe senza nessun errore, mostrando solo gli utenti di una palestra
 * a chi crede di vederle tutte.
 *
 * **Perche' non si creano utenti da qui.** Un utente nasce dentro una palestra:
 * o si registra dall'app col codice d'invito, o lo crea il gym_admin dal proprio
 * pannello (B3.4). Una creazione dal pannello di piattaforma dovrebbe chiedere
 * la palestra e i ruoli, e sarebbe l'unico punto del sistema in cui un account
 * nasce senza che nessuno della palestra lo sappia. Restano modifica,
 * disattivazione e impersonazione — cioe' le tre cose che il supporto fa davvero.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'utente';

    protected static ?string $pluralModelLabel = 'utenti';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * L'elenco deve mostrare gli utenti di tutte le palestre, cancellati
     * compresi: chi fa supporto ha bisogno di vedere anche l'account che
     * «non c'e' piu'», altrimenti l'unica risposta possibile e' «non lo trovo».
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class, TenantScope::class])
            ->with('tenant');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class, TenantScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
