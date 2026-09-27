<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports;

use App\Enums\ImportStatus;
use App\Filament\Gym\Resources\PlanImports\Pages\ListPlanImports;
use App\Filament\Gym\Resources\PlanImports\Pages\ReviewPlanImport;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\EditWorkoutPlan;
use App\Jobs\ParseWorkoutPdf;
use App\Models\WorkoutPlanImport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'import di schede da PDF, lato palestra — B7.1 / B7.4.
 *
 * 🚨 **Non c'e' nessun percorso che pubblichi senza passare dalla revisione.**
 * L'elenco porta a `ReviewPlanImport`, e la pubblicazione e' un pulsante di
 * quella pagina. E' voluto: un import che finisce in mano a un iscritto senza
 * che nessuno lo abbia guardato e' il modo piu' rapido per far allenare una
 * persona su qualcosa che il modello ha inventato.
 */
class PlanImportResource extends Resource
{
    protected static ?string $model = WorkoutPlanImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $modelLabel = 'import';

    protected static ?string $pluralModelLabel = 'import da PDF';

    protected static ?string $navigationLabel = 'Import da PDF';

    // 💡 F7: senza, le briciole dicevano «Import Da PDF» (maiuscole all'inglese).
    protected static ?string $breadcrumb = 'Schede da importare';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['member', 'uploader']);
    }

    /** Quante bozze aspettano una persona. */
    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', ImportStatus::Review->value)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * 🆕 **Anche una foto** — 23/09/2026, F7.
             *
             * 📌 *«visualizzatore pdf o immagine»*. 💡 Il modello sa già leggere
             * le foto (è così che importa l'app); mancava che il pannello le
             * accettasse. ⚠️ I tipi sono **gli stessi** del modello
             * (`WorkoutPlanImport::TIPI_ACCETTATI`): uno accettato qui e non là
             * fallirebbe nel job, dopo aver riservato i gettoni.
             */
            FileUpload::make('pdf')
                ->label('La scheda: un PDF o una foto')
                ->acceptedFileTypes(WorkoutPlanImport::TIPI_ACCETTATI)
                ->maxSize(20480)
                ->required()
                ->storeFiles(false)
                ->helperText('Massimo 20 MB. Una foto dritta e ben illuminata funziona quasi come un PDF digitale; una scansione storta molto peggio.')
                ->columnSpanFull(),

            /*
             * ⛔ **«Per quale iscritto» e' stato tolto** — 14/08/2026.
             *
             * 🚨 Era l'ultima porta rimasta aperta: caricare il PDF della scheda
             * di una persona e legarlo a lei **sul server**. Il file e' gia'
             * dato sanitario; legarlo a un nome lo rende identificabile.
             *
             * 💡 Da qui esce sempre un **modello**, che il trainer poi manda in
             * chat a chi vuole — e li' il legame lo vede solo lui.
             */
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),

                /*
                 * 🚨 **`$state`, non `$s`** — 23/09/2026, F7.
                 *
                 * ⛔ Filament inietta i parametri delle closure **per nome**
                 * (`$state`, `$record`, …) e, se il nome non lo conosce, prova
                 * a costruire il **tipo** dal container. Con `ImportStatus $s`
                 * provava a istanziare un enum: *«Target [ImportStatus] is not
                 * instantiable»*, e **l'elenco degli import dava 500** a
                 * chiunque lo aprisse. Idem `?float $s` sulla confidenza.
                 */
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (ImportStatus $state): string => $state->label())
                    ->color(fn (ImportStatus $state): string => $state->color()),

                /*
                 * ⛔ Via la colonna «Per» (l'iscritto): dopo R2 un import non è
                 * per nessuno, e la colonna diceva «Modello» su ogni riga. Al
                 * suo posto il nome della scheda letta, che è quello con cui
                 * la si riconosce.
                 */
                TextColumn::make('parsed_payload.name')
                    ->label('Scheda')
                    ->placeholder('—')
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state * 100, 0).'%')
                    // Sotto soglia il numero e' rosso: e' la riga da guardare
                    // per prima, non una curiosita' statistica.
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < (float) config('ai.pdf.escalation_confidence') => 'danger',
                        $state < 0.85 => 'warning',
                        default => 'success',
                    })
                    ->alignCenter(),

                TextColumn::make('model_used')
                    ->label('Letto da')
                    ->description(fn (WorkoutPlanImport $r): ?string => $r->escalated ? 'dopo escalation' : null)
                    ->toggleable(),

                TextColumn::make('uploader.name')->label('Caricato da')->toggleable(),

                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(ImportStatus::options()),
            ])
            ->recordActions([
                Action::make('rivedi')
                    ->label('Rivedi')
                    ->icon('heroicon-m-eye')
                    ->color('warning')
                    ->visible(fn (WorkoutPlanImport $r): bool => $r->status === ImportStatus::Review)
                    ->url(fn (WorkoutPlanImport $r): string => ReviewPlanImport::getUrl(['record' => $r])),

                // 🆕 F7 — una volta creata, la scheda si apre da qui nel builder.
                Action::make('apri')
                    ->label('Apri la scheda')
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (WorkoutPlanImport $r): bool => $r->workout_plan_id !== null)
                    ->url(fn (WorkoutPlanImport $r): string => EditWorkoutPlan::getUrl(['record' => $r->workout_plan_id])),

                Action::make('riprova')
                    ->label('Riprova')
                    ->icon('heroicon-m-arrow-path')
                    ->visible(fn (WorkoutPlanImport $r): bool => $r->status === ImportStatus::Failed)
                    ->action(function (WorkoutPlanImport $r): void {
                        $r->forceFill(['status' => ImportStatus::Queued, 'error' => null])->save();

                        ParseWorkoutPdf::dispatch($r->id);

                        Notification::make()->title('Rimesso in coda')->success()->send();
                    }),

                /*
                 * 🆕 🗑️ **Elimina** — A8, 22/09/2026.
                 *
                 * ⛔ Prima un import, e il documento caricato con lui, non si
                 * poteva togliere in nessun modo (DPIA R19). ⚠️ Si cancellano la
                 * riga e il documento (la media library lo toglie dal disco con
                 * la riga); la **scheda creata resta**: è della palestra, e i
                 * trainer potrebbero averla già mandata.
                 */
                DeleteAction::make()
                    ->label('Elimina')
                    ->modalHeading('Eliminare questo import?')
                    ->modalDescription('Spariscono il documento caricato e le righe lette. Se ne hai già creato una scheda, la scheda resta.')
                    ->successNotificationTitle('Import eliminato'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun import')
            ->emptyStateDescription('Carica il PDF di una scheda: verra\' letto e ti sara\' proposta una bozza da rivedere.');
    }

    /*
     * ⛔ **QUI C'ERA `iscritti()`: «A CHI SI PUÒ ASSEGNARE LA SCHEDA IMPORTATA».**
     * Tolto il 27/09/2026.
     *
     * 🚨 **Non lo chiamava più nessuno**, ed era un residuo pericoloso: il suo
     * nome e il suo commento descrivevano una cosa che **non esiste e non deve
     * esistere** — assegnare una scheda a una persona **dal server**.
     *
     * 📌 Il committente, 27/09, leggendomelo dire per l'ennesima volta: *«IL
     * TRAINER NON MANDA SCHEDE DAL SERVER»*.
     *
     * ⚖️ **Come funziona davvero**: il trainer scrive i **modelli** nel pannello
     * — esercizi, serie, ripetizioni, che non parlano di nessuno — e poi manda la
     * scheda **dalla chat, dall'app**, dentro una busta cifrata. 🔑 Le chiavi
     * stanno solo sui telefoni: il server trasporta e **non capisce**. ⛔ Quello che
     * non deve restare scritto da nessuna parte è il **legame fra una persona e un
     * programma**. Vedi `WorkoutPlanController::templates()`.
     *
     * ⚠️ **Perché questo commento resta al posto del metodo**: un residuo orfano
     * non fa danno finché nessuno lo legge — ma chi lo legge ricostruisce
     * un'architettura che non c'è, e ci ho creduto anch'io. 💡 Cancellarlo e
     * basta avrebbe lasciato il prossimo libero di riscriverlo.
     */

    public static function getPages(): array
    {
        return [
            'index' => ListPlanImports::route('/'),
            'review' => ReviewPlanImport::route('/{record}/review'),
        ];
    }
}
