<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Widgets;

use App\Models\Plan;
use App\Models\PlanSubscription;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * 📈 **Quanti ce l'hanno, mese per mese** — F9.2, 23/09/2026.
 *
 * 📌 Il committente, sulla pagina del piano: *«deve contenere un grafico di
 * quanti sono attivi»*.
 *
 * 💡 Per ogni mese degli ultimi dodici: gli abbonamenti a questo piano **vivi
 * all'ultimo giorno del mese** (iniziati prima, e non finiti prima). ⚠️ Stessa
 * idea di «attivo» di `PlanSubscription::attivi()`, applicata a una data
 * passata; le pause non si ricostruiscono all'indietro (non c'è la loro
 * storia), quindi un mese passato può contare chi era in pausa.
 */
class GliAttiviDelPiano extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    // Due query leggere: si disegna subito, senza aspettare di scorrere fin qui.
    protected static bool $isLazy = false;

    public ?Plan $record = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string|Htmlable|null
    {
        return 'Quanti ce l\'hanno attivo';
    }

    public function getDescription(): ?string
    {
        return 'Alla fine di ogni mese, negli ultimi dodici.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** Si contano persone: l'asse va a numeri interi, non «0,3 abbonati». */
    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    protected function getData(): array
    {
        if (! $this->record instanceof Plan) {
            return ['datasets' => [], 'labels' => []];
        }

        $etichette = [];
        $valori = [];

        for ($i = 11; $i >= 0; $i--) {
            $fine = Carbon::now()->startOfMonth()->subMonths($i)->endOfMonth();
            $fine = $fine->isFuture() ? Carbon::now() : $fine;

            $etichette[] = $fine->copy()->locale('it')->isoFormat('MMM YY');
            $valori[] = PlanSubscription::withoutGlobalScopes()
                ->where('plan_id', $this->record->getKey())
                ->where('starts_at', '<=', $fine)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $fine))
                ->count();
        }

        return [
            'datasets' => [[
                'label' => 'Attivi',
                'data' => $valori,
                'borderColor' => 'rgb(20, 184, 166)',
                'backgroundColor' => 'rgba(20, 184, 166, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $etichette,
        ];
    }
}
