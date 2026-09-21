<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Widgets;

use App\Filament\God\Resources\Plans\Tables\PlansTable;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Support\Tenancy\TenantContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 👥 **Chi ce l'ha attivo** — F9.2, 23/09/2026.
 *
 * 📌 *«deve contenere una tabella con quali utenti ce l'hanno attivo, quando
 * scade, quanto costa e quante volte è stato rinnovato»*.
 *
 * | Colonna | Da dove |
 * |---|---|
 * | Chi | lo spazio (palestra o persona) e il suo titolare |
 * | Scade | `ends_at`; «non scade» per il consumo e gli accordi a mano; se si rinnova da solo |
 * | Costo | il prezzo del piano con la sua temporalità |
 * | Rinnovi | gli **incassi** del registro per questo piano e questo spazio, meno il primo pagamento |
 *
 * ⚠️ I rinnovi si contano dal registro degli incassi, che parte dal 22/09/2026
 * (F3): per chi pagava già prima il numero è più basso del vero, e la
 * descrizione lo dice.
 */
class ChiHaIlPiano extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    // Due query leggere: si disegna subito, senza aspettare di scorrere fin qui.
    protected static bool $isLazy = false;

    public ?Plan $record = null;

    public function table(Table $table): Table
    {
        $piano = $this->record;

        return $table
            ->heading('Chi ce l\'ha attivo')
            ->description('I rinnovi si contano dagli incassi registrati (dal 22/09/2026).')
            ->query(fn () => PlanSubscription::withoutGlobalScopes()
                ->where('plan_id', $piano?->getKey() ?? 0)
                ->attivi()
                ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes(), 'tenant.proprietario']))
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Chi')
                    ->placeholder('spazio non più presente')
                    ->description(fn (PlanSubscription $s): ?string => $s->tenant?->proprietario?->email),

                TextColumn::make('starts_at')->label('Dal')->date('d/m/Y')->sortable(),

                TextColumn::make('ends_at')
                    ->label('Scade')
                    ->state(fn (PlanSubscription $s): string => $s->ends_at === null
                        ? 'non scade'
                        : $s->ends_at->format('d/m/Y').($s->rinnova ? ' · si rinnova' : ' · non si rinnova')),

                TextColumn::make('costo')
                    ->label('Costo')
                    ->state(fn (): string => $piano instanceof Plan ? PlansTable::prezzo($piano) : '—'),

                TextColumn::make('rinnovi')
                    ->label('Rinnovi')
                    ->state(fn (PlanSubscription $s): int => $piano instanceof Plan ? self::rinnovi($s, $piano) : 0),

                TextColumn::make('pagato_con')
                    ->label('Pagato con')
                    ->state(fn (PlanSubscription $s): string => $s->stripe_subscription_id !== null || $s->stripe_customer_id !== null ? 'Stripe' : 'a mano')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Stripe' ? 'success' : 'warning'),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading('Nessuno ce l\'ha attivo');
    }

    /** Pagamenti registrati per questo piano e questo spazio, meno il primo. */
    private static function rinnovi(PlanSubscription $s, Plan $piano): int
    {
        $pagamenti = app(TenantContext::class)->runWithoutTenant(fn (): int => PagamentoIncassato::query()
            ->where('tenant_id', $s->tenant_id)
            ->where('tipo', $piano->aConsumo() ? PagamentoIncassato::CONSUMO : PagamentoIncassato::CANONE)
            ->when(! $piano->aConsumo(), fn ($q) => $q->where('cosa', $piano->code))
            ->where('pagato_il', '>=', $s->starts_at)
            ->count());

        return max(0, $pagamenti - 1);
    }
}
