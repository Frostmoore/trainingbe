<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Tables;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
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
            ->columns([
                ColorColumn::make('color_primary')
                    ->label('')
                    ->tooltip('Colore principale'),

                TextColumn::make('name')
                    ->label('Palestra')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Tenant $r): string => $r->slug)
                    ->weight('bold'),

                TextColumn::make('join_code')
                    ->label('Codice')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Codice copiato')
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                    ->color(fn (TenantStatus $state): string => $state->color()),

                TextColumn::make('plan')
                    ->label('Piano')
                    ->badge()
                    ->color('gray'),

                // Conteggi via `withCount` nella query: senza, ogni riga
                // farebbe la sua query e l'elenco ne sparerebbe una per palestra.
                TextColumn::make('users_count')
                    ->label('Utenti')
                    ->counts('users')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('trial_ends_at')
                    ->label('Prova fino al')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    // Rosso se il periodo di prova è già scaduto: è il caso in
                    // cui l'abbonamento sembra attivo ma nessuno può entrare.
                    ->color(fn (?Carbon $state): string => $state !== null && $state->isPast()
                        ? 'danger'
                        : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creata')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->recordActions([
                EditAction::make(),

                Action::make('sospendi')
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
