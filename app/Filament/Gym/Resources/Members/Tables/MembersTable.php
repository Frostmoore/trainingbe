<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Tables;

use App\Filament\Support\VoceDiScheda;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Layout\Stack;
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
            /*
             * ══ 🃏 SCHEDE, NON RIGHE — 09/09/2026 ════════════════════════
             *
             * 📌 *«fai tutto con cards. Stessi elementi»*. Il perché per esteso
             * sta in `UsersTable`.
             *
             * 💡 Qui il guadagno è particolare: chi lavora in palestra guarda
             * questo elenco **dal telefono**, in sala, mentre parla con
             * qualcuno. ⛔ Una riga con cinque colonne su uno schermo da cinque
             * pollici è la definizione di illeggibile.
             */
            /*
             * == LA SCHEDA NON E' TUTTA CLICCABILE -- 09/09/2026 ==
             *
             * Il committente: "la card non deve essere tutta cliccabile, solo
             * il tasto modifica".
             *
             * In una TABELLA cliccare una riga e' un gesto che uno fa apposta.
             * In una SCHEDA piena di targhette e pulsanti diventa una trappola:
             * si tocca per leggere, o si sbaglia mira su un'azione, e ci si
             * ritrova dentro un modulo di modifica senza averlo chiesto.
             *
             * `recordUrl(null)` toglie il collegamento dall'intera area:
             * l'unico modo di entrare in modifica resta il suo pulsante.
             */
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->label('Iscritto')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->description(fn (User $r): ?string => $r->email),

                    VoceDiScheda::make('Seguito da', TextColumn::make('trainers')
                        ->label('Seguito da')
                        ->badge()
                        ->getStateUsing(fn (User $r): array => $r->assignedTrainers()->pluck('name')->all())
                        ->placeholder('nessuno')
                        ->color('info')),

                    /*
                     * Qui c'era "Ultimo allenamento", e non e' stata tolta per
                     * ripulire: e' stata tolta perche' NON DEVE ESSERCI --
                     * FASE 11.6, 21/08/2026.
                     *
                     * Il committente, 16/08: "Niente, nemmeno se si allena".
                     * Gli allenamenti stanno sul telefono di chi li fa, e il
                     * server non li vede piu' -- non e' che non li mostriamo,
                     * non ce li ha.
                     *
                     * E' la stessa scelta gia' fatta per il peso (D9-bis) e per
                     * il diario alimentare: la palestra sa CHI E' ISCRITTO e
                     * COSA HA COMPRATO, non come vive.
                     *
                     * Per lo stesso motivo qui non c'e' la colonna "Peso":
                     * "il trainer non ha bisogno di vedere peso, altezza ed
                     * eta': puo' chiederli". Da S5.4 il peso non sta nemmeno
                     * piu' sul server.
                     */

                    VoceDiScheda::make('Attivo', TextColumn::make('is_active')
                        ->label('Attivo')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'attivo' : 'disattivato')
                        ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                        ->sortable()),

                    VoceDiScheda::make('Iscritto il', TextColumn::make('created_at')
                        ->label('Iscritto il')
                        ->date('d/m/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)),
                ])->space(3),
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
            /*
             * == LE AZIONI HANNO UN BORDO, E STANNO DUE PER RIGA ==
             *
             * Il committente: "i tasti azione mettine 2 per riga, altrimenti
             * non si capisce niente (mettili centrati e con un bordo)".
             *
             * Il bordo lo da' `->button()->outlined()` qui sotto, azione per
             * azione. La griglia a due colonne NON si puo' dire da qui:
             * Filament v5 non espone nessuna API per disporre le azioni, e la
             * regola sta in `resources/css/pannello-a-schede.css`.
             *
             * ATTENZIONE: se un giorno le azioni tornassero in fila, il primo
             * posto da guardare e' se il deploy ha eseguito `filament:assets` --
             * senza, quel foglio non esce e nulla lo segnala.
             */
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->outlined(),
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
