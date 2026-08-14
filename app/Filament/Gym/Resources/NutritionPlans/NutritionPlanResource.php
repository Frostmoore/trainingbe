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
 * L'editor dei piani alimentari — B5.4, esteso in G6.2.
 *
 * **Quattro** livelli annidati (piano → giorni → pasti → alimenti) e non un
 * campo libero, perche' il piano deve essere **calcolabile**.
 *
 * ── 🚨 La motivazione che era scritta qui, e non vale piu' ────────────────
 *
 * Diceva: *«senza struttura non si puo' dire quanto l'iscritto vi abbia aderito,
 * che e' la domanda per cui la palestra paga»*.
 *
 * ⚠️ **Da D4 quella domanda il server non se la pone piu'.** I piani si
 * consegnano via chat cifrata e restano anonimi: `member_id` non viene
 * valorizzato, quindi non c'e' nessun «l'iscritto» di cui misurare l'aderenza.
 * Il calcolo si fa **sul telefono**, dove ci sono sia il piano sia il diario, e
 * l'allievo decide se mostrarlo.
 *
 * 💡 **La struttura serve ancora, per due ragioni diverse e piu' forti**: perche'
 * i totali si calcolino a ogni livello (D14), e perche' un alimento — o
 * un'alternativa — possa essere **importato nel diario con un tocco**. Un campo
 * libero non si importa.
 *
 * 🚨 E' la forma di errore che questo progetto incontra di continuo: una
 * motivazione giusta che resta scritta mentre il presupposto e' gia' cambiato.
 * Qui e' stata corretta il 13/08/2026, lo stesso giorno in cui D4 l'ha smentita.
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

                    /*
                     * ⛔ **«Assegnato a» NON esiste piu'** — 14/08/2026. Vedi la
                     * nota gemella in `WorkoutPlanForm`: un piano legato a una
                     * persona **sul server** e' dato sanitario, e la decisione
                     * del committente e' che non lo teniamo.
                     *
                     * 💡 Al suo posto il «Rif. Allievo»: pseudonimizzazione
                     * scelta dal trainer, visibile solo a lui.
                     */

                    /*
                     * D3 — il promemoria privato di chi scrive il piano.
                     *
                     * 🚨 **Lo vede solo chi l'ha scritto** (R4), e **non entra
                     * mai nella busta cifrata**: e' l'etichetta del trainer,
                     * mandarla vorrebbe dire mostrare all'allievo come lo si
                     * chiama negli appunti.
                     *
                     * ⚠️ Il suggerimento alle iniziali non e' cortesia: il
                     * campo sta **in chiaro sul server**, e da un piano si
                     * capisce molto di chi lo segue. Scriverci il nome per
                     * esteso rimette sul server esattamente il legame che
                     * l'anonimato dei piani serve a togliere.
                     */
                    /*
                     * 🚨 **`visible()` non basta, e il test l\'ha dimostrato.**
                     *
                     * Livewire serializza lo **stato del modulo** dentro la
                     * pagina: un campo nascosto ma **idratato** manda il suo
                     * valore al browser lo stesso, dentro lo snapshot. Il primo
                     * tentativo usava solo `visible()`, e il valore compariva
                     * nell\'HTML di un altro trainer.
                     *
                     * 💡 `formatStateUsing()` interviene **sull\'idratazione**:
                     * per chi non e\' l\'autore lo stato diventa `null`, quindi
                     * non c\'e\' niente da serializzare. `visible()` resta, ma per
                     * non mostrare un campo vuoto — non e\' piu\' lui la difesa.
                     *
                     * ⚠️ La regola generale: nascondere non e\' non mandare.
                     */
                    TextInput::make('rif_allievo')
                        ->label('Rif. Allievo')
                        ->maxLength(120)
                        ->placeholder('M.R. spalla dx')
                        ->helperText(
                            'Un tuo promemoria, per ritrovare il piano. '
                            .'Meglio le iniziali: lo vedi solo tu, ma resta scritto sul server.'
                        )
                        ->formatStateUsing(fn (?string $state, ?NutritionPlan $record): ?string => $record !== null
                            && $record->created_by !== auth()->id() ? null : $state)
                        ->visible(fn (?NutritionPlan $record): bool => $record === null
                            || $record->created_by === auth()->id()),

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

            Section::make('Giorni')
                ->description(
                    'Un piano puo\' avere piu\' giorni, e ogni giorno puo\' avere un\'alternativa. '
                    .'Un piano a un solo giorno si scrive lasciando il nome vuoto.'
                )
                ->schema([
                    Repeater::make('days')
                        ->label('')
                        ->relationship()
                        ->orderColumn('position')
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel('Aggiungi giorno')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Giorno')
                        ->defaultItems(1)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nome del giorno')
                                ->maxLength(120)
                                ->placeholder('Giorno A - lasciare vuoto se il piano ha un giorno solo')
                                ->columnSpanFull(),

                            ...self::pastiDelGiorno(),

                            /*
                             * D2 - le alternative di giornata.
                             *
                             * 🚨 **Un livello solo**: dentro un giorno alternativo
                             * non ci sono altre alternative. Se ci fossero, «quale
                             * piano sto seguendo» smetterebbe di avere una risposta
                             * unica - e il modulo diventerebbe un albero infinito.
                             */
                            Repeater::make('alternative')
                                ->label('Giorni alternativi')
                                ->relationship()
                                ->orderColumn('position')
                                ->collapsible()
                                ->collapsed()
                                ->addActionLabel('Aggiungi un giorno alternativo')
                                ->maxItems(3)
                                ->defaultItems(0)
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Alternativa')
                                ->helperText('Al massimo tre. L\'allievo ne segue uno al posto di questo.')
                                ->schema([
                                    TextInput::make('name')->label('Nome')->maxLength(120)->columnSpanFull(),
                                    ...self::pastiDelGiorno(conAlternative: false),
                                ])
                                ->columnSpanFull(),

                            Textarea::make('notes')->label('Note del giorno')->rows(2)->columnSpanFull(),
                        ]),
                ]),

        ]);
    }

    /**
     * I pasti di un giorno - G6.2.
     *
     * 💡 Scritti una volta e riusati per i giorni veri **e** per quelli
     * alternativi: un pasto e\' un pasto. Due copie divergerebbero, e la prima a
     * divergere sarebbe quella dei giorni alternativi, cioe\' quella che nessuno
     * prova.
     *
     * @param  bool  $conAlternative  se generare anche i pasti alternativi
     *                                (⚠️ `false` dentro un giorno alternativo:
     *                                le alternative si fermano a un livello, D2)
     * @return list<mixed>
     */
    private static function pastiDelGiorno(bool $conAlternative = true): array
    {
        return [
            Repeater::make('meals')
                ->label('Pasti')
                ->relationship()
                ->orderColumn('position')
                ->reorderable()
                ->collapsible()
                ->addActionLabel('Aggiungi pasto')
                ->itemLabel(fn (array $state): ?string => isset($state['meal'])
                    ? (MealType::tryFrom($state['meal'])?->label() ?? 'Pasto')
                    : null)
                ->defaultItems(0)
                ->schema(array_values(array_filter([
                    Select::make('meal')
                        ->label('Pasto')
                        ->options(MealType::options())
                        ->required()
                        ->native(false),

                    TextInput::make('title')->label('Titolo')->maxLength(160),

                    self::alimenti(),

                    $conAlternative
                        ? Repeater::make('alternative')
                            ->label('Pasti alternativi')
                            ->relationship()
                            ->orderColumn('position')
                            ->collapsible()
                            ->collapsed()
                            ->addActionLabel('Aggiungi un pasto alternativo')
                            ->maxItems(3)
                            ->defaultItems(0)
                            ->helperText('Al massimo tre. Sostituiscono questo pasto per intero.')
                            ->schema([
                                Select::make('meal')
                                    ->label('Pasto')
                                    ->options(MealType::options())
                                    ->required()
                                    ->native(false),
                                TextInput::make('title')->label('Titolo')->maxLength(160),
                                self::alimenti(conAlternative: false),
                            ])
                            ->columnSpanFull()
                        : null,

                    Textarea::make('notes')->label('Note del pasto')->rows(2)->columnSpanFull(),
                ])))
                ->columnSpanFull(),
        ];
    }

    /**
     * Gli alimenti di un pasto, con le loro alternative - G6.2 (D2).
     *
     * 🚨 **Il campo `alternatives` come `Textarea` non esiste piu\'.** Era una
     * colonna `text` con dentro JSON, e un\'alternativa scritta cosi\' **non ha
     * macro proprie**: sceglierla non diceva al diario cosa scrivere. Da G4 e\'
     * una riga con gli stessi campi dell\'alimento che sostituisce.
     *
     * ⚠️ Questo modulo era **rotto** fino a G6: puntava a una colonna tolta
     * dalla migrazione `2026_08_14_150000`.
     */
    private static function alimenti(bool $conAlternative = true): Repeater
    {
        $campi = [
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
        ];

        if ($conAlternative) {
            $campi[] = Repeater::make('alternative')
                ->label('Oppure')
                ->relationship()
                ->orderColumn('position')
                ->addActionLabel('Aggiungi un\'alternativa')
                ->maxItems(3)
                ->defaultItems(0)
                ->columns(6)
                ->columnSpanFull()
                ->helperText(
                    'Al massimo tre. 🚨 Vanno messe anche le kcal: senza, '
                    .'sceglierla non dice al diario dell\'allievo cosa scrivere.'
                )
                ->schema([
                    TextInput::make('description')->label('Alimento')->required()->maxLength(255)->columnSpan(3),
                    TextInput::make('qty')->label('Quantita')->numeric()->minValue(0),
                    Select::make('unit')->label('Unita')->options(FoodUnit::options())->native(false),
                    TextInput::make('kcal')->label('kcal')->numeric()->minValue(0),
                ]);
        }

        return Repeater::make('items')
            ->label('Alimenti')
            ->relationship()
            ->orderColumn('position')
            ->reorderable()
            ->addActionLabel('Aggiungi alimento')
            ->defaultItems(0)
            ->columns(6)
            ->columnSpanFull()
            ->schema($campi);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Piano')->searchable()->sortable()->weight('bold'),

                /*
                 * 💡 **Il «Rif. Allievo» al posto di «Assegnato a».**
                 *
                 * E' la sola cosa che distingue due piani che si chiamano
                 * uguale, ed e' anche l'unica che il trainer puo' usare per
                 * ritrovare il proprio: il server non sa a chi sia stato
                 * mandato niente.
                 *
                 * 🚨 `visible()` sull'utente e non sulla riga: la colonna
                 * sparisce per chi non e' l'autore, e comunque lo scoping mostra
                 * solo i piani suoi. R4.
                 */
                TextColumn::make('rif_allievo')
                    ->label('Rif. Allievo')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),

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

                /*
                 * ⛔ **L'azione «Assegna» e' stata tolta** — 14/08/2026.
                 *
                 * Creava una copia del piano **addosso a una persona**, con
                 * `member_id` valorizzato: esattamente il legame
                 * persona-programma che D4 ha deciso di non tenere sul server.
                 *
                 * 🎯 Al suo posto c'e' la **chat cifrata**: il trainer apre la
                 * conversazione e manda il piano, che viaggia dentro una busta
                 * che il server instrada senza poterla leggere.
                 *
                 * 💡 `WorkoutPlan::assignTo()` e `NutritionPlan::assignTo()`
                 * **restano nel codice**: servono ancora a duplicare un piano, e
                 * duplicare non e' assegnare. Quello che non c'e' piu' e' il
                 * pulsante che lo faceva verso una persona.
                 */

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
