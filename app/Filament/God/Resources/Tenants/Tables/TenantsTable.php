<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Tables;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Filament\Support\VoceDiScheda;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * L'elenco delle palestre.
 *
 * Le colonne rispondono alle domande che ci si fa davvero guardandolo: quante
 * persone ci sono dentro, l'abbonamento è attivo, e qual è il codice da dettare
 * al telefono a chi chiama.
 */
class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ══ 🃏 SCHEDE, NON RIGHE — 09/09/2026 ════════════════════════
             *
             * 📌 *«fai tutto con cards. Stessi elementi»*. Il perché per esteso
             * sta in `UsersTable`; qui vale la stessa cosa, e in più il colore
             * della palestra in una scheda **serve davvero**: in tabella era una
             * pallina in una colonna senza titolo, qui è il primo segno che
             * distingue una palestra dall'altra a colpo d'occhio.
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
                    /*
                     * Il colore accanto al nome, non su una riga sua: da solo
                     * sarebbe una pallina sospesa che non si capisce cosa
                     * qualifichi. Qui e' il titolo della scheda, e come il nome
                     * degli utenti non porta etichetta.
                     */
                    Split::make([
                        ColorColumn::make('color_primary')
                            ->label('')
                            ->tooltip('Colore principale')
                            ->grow(false),

                        TextColumn::make('name')
                            ->label('Palestra')
                            ->searchable()
                            ->sortable()
                            ->description(fn (Tenant $r): string => $r->slug)
                            ->weight('bold'),
                    ]),

                    VoceDiScheda::make('Codice', TextColumn::make('join_code')
                        ->label('Codice')
                        ->searchable()
                        ->copyable()
                        ->copyMessage('Codice copiato')
                        ->fontFamily('mono')),

                    VoceDiScheda::make('Stato', TextColumn::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                        ->color(fn (TenantStatus $state): string => $state->color())),

                    VoceDiScheda::make('Piano', TextColumn::make('plan')
                        ->label('Piano')
                        ->badge()
                        ->color('gray')),

                    // Conteggi via `withCount` nella query: senza, ogni scheda
                    // farebbe la sua query e l'elenco ne sparerebbe una per
                    // palestra.
                    VoceDiScheda::make('Utenti', TextColumn::make('users_count')
                        ->label('Utenti')
                        ->counts('users')
                        ->badge()
                        ->color('gray')
                        ->sortable()),

                    VoceDiScheda::make('Prova fino al', TextColumn::make('trial_ends_at')
                        ->label('Prova fino al')
                        ->dateTime('d/m/Y')
                        ->placeholder('nessuna')
                        // Rosso se il periodo di prova e' gia' scaduto: e' il caso
                        // in cui l'abbonamento sembra attivo ma nessuno puo'
                        // entrare.
                        ->color(fn (?Carbon $state): string => $state !== null && $state->isPast()
                            ? 'danger'
                            : 'gray')
                        ->sortable()),

                    VoceDiScheda::make('Creata', TextColumn::make('created_at')
                        ->label('Creata')
                        ->date('d/m/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)),
                ])->space(3),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(collect(TenantStatus::cases())
                        ->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->label()])),

                SelectFilter::make('plan')
                    ->label('Piano')
                    ->options(['starter' => 'Starter', 'pro' => 'Pro', 'enterprise' => 'Enterprise']),

                TrashedFilter::make()->label('Cancellate'),
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

                Action::make('sospendi')
                    ->button()
                    ->outlined()
                    ->label(fn (Tenant $r): string => $r->status === TenantStatus::Suspended ? 'Riattiva' : 'Sospendi')
                    ->icon(fn (Tenant $r): string => $r->status === TenantStatus::Suspended
                        ? 'heroicon-m-play'
                        : 'heroicon-m-pause')
                    ->color(fn (Tenant $r): string => $r->status === TenantStatus::Suspended ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Tenant $r): string => $r->status === TenantStatus::Suspended
                        ? 'Gli iscritti torneranno a entrare dall\'app e lo staff dal pannello.'
                        : 'Nessuno di questa palestra potrà più entrare, né dall\'app né dal pannello. '
                          .'I dati restano al loro posto.')
                    // Sospendere una palestra chiude fuori tutti i suoi utenti in
                    // un colpo solo: e' fra le azioni per cui, il giorno dopo,
                    // qualcuno chiedera' chi e' stato. Percio' finisce nel
                    // registro (B10.2).
                    ->action(function (Tenant $r): void {
                        $riattiva = $r->status === TenantStatus::Suspended;

                        $r->update([
                            'status' => $riattiva ? TenantStatus::Active : TenantStatus::Suspended,
                        ]);

                        app(AuditLogger::class)->log(
                            $riattiva ? AuditAction::TenantReactivated : AuditAction::TenantSuspended,
                            $r,
                            ['utenti_coinvolti' => $r->users()->count()],
                            tenant: $r,
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessuna palestra')
            ->emptyStateDescription('Creane una: le servirà il codice d\'invito per far entrare gli iscritti.');
    }
}
