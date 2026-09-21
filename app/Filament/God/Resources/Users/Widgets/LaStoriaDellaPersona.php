<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Widgets;

use App\Models\User;
use App\Services\Reporting\LaStoriaDiUnaPersona;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 📊 **I tre grafici di una persona** — 22/09/2026, fase F4.3.
 *
 * 📌 Il committente: *«grafici: acquisti, gettoni spesi (granularità scelta),
 * chiamate automatiche consumate»*.
 *
 * ══ 🚨 PERCHÉ UN GRAFICO SOLO, E NON TRE ═════════════════════════════════
 *
 * Perché le tre domande si fanno **una alla volta**: «quanto ha speso», «quanti
 * gettoni brucia», «quanto la usa». ⛔ Tre riquadri affiancati costringono a
 * leggere tre assi diversi insieme — euro, gettoni e conteggi — e affiancare
 * scale diverse è il modo più rapido di vedere una correlazione che non c'è.
 *
 * ✅ Una tendina sceglie **cosa** guardare, un'altra **con che passo**. Il
 * grafico resta uno, e l'asse verticale significa sempre una cosa sola.
 *
 * ══ ⚠️ LA GRANULARITÀ NON È UN VEZZO ═════════════════════════════════════
 *
 * 📌 *«granularità scelta»*. 💡 A passo mensile un utente che compra due volte
 * l'anno è una riga piatta con due picchi; a passo giornaliero, la stessa
 * persona è rumore. ⛔ Fissarne una sola avrebbe reso il grafico inutile per
 * metà dei casi.
 *
 * ══ 🚨 I DATI LI CONTA UN SERVIZIO, NON QUESTA CLASSE ════════════════════
 *
 * `LaStoriaDiUnaPersona` fa i tre conti. ⛔ Scriverli qui vorrebbe dire
 * riscriverli il giorno che servono anche alla palestra (F5.7) — e due conti
 * per la stessa domanda, prima o poi, danno due numeri.
 */
class LaStoriaDellaPersona extends ChartWidget
{
    protected ?string $heading = 'Come si muove';

    protected int|string|array $columnSpan = 'full';

    /**
     * ⚠️ Popolato da `ViewUser::getFooterWidgetsData()`: un widget di pagina
     * riceve il record da lì, non dal costruttore.
     */
    public ?User $record = null;

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Le due tendine.
     *
     * 💡 `getFilters()` di Filament ne disegna **una**: per averne due si
     * incrociano qui i valori possibili. ⛔ Nove voci sono tante, ma sono
     * comunque meno delle tre tendine + tre grafici che l'alternativa
     * richiederebbe.
     *
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            'acquisti|mese' => 'Acquisti, per mese',
            'acquisti|settimana' => 'Acquisti, per settimana',
            'acquisti|giorno' => 'Acquisti, per giorno',
            'gettoni|mese' => 'Gettoni spesi, per mese',
            'gettoni|settimana' => 'Gettoni spesi, per settimana',
            'gettoni|giorno' => 'Gettoni spesi, per giorno',
            'chiamate|mese' => 'Chiamate automatiche, per mese',
            'chiamate|settimana' => 'Chiamate automatiche, per settimana',
            'chiamate|giorno' => 'Chiamate automatiche, per giorno',
        ];
    }

    public function getHeading(): string|Htmlable|null
    {
        return match ($this->cosa()) {
            'acquisti' => 'Quanto ha speso (€)',
            'gettoni' => 'Gettoni spesi',
            default => 'Chiamate automatiche',
        };
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        if (! $this->record instanceof User) {
            return ['datasets' => [], 'labels' => []];
        }

        $storia = app(LaStoriaDiUnaPersona::class);
        $passo = $this->passo();

        $serie = match ($this->cosa()) {
            'acquisti' => $storia->acquisti($this->record, $passo),
            'gettoni' => $storia->gettoniSpesi($this->record, $passo),
            default => $storia->chiamate($this->record, $passo),
        };

        /*
         * 💶 **Gli euro si dividono qui, non nel servizio**: il registro parla
         * in centesimi, ed è giusto che continui a farlo — è un grafico che si
         * adatta al dato, non un dato che si arrotonda per un grafico.
         */
        $valori = $this->cosa() === 'acquisti'
            ? array_map(static fn (int $c): float => round($c / 100, 2), array_values($serie))
            : array_values($serie);

        return [
            'datasets' => [[
                'label' => $this->getHeading(),
                'data' => $valori,
                'backgroundColor' => match ($this->cosa()) {
                    'acquisti' => 'rgba(16, 185, 129, 0.6)',
                    'gettoni' => 'rgba(245, 158, 11, 0.6)',
                    default => 'rgba(59, 130, 246, 0.6)',
                },
            ]],
            'labels' => array_map(
                fn (string $chiave): string => LaStoriaDiUnaPersona::etichetta($chiave, $passo),
                array_keys($serie),
            ),
        ];
    }

    private function cosa(): string
    {
        return explode('|', (string) ($this->filter ?? 'acquisti|mese'))[0];
    }

    private function passo(): string
    {
        $pezzi = explode('|', (string) ($this->filter ?? 'acquisti|mese'));

        return $pezzi[1] ?? 'mese';
    }
}
