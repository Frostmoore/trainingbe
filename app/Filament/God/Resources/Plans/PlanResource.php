<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans;

use App\Filament\God\Resources\Plans\Pages\EditPlan;
use App\Filament\God\Resources\Plans\Pages\ListPlans;
use App\Filament\God\Resources\Plans\Schemas\PlanForm;
use App\Filament\God\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ✂️ **I piani, e quelli cuciti a mano** — 21/09/2026.
 *
 * 📌 Il committente: *«creami la possibilità di creare nuovi abbonamenti per
 * palestre con il numero di trainers che voglio io»*.
 *
 * ── 🚨 Chi comanda su una riga di `plans` ─────────────────────────────────
 *
 * | `cucito_a_mano` | La scrive | Da qui |
 * |---|---|---|
 * | `false` | `PlanSeeder`, da `config/listino.php` | si guarda |
 * | `true` | una persona | si crea, si modifica, si assegna |
 *
 * ⛔ **Nessun piano si cancella da qui.** `plan_subscriptions` punta a
 * `plans.id`: cancellare un piano lascerebbe senza piano chi ce l'ha, e la
 * storia degli abbonamenti — che serve a rispondere a «perché a marzo aveva
 * l'AI e ad aprile no?» — punterebbe nel vuoto. 💡 Un piano che non si vuole
 * più si spegne da `is_public`, come i piani ritirati.
 *
 * ── ⚠️ Cosa NON fa, e va saputo ───────────────────────────────────────────
 *
 * Un piano cucito a mano **non si vende dal sito**: `PianiInVendita::apri()`
 * cerca fra i piani `pubblici()`, ed è giusto così — un accordo cucito per un
 * cliente non deve diventare comprabile da chiunque abbia il link. Si assegna
 * da qui, e si fattura per accordo. 🚨 Se è **a consumo**, `AddebitoDelMese`
 * calcola comunque il dovuto ogni mese.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'piano';

    protected static ?string $pluralModelLabel = 'piani';

    protected static ?string $navigationLabel = 'Piani e listino';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /**
     * 🚨 **Si modifica solo ciò che il seeder non riscrive.** Il controllo sta
     * qui e non solo sul pulsante: `canEdit()` è la fonte di tutte le strade —
     * il pulsante, l'URL diretto della pagina di modifica, un'azione di gruppo.
     */
    /**
     * 🆕 F9.1 — **tutti** si modificano: 📌 la pagina del piano *«deve
     * contenere il prezzo, la temporalità, i tetti, in vendita»*. ⚠️ Prima
     * solo quelli cuciti a mano, perché il seeder riscriveva gli altri a ogni
     * deploy: adesso un piano toccato da qui è `modificato_a_mano` e il seeder
     * lo salta (`PlanSeeder`).
     */
    public static function canEdit(mixed $record): bool
    {
        return $record instanceof Plan;
    }

    /** I piani di listino nascono dal seeder: qui si cuciono solo quelli su misura, altrove. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema, suMisura: false);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table, suMisura: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
