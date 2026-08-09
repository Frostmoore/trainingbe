<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Tables;

use App\Enums\AuditAction;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * L'elenco delle schede della palestra.
 */
class WorkoutPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Scheda')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('member.name')
                    ->label('Assegnata a')
                    ->placeholder('Modello')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => $state->label())
                    ->color(fn (PlanStatus $state): string => $state->color()),

                TextColumn::make('exercises_count')
                    ->label('Esercizi')
                    ->counts('exercises')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Origine')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (PlanSource $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Modificata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(PlanStatus::options()),

                SelectFilter::make('source')
                    ->label('Origine')
                    ->options(fn (): array => collect(PlanSource::cases())
                        ->mapWithKeys(fn (PlanSource $s) => [$s->value => $s->label()])
                        ->all()),

                Filter::make('modelli')
                    ->label('Solo modelli')
                    ->query(fn (Builder $q): Builder => $q->whereNull('member_id')),

                Filter::make('assegnate')
                    ->label('Solo assegnate')
                    ->query(fn (Builder $q): Builder => $q->whereNotNull('member_id')),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * 🚨 Assegnare **copia**, non condivide.
                 *
                 * Se venti persone puntassero alla stessa riga, la prima
                 * personalizzazione — un peso diverso, un esercizio tolto per un
                 * infortunio — le cambierebbe tutte. Il modello e' un punto di
                 * partenza, non un contratto vincolante.
                 */
                Action::make('assegna')
                    ->label('Assegna')
                    ->icon('heroicon-m-user-plus')
                    ->color('success')
                    ->visible(fn (WorkoutPlan $r): bool => $r->isTemplate())
                    ->schema([
                        Select::make('member_id')
                            ->label('A chi')
                            ->options(fn (): array => static::iscrittiAssegnabili())
                            ->searchable()
                            ->required(),
                    ])
                    ->modalDescription('Ne verra\' creata una copia sua: le modifiche successive non toccheranno il modello.')
                    ->action(function (WorkoutPlan $r, array $data): void {
                        $membro = User::find($data['member_id']);

                        if ($membro === null) {
                            return;
                        }

                        $copia = $r->assignTo($membro, auth()->user());

                        Notification::make()
                            ->title("Copiata su {$membro->name}")
                            ->body('E\' una bozza: pubblicala quando e\' pronta.')
                            ->success()
                            ->send();

                        unset($copia);
                    }),

                Action::make('pubblica')
                    ->label(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published ? 'Archivia' : 'Pubblica')
                    ->icon(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published
                        ? 'heroicon-m-archive-box'
                        : 'heroicon-m-paper-airplane')
                    ->color(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published ? 'warning' : 'success')
                    // Un modello non si pubblica: non e' di nessuno.
                    ->visible(fn (WorkoutPlan $r): bool => ! $r->isTemplate())
                    ->requiresConfirmation()
                    ->modalDescription(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published
                        ? 'L\'iscritto non la vedra\' piu\' nell\'app. Gli allenamenti gia\' fatti restano.'
                        : 'Da questo momento l\'iscritto la vede nell\'app e comincia a seguirla.')
                    ->action(function (WorkoutPlan $r): void {
                        if ($r->status === PlanStatus::Published) {
                            $r->archive();

                            return;
                        }

                        $r->publish();

                        app(AuditLogger::class)->log(
                            AuditAction::WorkoutPlanPublished,
                            $r,
                            ['member_id' => $r->member_id, 'name' => $r->name],
                            tenant: $r->tenant_id,
                        );
                    }),

                ReplicateAction::make()
                    ->label('Duplica')
                    ->excludeAttributes(['published_at'])
                    ->beforeReplicaSaved(function (WorkoutPlan $replica): void {
                        $replica->name = $replica->name.' (copia)';
                        $replica->status = PlanStatus::Draft;
                    })
                    // Le righe non le duplica `replicate()`: vanno copiate a mano,
                    // altrimenti si ottiene una scheda vuota con lo stesso nome.
                    ->after(function (WorkoutPlan $replica, WorkoutPlan $record): void {
                        foreach ($record->exercises as $riga) {
                            $nuova = $riga->replicate();
                            $nuova->workout_plan_id = $replica->getKey();
                            $nuova->save();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Nessuna scheda')
            ->emptyStateDescription('Crea un modello riutilizzabile, oppure una scheda per un iscritto.');
    }

    /** @return array<int, string> */
    private static function iscrittiAssegnabili(): array
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
}
