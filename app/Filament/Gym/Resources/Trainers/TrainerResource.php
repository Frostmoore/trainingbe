<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Trainers;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\Pages\CreateTrainer;
use App\Filament\Gym\Resources\Trainers\Pages\EditTrainer;
use App\Filament\Gym\Resources\Trainers\Pages\ListTrainers;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Tenancy\TogliUnTrainerDallaPalestra;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
            /*
             * ══ 🆕 🚨 ANCHE IL `TenantScope`, O I TRAINER SPARISCONO — 18/09 ══
             *
             * ⛔ `User` usa `BelongsToTenant`, quindi di serie questa query
             * guardava solo chi ha `users.tenant_id` = la palestra. 🚨 Un
             * trainer con **la casa altrove** — cioè, dalla decisione 2, *ogni*
             * trainer — **spariva dall'elenco**: la palestra non lo vedeva, non
             * lo poteva aprire, e non lo poteva congedare.
             *
             * ⚠️ **E succedeva già prima**, per chi ha due cappelli (AH.1-quater):
             * un trainer con un giro suo, che lavora anche qui, non compariva
             * fra i trainer della palestra che lo paga.
             *
             * 💡 **Toglierlo non apre niente a nessuno**: l'isolamento lo fa già
             * il filtro qui sotto, che pretende il ruolo `trainer` **dentro
             * questa palestra**. Si vede chi lavora qui, e solo lui.
             */
            ->withoutGlobalScopes([SoftDeletingScope::class, TenantScope::class])
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

                /*
                 * ══ ⛔ VIA «ISCRITTI SEGUITI» — 23/09/2026, F5.9 ════════════
                 *
                 * 📌 Il committente la vuole fuori, e dall'altro lato sparisce
                 * «seguito da» dall'elenco degli iscritti (F5.6).
                 *
                 * 🚨 **Non misurava più niente.** Dal 16/09 un trainer vede
                 * **tutta** la palestra, come già la vede nell'app; e dopo R2
                 * nemmeno le schede passano dall'assegnazione. ⛔ Quel numero
                 * diceva «ne segue 3» a chi in sala li allena tutti.
                 *
                 * ── ✅ Al suo posto: COSA HA PRODOTTO ────────────────────────
                 *
                 * 💡 È la domanda vera davanti a un elenco di trainer: chi sta
                 * scrivendo il patrimonio della palestra, e chi no.
                 */
                TextColumn::make('schede_create')
                    ->label('Schede scritte')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->state(fn (User $r): int => static::quante($r, 'workout_plans')),

                /*
                 * ⚠️ **Questo numero oggi può solo essere zero**, e va detto.
                 *
                 * 📌 Il committente lo ha chiesto (F5.9) nella stessa sessione
                 * in cui ha deciso F5.15: *«Non esistono quelli vecchi, quindi
                 * si tolgono e basta»* — i piani alimentari sono usciti dal
                 * pannello della palestra, quindi da qui non ne nascono più.
                 *
                 * 💡 Resta perché conta **quelli scritti prima**, e perché se un
                 * giorno la funzione tornasse, la colonna è già al suo posto.
                 * 🚨 Se questa riga scrive 0 a tutti per sempre, va tolta: è la
                 * stessa regola che ha fatto sparire «senza scheda pubblicata».
                 */
                TextColumn::make('consigli_creati')
                    ->label('Consigli alimentari')
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->state(fn (User $r): int => static::quante($r, 'nutrition_plans'))
                    ->toggleable(),

                IconColumn::make('is_active')->label('Attivo')->boolean()->alignCenter(),

                TextColumn::make('last_login_at')
                    ->label('Ultimo accesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('mai')
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * 🆕 🚪 **Congedare chi ha un giro suo** — 3b-AJ.6, 16/09/2026.
                 *
                 * ⛔ **Per questa persona gli altri due gesti non vanno bene.**
                 * «Spegni» e «cancella» stanno sull'**account**, non sul rapporto
                 * con questa palestra: la chiuderebbero fuori anche da casa
                 * sua. ✅ `UserPolicy` glielo impedisce già — confronta i
                 * `tenant_id` — ma il rovescio era che la palestra non poteva
                 * **nemmeno mandarla via**.
                 *
                 * ⚠️ **Si vede solo a chi serve**: per un trainer che vive dentro
                 * la palestra non c'è nessun posto dove tornare, e la strada
                 * resta quella di sempre.
                 */
                Action::make('togliDallaPalestra')
                    ->label('Togli dalla palestra')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->visible(fn (User $r): bool => $r->tenant_id !== app(TenantContext::class)->id())
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => "Togliere {$r->name} dalla palestra?")
                    /*
                     * 💡 **La conferma dice cosa succede, non «sei sicuro?».**
                     * ⚠️ Chi preme deve sapere che l'account resta in piedi e che
                     * le schede se le porta via: sono le due cose che
                     * sorprenderebbero dopo.
                     */
                    ->modalDescription(
                        "Non allenera' piu' qui e non vedra' piu' gli iscritti di questa "
                        .'palestra. Il suo account resta suo, insieme ai suoi allievi personali '
                        ."e alle schede che ha scritto. Le schede gia' consegnate restano a chi "
                        .'le ha ricevute.'
                    )
                    ->modalSubmitActionLabel('Togli dalla palestra')
                    ->action(function (User $r): void {
                        $palestra = app(TenantContext::class)->get();

                        if ($palestra === null) {
                            return;
                        }

                        app(TogliUnTrainerDallaPalestra::class)($r, $palestra);

                        Notification::make()
                            ->title("{$r->name} non fa più parte della palestra")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessun trainer')
            ->emptyStateDescription('Mandagli un link d\'invito: vale per una persona sola e scade dopo sette giorni.');
    }

    /**
     * Quante righe di questa tabella ha scritto questa persona — F5.9.
     *
     * ⚠️ **`created_by`, non `tenant_id`**: la domanda è «quanto ha prodotto
     * lui», e un trainer con un portfolio suo scrive dentro due spazi diversi.
     *
     * 💡 `DB::table()` e non il modello: qui serve un conteggio, e passare da
     * Eloquent vorrebbe dire trascinarsi dietro i global scope — compreso
     * quello del tenant, che su un trainer con due cappelli darebbe il numero
     * sbagliato.
     */
    private static function quante(User $trainer, string $tabella): int
    {
        return (int) DB::table($tabella)
            ->where('created_by', $trainer->getKey())
            ->whereNull('deleted_at')
            ->count();
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
