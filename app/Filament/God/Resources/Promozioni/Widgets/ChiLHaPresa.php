<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Widgets;

use App\Enums\StatoAssegnazione;
use App\Filament\Support\VoceDiScheda;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Services\Promozioni\RevocaDellaPromozione;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 🧾 **Chi l'ha presa** — 24/09/2026, P7 di `plan_promozioni.md` (opzioni 40, 41).
 *
 * Le assegnazioni della promozione, **a schede** come il resto del pannello,
 * con «Revoca» su ognuna. La revoca vera sta in `RevocaDellaPromozione`.
 */
class ChiLHaPresa extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?Promozione $record = null;

    public function table(Table $table): Table
    {
        $promozione = $this->record;

        return $table
            ->heading('Chi l\'ha presa')
            ->query(fn () => PromozioneAssegnazione::query()
                ->where('promozione_id', $promozione?->getKey() ?? 0)
                ->with('utente'))
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('utente.name')
                        ->label('Chi')
                        ->weight('bold')
                        ->placeholder('persona non più presente')
                        ->description(fn (PromozioneAssegnazione $a): ?string => $a->utente?->email),

                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (StatoAssegnazione $state): string => $state->label()
                            .($state === StatoAssegnazione::Assegnata ? ' (in corso)' : ''))
                        ->color(fn (StatoAssegnazione $state): string => match ($state) {
                            StatoAssegnazione::Usata, StatoAssegnazione::Assegnata => 'success',
                            StatoAssegnazione::Revocata => 'danger',
                            default => 'gray',
                        })),

                    VoceDiScheda::make('Come', TextColumn::make('origine')
                        ->label('Come')
                        ->formatStateUsing(fn ($state): string => $state?->label() ?? '—')),

                    VoceDiScheda::make('Quando', TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')),

                    VoceDiScheda::make('Gettoni', TextColumn::make('gettoni')->label('Gettoni'))
                        ->visible(fn (?PromozioneAssegnazione $record): bool => ($record?->gettoni ?? 1) > 0),

                    VoceDiScheda::make('Scontato', TextColumn::make('scontato_cent')
                        ->label('Scontato')
                        ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2, ',', '.').' €'))
                        ->visible(fn (?PromozioneAssegnazione $record): bool => ($record?->scontato_cent ?? 1) > 0),

                    VoceDiScheda::make('Revocata il', TextColumn::make('revocata_il')->label('Revocata il')->dateTime('d/m/Y H:i'))
                        ->visible(fn (?PromozioneAssegnazione $record): bool => $record?->revocata_il !== null),
                ]),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(collect(StatoAssegnazione::cases())->mapWithKeys(fn (StatoAssegnazione $s) => [$s->value => $s->label()])),
            ])
            ->recordActions([
                Action::make('revoca')
                    // Come gli altri tasti delle schede: bordati, e la griglia di pannello-a-schede.css li allarga.
                    ->button()
                    ->outlined()
                    ->label('Revoca')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (PromozioneAssegnazione $a): bool => $a->stato->conta())
                    ->requiresConfirmation()
                    ->modalHeading('Revocare la promozione a questa persona?')
                    ->modalDescription('Toglie i gettoni regalati ancora nel saldo (fino a quelli dati: se ne ha spesi, possono andarsene anche gettoni suoi), '
                        .'chiude subito abbonamenti e upgrade regalati, toglie i posti. Gli sconti già pagati restano: un rimborso si fa da Stripe.')
                    ->modalSubmitActionLabel('Revoca')
                    ->action(function (PromozioneAssegnazione $a): void {
                        $tolti = app(RevocaDellaPromozione::class)->revoca($a, auth()->user());

                        Notification::make()
                            ->title('Revocata')
                            ->body($tolti > 0 ? "Tolti {$tolti} gettoni." : null)
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuno l\'ha ancora presa');
    }
}
