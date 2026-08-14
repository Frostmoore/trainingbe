<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Tables;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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

                /*
                 * ⛔ **I filtri «modelli» e «assegnate» sono spariti** —
                 * 14/08/2026.
                 *
                 * 🚨 Dopo che la palestra non puo' piu' assegnare, tutto quello
                 * che si vede qui **e' un modello**: due filtri per separare una
                 * cosa da una che non esiste sono due modi di non trovare
                 * niente.
                 *
                 * ⚠️ Le schede con `member_id` valorizzato esistono ancora, ma
                 * sono quelle che **l'iscritto scrive per se'** dall'app, e
                 * questa tabella non le mostra.
                 */
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * ⛔ **L'azione «Assegna» e' stata tolta** — 14/08/2026, come
                 * sui piani alimentari.
                 *
                 * 🚨 Il ragionamento che stava scritto qui — «assegnare copia,
                 * non condivide, perche' altrimenti la prima personalizzazione
                 * cambierebbe la scheda di venti persone» — **era giusto e resta
                 * giusto**: e' il motivo per cui `assignTo()` duplica, e quel
                 * metodo non e' stato toccato.
                 *
                 * ⚠️ Quello che e' cambiato e' un livello sopra: non e' piu' la
                 * **palestra** a creare il legame fra una persona e un
                 * programma. Da un programma post-infortunio si capisce cos'e'
                 * successo a chi lo esegue, e quel dato sul server non lo
                 * teniamo.
                 *
                 * 🎯 La scheda si consegna via chat cifrata (D4).
                 */

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
