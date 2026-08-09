<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Tables;

use App\Models\BodyMetric;
use App\Models\User;
use App\Models\WorkoutSession;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'elenco degli iscritti.
 *
 * Le colonne rispondono alle domande che si fa chi lavora in palestra: **chi non
 * viene piu'**, **chi non ha una scheda**, **chi sto seguendo io**. Un elenco di
 * nomi ed email non serve a niente che non si possa fare con un foglio di calcolo.
 */
class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Iscritto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $r): ?string => $r->email),

                TextColumn::make('trainers')
                    ->label('Seguito da')
                    ->badge()
                    ->getStateUsing(fn (User $r): array => $r->assignedTrainers()->pluck('name')->all())
                    ->placeholder('nessuno')
                    ->color('info'),

                TextColumn::make('ultimo_allenamento')
                    ->label('Ultimo allenamento')
                    ->getStateUsing(fn (User $r): ?string => WorkoutSession::query()
                        ->forUser($r)
                        ->latest('started_at')
                        ->value('started_at')?->diffForHumans())
                    ->placeholder('mai')
                    ->color(fn (?string $state): string => $state === null ? 'danger' : 'gray'),

                TextColumn::make('peso')
                    ->label('Peso')
                    ->getStateUsing(fn (User $r): ?string => ($p = BodyMetric::latestWeightFor($r)) !== null
                        ? number_format($p, 1, ',', '').' kg'
                        : null)
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Attivo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Iscritto il')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Attivo')
                    ->placeholder('Tutti')
                    ->trueLabel('Solo attivi')
                    ->falseLabel('Solo disattivati'),

                // 🚨 I due filtri che valgono davvero: sono le due domande che
                // una palestra si fa quando vuole non perdere un cliente.
                Filter::make('senza_scheda')
                    ->label('Senza scheda pubblicata')
                    ->query(fn (Builder $q): Builder => $q->whereDoesntHave('workoutPlans',
                        fn (Builder $p) => $p->where('status', 'published'))),

                Filter::make('inattivi')
                    ->label('Non si allena da 30 giorni')
                    ->query(fn (Builder $q): Builder => $q->whereDoesntHave('workoutSessions',
                        fn (Builder $s) => $s->where('started_at', '>=', now()->subDays(30)))),

                TrashedFilter::make()->label('Cancellati'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessun iscritto')
            ->emptyStateDescription('Gli iscritti si registrano dall\'app con il codice della palestra, oppure si creano da qui.');
    }
}
