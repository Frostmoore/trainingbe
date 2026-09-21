<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Widgets;

use App\Models\User;
use App\Services\Billing\AbbonamentiDegliIscritti;
use App\Services\Reporting\IContiDellaPalestra;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * 📊 **I due grafici dell'iscritto** — 23/09/2026, F5.7.
 *
 * 📌 Il committente, sulla pagina dell'iscritto: *«mi serve un grafico con gli
 * abbonamenti nel tempo»* e *«mi serve un grafico con la revenue da
 * quell'allievo nel tempo (stessi criteri del numero in pagina principale)»*.
 *
 * ⚠️ **«Stessi criteri»** vuol dire lo stesso conto, non uno simile: la curva
 * viene da `AbbonamentiDegliIscritti::revenuePerMese()`, e il numero
 * nell'elenco da `revenueCent()` della stessa classe. ⛔ Due conti diversi per
 * la stessa domanda, prima o poi, danno due numeri — e la palestra li vede uno
 * accanto all'altro.
 *
 * ⛔ **Niente consumi, niente allenamenti**: la palestra sa cosa ha venduto a
 * quella persona, non come vive.
 */
class LaStoriaDellIscritto extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    /** ⚠️ Riempito dalla pagina: `ViewRecord::getWidgetData()`. */
    public ?User $record = null;

    protected function getType(): string
    {
        return $this->cosa() === 'abbonamento' ? 'bar' : 'line';
    }

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'revenue' => 'Quanto ti ha reso (€)',
            'abbonamento' => 'Abbonato, mese per mese',
        ];
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->cosa() === 'abbonamento'
            ? 'Gli abbonamenti nel tempo'
            : 'Quanto ti ha reso, dall\'inizio (€)';
    }

    public function getDescription(): ?string
    {
        return $this->cosa() === 'abbonamento'
            ? '1 = quel mese aveva l\'abbonamento della palestra, 0 = glielo avevi tolto o non era ancora iscritto.'
            : '⚠️ Stima: (la tua quota − quanto ti costa un posto) × i mesi in cui era abbonato. '
              .'I pagamenti che ti fa non passano da noi.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        if (! $this->record instanceof User) {
            return ['datasets' => [], 'labels' => []];
        }

        $servizio = app(AbbonamentiDegliIscritti::class);
        $mesi = app(IContiDellaPalestra::class)->mesi();

        $etichette = array_map(
            static fn (string $m): string => Carbon::parse($m.'-01')->locale('it')->isoFormat('MMM YY'),
            $mesi,
        );

        if ($this->cosa() === 'abbonamento') {
            $coperto = $servizio->copertoPerMese($this->record);

            return [
                'datasets' => [[
                    'label' => 'Abbonato',
                    'data' => array_map(static fn (string $m): int => ($coperto[$m] ?? false) ? 1 : 0, $mesi),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                ]],
                'labels' => $etichette,
            ];
        }

        $revenue = $servizio->revenuePerMese($this->record);

        return [
            'datasets' => [[
                'label' => 'Ti ha reso',
                'data' => array_map(static fn (string $m): float => round(($revenue[$m] ?? 0) / 100, 2), $mesi),
                'borderColor' => 'rgba(16, 185, 129, 1)',
                'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                'fill' => true,
            ]],
            'labels' => $etichette,
        ];
    }

    private function cosa(): string
    {
        return (string) ($this->filter ?? 'revenue');
    }
}
