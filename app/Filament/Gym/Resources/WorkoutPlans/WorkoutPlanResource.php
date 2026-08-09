<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\CreateWorkoutPlan;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\EditWorkoutPlan;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\ListWorkoutPlans;
use App\Filament\Gym\Resources\WorkoutPlans\Schemas\WorkoutPlanForm;
use App\Filament\Gym\Resources\WorkoutPlans\Tables\WorkoutPlansTable;
use App\Models\User;
use App\Models\WorkoutPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * L'editor delle schede — B4.4.
 *
 * 🚨 **Lo scoping del trainer qui e' diverso da quello degli iscritti**, e vale
 * la pena scriverlo: un trainer vede le schede dei **propri** assegnati **piu'**
 * tutti i **modelli** della palestra (`member_id` null). I modelli sono
 * patrimonio comune — e' il loro scopo — mentre una scheda assegnata e' il
 * programma di una persona precisa.
 */
class WorkoutPlanResource extends Resource
{
    protected static ?string $model = WorkoutPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'scheda';

    protected static ?string $pluralModelLabel = 'schede';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['member']);

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
        return WorkoutPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkoutPlansTable::configure($table);
    }

    /** Il numero accanto alla voce di menu: le bozze da finire. */
    public static function getNavigationBadge(): ?string
    {
        $bozze = static::getEloquentQuery()->where('status', PlanStatus::Draft->value)->count();

        return $bozze > 0 ? (string) $bozze : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<string, string> */
    public static function origini(): array
    {
        $out = [];

        foreach (PlanSource::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkoutPlans::route('/'),
            'create' => CreateWorkoutPlan::route('/create'),
            'edit' => EditWorkoutPlan::route('/{record}/edit'),
        ];
    }
}
