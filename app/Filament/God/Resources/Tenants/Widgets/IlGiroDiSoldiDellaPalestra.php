<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Widgets;

use App\Models\AiUsageLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * 📊 **Quanto ci dà e quanto ci costa, mese per mese** — 22/09/2026, F3.5.
 *
 * 📌 Il committente: *«grafico revenue/spese della palestra»*.
 *
 * ══ 🚨 DUE SERIE, DUE VALUTE, E UNA DELLE DUE VA CONVERTITA ══════════════
 *
 * | | |
 * |---|---|
 * | **Ci ha dato** | euro, dal registro degli incassi: è un ricavo |
 * | **Ci è costata** | l'AI di quella palestra, che il listino del modello conta **in dollari** |
 *
 * ⛔ Metterle sullo stesso asse senza convertire vorrebbe dire sommare valute
 * diverse in un grafico che sembra tornare. 💡 Qui la spesa si porta in euro
 * con un cambio dichiarato (`services.cambio_usd_eur`), e il titolo lo dice:
 * un margine letto su due valute è un margine sbagliato, e non si vede.
 *
 * ⚠️ **Il registro parte dal 22/09/2026.** I mesi precedenti risultano a zero
 * sui ricavi e pieni sui costi: è vero, non è un difetto, ma chi guarda deve
 * saperlo — sta scritto nella descrizione del riquadro.
 */
class IlGiroDiSoldiDellaPalestra extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    /** ⚠️ Riempito dalla pagina: vedi `ViewTenant::getFooterWidgets()`. */
    public ?Tenant $record = null;

    /** Quanti mesi indietro. */
    private const MESI = 12;

    public function getHeading(): ?string
    {
        return 'Quanto ci dà, quanto ci costa (€ al mese)';
    }

    public function getDescription(): ?string
    {
        return '💶 Gli incassi partono dal 22/09/2026, da quando il registro esiste. '
            .'⚠️ Il costo dell\'AI è convertito in euro per poterli confrontare.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        if (! $this->record instanceof Tenant) {
            return ['datasets' => [], 'labels' => []];
        }

        $mesi = $this->mesi();

        $incassi = app(RegistroDeiPagamenti::class)->perMese($this->record, self::MESI);
        $costi = $this->costoAiPerMese();

        return [
            'datasets' => [
                [
                    'label' => 'Ci ha dato',
                    'data' => array_map(
                        static fn (string $m): float => round(($incassi[$m] ?? 0) / 100, 2),
                        $mesi,
                    ),
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'fill' => true,
                ],
                [
                    'label' => 'Ci è costata (AI)',
                    'data' => array_map(
                        fn (string $m): float => $this->inEuro($costi[$m] ?? 0),
                        $mesi,
                    ),
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.15)',
                    'fill' => true,
                ],
            ],
            'labels' => array_map(
                static fn (string $m): string => Carbon::parse($m.'-01')->locale('it')->isoFormat('MMM YY'),
                $mesi,
            ),
        ];
    }

    /**
     * I mesi da mostrare, dal più vecchio.
     *
     * 🚨 **Lo scheletro va costruito qui**, non dedotto dalle chiavi delle due
     * serie: ⛔ un mese senza incassi e senza chiamate sparirebbe, e le due
     * linee scorrerebbero su etichette diverse — cioè un grafico che mente
     * senza dare nessun errore.
     *
     * @return list<string>
     */
    private function mesi(): array
    {
        $mesi = [];
        $cursore = Carbon::now()->startOfMonth()->subMonths(self::MESI - 1);

        for ($i = 0; $i < self::MESI; $i++) {
            $mesi[] = $cursore->format('Y-m');
            $cursore = $cursore->addMonth();
        }

        return $mesi;
    }

    /**
     * Il costo dell'AI mese per mese, in **millicent di dollaro**.
     *
     * @return array<string, int>
     */
    private function costoAiPerMese(): array
    {
        $da = Carbon::now()->startOfMonth()->subMonths(self::MESI - 1);

        return app(TenantContext::class)->runWithoutTenant(
            fn (): array => AiUsageLog::query()
                ->withoutGlobalScopes([TenantScope::class])
                ->where('tenant_id', $this->record?->getKey())
                ->where('created_at', '>=', $da)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as mese, SUM(cost_millicents) as totale")
                ->groupBy('mese')
                ->pluck('totale', 'mese')
                ->map(static fn ($v): int => (int) $v)
                ->all(),
        );
    }

    /**
     * Da millicent di dollaro a euro.
     *
     * ⚠️ **Il cambio è una configurazione, non una costante scritta qui**: il
     * giorno che cambia si tocca un file solo, e chi legge il grafico sa che
     * è una conversione e non una misura.
     */
    private function inEuro(int $millicents): float
    {
        $dollari = AiUsageLog::millicentsToCurrency($millicents);

        return round($dollari * (float) config('services.cambio_usd_eur', 0.92), 2);
    }
}
