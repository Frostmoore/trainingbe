<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Tables;

use App\Models\User;
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

                /*
                 * ⛔ **Qui c'era «Ultimo allenamento», e non e' stata tolta per
                 * ripulire: e' stata tolta perche' NON DEVE ESSERCI** — FASE
                 * 11.6, 21/08/2026.
                 *
                 * 📌 Il committente, 16/08: *«Niente, nemmeno se si allena»*.
                 * Gli allenamenti stanno sul telefono di chi li fa, e il server
                 * non li vede piu' — non e' che non li mostriamo, non ce li ha.
                 *
                 * 🚨 E' la stessa scelta gia' fatta per il peso (D9-bis) e per
                 * il diario alimentare: la palestra sa **chi e' iscritto** e
                 * **cosa ha comprato**, non come vive.
                 */

                /*
                 * 🚨 Qui c'era la colonna «Peso», e non e' stata tolta per
                 * ripulire: e' stata tolta perche' **non deve esserci**.
                 *
                 * Decisione D9-bis: *«il trainer non ha bisogno di vedere peso,
                 * altezza ed eta': puo' chiederli»*. Da S5.4 il peso non sta
                 * nemmeno piu' sul server, quindi questa colonna non avrebbe
                 * potuto funzionare comunque — ma sarebbe stata rimossa lo
                 * stesso.
                 */

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
