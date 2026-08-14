<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports;

use App\Enums\ImportStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\PlanImports\Pages\ListPlanImports;
use App\Filament\Gym\Resources\PlanImports\Pages\ReviewPlanImport;
use App\Jobs\ParseWorkoutPdf;
use App\Models\User;
use App\Models\WorkoutPlanImport;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
            FileUpload::make('pdf')
                ->label('Il PDF della scheda')
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(20480)
                ->required()
                ->storeFiles(false)
                ->helperText('Massimo 20 MB. I PDF scansionati funzionano peggio: se possibile usare l\'originale digitale.')
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

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (ImportStatus $s): string => $s->label())
                    ->color(fn (ImportStatus $s): string => $s->color()),

                TextColumn::make('member.name')
                    ->label('Per')
                    ->placeholder('Modello')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->formatStateUsing(fn (?float $s): string => $s === null ? '—' : number_format($s * 100, 0).'%')
                    // Sotto soglia il numero e' rosso: e' la riga da guardare
                    // per prima, non una curiosita' statistica.
                    ->color(fn (?float $s): string => match (true) {
                        $s === null => 'gray',
                        $s < (float) config('ai.pdf.escalation_confidence') => 'danger',
                        $s < 0.85 => 'warning',
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

                Action::make('riprova')
                    ->label('Riprova')
                    ->icon('heroicon-m-arrow-path')
                    ->visible(fn (WorkoutPlanImport $r): bool => $r->status === ImportStatus::Failed)
                    ->action(function (WorkoutPlanImport $r): void {
                        $r->forceFill(['status' => ImportStatus::Queued, 'error' => null])->save();

                        ParseWorkoutPdf::dispatch($r->id);

                        Notification::make()->title('Rimesso in coda')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun import')
            ->emptyStateDescription('Carica il PDF di una scheda: verra\' letto e ti sara\' proposta una bozza da rivedere.');
    }

    /** @return array<int, string> */
    public static function iscritti(): array
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
            'index' => ListPlanImports::route('/'),
            'review' => ReviewPlanImport::route('/{record}/review'),
        ];
    }
}
