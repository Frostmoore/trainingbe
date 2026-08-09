<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Enums\SleepStage;
use App\Models\HealthSample;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * L'ipnogramma e la valutazione della qualita' del sonno — B9.2.
 *
 * Le soglie sono quelle confermate nel piano e vengono dalla letteratura sul
 * sonno dell'adulto:
 *
 * | Indicatore   | ok      | attenzione |
 * |--------------|---------|------------|
 * | minuti dormiti | ≥ 420 | ≥ 360      |
 * | % profondo   | ≥ 15    | ≥ 10       |
 * | % REM        | ≥ 20    | ≥ 15       |
 * | minuti svegli| ≤ 30    | ≤ 60       |
 *
 * 🚨 **Il giudizio complessivo e' il PEGGIORE dei quattro, non la media.**
 * Una notte di otto ore con il dieci per cento di sonno profondo non e' «buona
 * in media»: e' una notte lunga e poco riposante, e mediarla la farebbe passare
 * per normale. In salute il valore che conta e' quello che sta peggio.
 *
 * ⚠️ **Non e' una diagnosi**, e i messaggi lo dicono: questo sistema non e' un
 * dispositivo medico e non deve suggerire che lo sia.
 */
class SleepAnalyzer
{
    private const SOGLIE = [
        'asleep_minutes' => ['ok' => 420, 'warn' => 360],
        'deep_pct' => ['ok' => 15.0, 'warn' => 10.0],
        'rem_pct' => ['ok' => 20.0, 'warn' => 15.0],
        'awake_minutes' => ['ok' => 30, 'warn' => 60],
    ];

    /**
     * Il riepilogo di una notte.
     *
     * @return array<string, mixed>|null `null` se non ci sono campioni
     */
    public function night(User $utente, Carbon $notte): ?array
    {
        $campioni = HealthSample::query()
            ->forUser($utente)
            ->forNight($notte)
            ->orderBy('started_at')
            ->get();

        if ($campioni->isEmpty()) {
            return null;
        }

        $minuti = $this->minutiPerFase($campioni);

        $dormito = $minuti[SleepStage::Light->value]
            + $minuti[SleepStage::Deep->value]
            + $minuti[SleepStage::Rem->value];

        $svegli = $minuti[SleepStage::Awake->value];

        $profondoPct = $dormito > 0 ? round($minuti[SleepStage::Deep->value] / $dormito * 100, 1) : 0.0;
        $remPct = $dormito > 0 ? round($minuti[SleepStage::Rem->value] / $dormito * 100, 1) : 0.0;

        $valutazioni = [
            'asleep_minutes' => $this->valuta($dormito, self::SOGLIE['asleep_minutes'], piuEMeglio: true),
            'deep_pct' => $this->valuta($profondoPct, self::SOGLIE['deep_pct'], piuEMeglio: true),
            'rem_pct' => $this->valuta($remPct, self::SOGLIE['rem_pct'], piuEMeglio: true),
            'awake_minutes' => $this->valuta($svegli, self::SOGLIE['awake_minutes'], piuEMeglio: false),
        ];

        return [
            'night' => $notte->toDateString(),
            'from' => $campioni->first()->started_at->toIso8601String(),
            'to' => $campioni->last()->ended_at->toIso8601String(),
            'asleep_minutes' => $dormito,
            'awake_minutes' => $svegli,
            'light_minutes' => $minuti[SleepStage::Light->value],
            'deep_minutes' => $minuti[SleepStage::Deep->value],
            'rem_minutes' => $minuti[SleepStage::Rem->value],
            'deep_pct' => $profondoPct,
            'rem_pct' => $remPct,
            'ratings' => $valutazioni,
            // Il peggiore, non la media.
            'overall' => $this->peggiore($valutazioni),
            'hypnogram' => $campioni->map(fn (HealthSample $c): array => [
                'from' => $c->started_at->toIso8601String(),
                'to' => $c->ended_at->toIso8601String(),
                'stage' => $c->stage->value,
                'label' => $c->stage->label(),
                'minutes' => $c->minutes(),
            ])->all(),
            'disclaimer' => 'Indicazioni orientative, non una valutazione medica.',
        ];
    }

    /**
     * @param  Collection<int, HealthSample>  $campioni
     * @return array<int, int>
     */
    private function minutiPerFase(Collection $campioni): array
    {
        $out = [];

        foreach (SleepStage::cases() as $fase) {
            $out[$fase->value] = 0;
        }

        foreach ($campioni as $c) {
            $out[$c->stage->value] += $c->minutes();
        }

        return $out;
    }

    /** @param array{ok: int|float, warn: int|float} $soglie */
    private function valuta(int|float $valore, array $soglie, bool $piuEMeglio): string
    {
        if ($piuEMeglio) {
            return match (true) {
                $valore >= $soglie['ok'] => 'ok',
                $valore >= $soglie['warn'] => 'warn',
                default => 'bad',
            };
        }

        return match (true) {
            $valore <= $soglie['ok'] => 'ok',
            $valore <= $soglie['warn'] => 'warn',
            default => 'bad',
        };
    }

    /** @param array<string, string> $valutazioni */
    private function peggiore(array $valutazioni): string
    {
        if (in_array('bad', $valutazioni, true)) {
            return 'bad';
        }

        return in_array('warn', $valutazioni, true) ? 'warn' : 'ok';
    }
}
