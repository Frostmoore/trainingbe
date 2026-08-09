<?php

namespace App\Filament\God\Resources\Tenants;

use App\Filament\God\Resources\Tenants\Pages\CreateTenant;
use App\Filament\God\Resources\Tenants\Pages\EditTenant;
use App\Filament\God\Resources\Tenants\Pages\ListTenants;
use App\Filament\God\Resources\Tenants\Schemas\TenantForm;
use App\Filament\God\Resources\Tenants\Tables\TenantsTable;
use App\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Le palestre clienti, dal pannello della piattaforma.
 *
 * 🚨 **Solo il super admin.** Il pannello `/god` è già chiuso agli altri da
 * `User::canAccessPanel()`, ma il controllo si ripete qui: chi arrivasse a
 * questa risorsa per un'altra strada — un link diretto, una futura
 * riorganizzazione dei pannelli — non deve trovarla aperta. Un solo controllo
 * in un posto solo è un controllo che prima o poi qualcuno aggira senza volerlo.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'palestra';

    protected static ?string $pluralModelLabel = 'palestre';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** Il numero accanto alla voce di menù: quante palestre attive ci sono. */
    public static function getNavigationBadge(): ?string
    {
        return (string) Tenant::query()
            ->where('status', \App\Enums\TenantStatus::Active->value)
            ->count();
    }

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
