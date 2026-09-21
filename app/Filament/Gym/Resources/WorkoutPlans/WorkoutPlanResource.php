<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans;

use App\Enums\PlanSource;
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
        /*
         * ══ 📄 SOLO I MODELLI DELLA PALESTRA — 22/09/2026, F5.14 e F5.10 ═══
         *
         * 📌 R2: *«Le schede vivono sui telefoni. Non ci sono destinatari.
         * Quando un trainer (o una palestra) creano una scheda, questa va a
         * finire tra le schede di tutti i trainer della palestra, e dal
         * telefono questi le possono inviare a chi vogliono.»*
         *
         * ⛔ **`member_id IS NULL`, e senza eccezioni.** Le righe con un
         * destinatario esistono ancora, ma sono le schede che **l'iscritto
         * scrive per sé** dall'app: sono sue, non della palestra, e in questo
         * elenco non ci devono comparire — né per il gestore né per il trainer.
         *
         * 💡 **E questo chiude anche F5.10**: se tutto ciò che c'è qui è un
         * modello della palestra, allora la palestra vede le schede dei suoi
         * trainer **per costruzione**, senza nessuna regola in più. Lo scoping
         * per assegnati non serve più: non c'era per i modelli neanche prima.
         *
         * ⚠️ `with('creator')` e non più `with('member')`: la colonna che
         * conta adesso è **chi l'ha scritta**.
         */
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNull('member_id')
            ->with(['creator']);

        $utente = auth()->user();

        /*
         * 🚨 Chi non è né gestore né trainer non vede niente. ⛔ Il caso esiste
         * davvero: un trainer indipendente che apre il pannello senza palestra
         * (vedi `RuoliSenzaPalestraTest`).
         */
        if (! $utente instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($utente->isSuperAdmin() || $utente->isGymAdmin() || $utente->isTrainer()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
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

    /**
     * ⛔ **Il numeretto delle bozze non c'è più** — 22/09/2026, F5.14.
     *
     * Contava le schede in stato `draft`, e lo stato è la cosa che F5.14
     * toglie: senza destinatari non esiste «bozza da finire e pubblicare».
     * ⚠️ Un badge che conta sempre zero è peggio di nessun badge — fa credere
     * che non ci sia niente da fare in un posto dove nessuno guarda più.
     */
    public static function getNavigationBadge(): ?string
    {
        return null;
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
