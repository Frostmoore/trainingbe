<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans;

use App\Filament\God\Resources\Plans\Pages\CreatePianoSuMisura;
use App\Filament\God\Resources\Plans\Pages\EditPianoSuMisura;
use App\Filament\God\Resources\Plans\Pages\ListPianiSuMisura;
use App\Filament\God\Resources\Plans\Schemas\PlanForm;
use App\Filament\God\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * ✂️ **I piani su misura** — F9.4, 23/09/2026.
 *
 * 📌 Il committente, su «Offerte su misura»: *«dovrebbe essere la pagina dei
 * piani di abbonamento su misura, quindi deve avere gli stessi campi di piani e
 * listino · qui andranno anche le promozioni»*.
 *
 * 💡 Sono i `Plan` con `cucito_a_mano = true`: **stesso modulo e stesse schede**
 * di «Piani e listino» (`PlanForm`, `PlansTable` con `$suMisura`), più il link
 * di pagamento e l'attivazione a mano. Nel menu stanno nel gruppo **«Offerte su
 * misura»**, insieme ai link mandati (`OffertaDiPianoResource`) e alle
 * promozioni (`PromozioneResource`).
 */
class PianoSuMisuraResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected static string|UnitEnum|null $navigationGroup = 'Offerte su misura';

    protected static ?string $modelLabel = 'piano su misura';

    protected static ?string $pluralModelLabel = 'piani su misura';

    protected static ?string $navigationLabel = 'Piani su misura';

    protected static ?string $slug = 'piani-su-misura';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema, suMisura: true);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table, suMisura: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPianiSuMisura::route('/'),
            'create' => CreatePianoSuMisura::route('/create'),
            'edit' => EditPianoSuMisura::route('/{record}/edit'),
        ];
    }
}
