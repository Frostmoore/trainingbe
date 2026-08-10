<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\HealthMetric;
use App\Models\BodyMetric;
use App\Models\HealthReading;
use App\Models\HealthSample;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Health\SleepAnalyzer;
use App\Services\Nutrition\DiaryService;
use App\Services\Training\WorkoutCalorieService;
use Illuminate\Support\Carbon;

/**
 * Il riepilogo di oggi, in una richiesta sola — D4.
 *
 * 🚨 **Un endpoint e non sei.** La schermata principale mostra calorie,
 * allenamenti recenti, peso, sonno, HRV e battito: con sei chiamate separate ne
 * basta una lenta per far comparire la schermata a pezzi, e su rete mobile
 * capita sempre. Qui si paga una query in più e si mostra tutto insieme o
 * niente.
 *
 * ⚠️ **Ogni sezione può essere `null`, e il `null` è un'informazione.** Nessuna
 * di queste cose è garantita: l'orologio può non aver mai mandato niente, il
 * profilo può essere incompleto. Restituire zeri al posto dei dati assenti
 * farebbe disegnare un HRV di 0 ms, che l'app mostrerebbe come un valore
 * pessimo invece che come un dato mancante.
 */
class DashboardService
{
    /** Quanti allenamenti recenti mostrare: quelli che si ricordano. */
    private const ULTIMI_ALLENAMENTI = 5;

    public function __construct(
        private readonly DiaryService $diario,
        private readonly WorkoutCalorieService $calorie,
        private readonly SleepAnalyzer $analizzatoreSonno,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forToday(User $utente, ?Carbon $adesso = null): array
    {
        $adesso ??= Carbon::now();
        $oggi = $adesso->copy()->startOfDay();

        return [
            'date' => $oggi->toDateString(),

            /*
             * 🚨 L'ORA, e non solo la data.
             *
             * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono due
             * situazioni opposte: la prima è una giornata che sta per andare
             * fuori controllo, la seconda è una giornata finita. Senza l'ora,
             * qualunque lettura dei totali — quella dell'app e quella dell'AI —
             * è cieca su questa differenza.
             */
            'now' => $adesso->toIso8601String(),
            'hour' => (int) $adesso->format('G'),
            'day_progress_pct' => $this->quantaGiornataEPassata($adesso),

            'nutrition' => $this->nutrizione($utente, $oggi),
            'training' => $this->allenamento($utente, $oggi),
            'body' => $this->corpo($utente),
            'sleep' => $this->riposo($utente, $adesso),
            'vitals' => $this->parametri($utente),
        ];
    }

    /**
     * Quanto è passato della **giornata sveglia**, non delle 24 ore.
     *
     * ⚠️ Si conta dalle 6 alle 23: alle 8 del mattino è passato circa il 12%
     * della giornata in cui si mangia, non il 33% delle ore. Usare le 24 ore
     * farebbe sembrare «indietro» chiunque a metà mattina.
     */
    private function quantaGiornataEPassata(Carbon $adesso): int
    {
        $minuti = $adesso->hour * 60 + $adesso->minute;
        $inizio = 6 * 60;
        $fine = 23 * 60;

        if ($minuti <= $inizio) {
            return 0;
        }

        if ($minuti >= $fine) {
            return 100;
        }

        return (int) round(($minuti - $inizio) / ($fine - $inizio) * 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function nutrizione(User $utente, Carbon $oggi): array
    {
        $giornata = $this->diario->forDate($utente, $oggi);

        return [
            'totals' => $giornata['totals'] ?? null,
            'targets' => $giornata['targets'] ?? null,
            'burned' => $giornata['burned'] ?? null,
            'entries_count' => collect($giornata['meals'] ?? [])
                ->sum(fn (array $m): int => count($m['entries'] ?? [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allenamento(User $utente, Carbon $oggi): array
    {
        $recenti = WorkoutSession::query()
            ->forUser($utente)
            ->with('plan')
            ->withCount('sets')
            ->orderByDesc('started_at')
            ->limit(self::ULTIMI_ALLENAMENTI)
            ->get();

        $kg = $this->calorie->bodyweight($utente);

        return [
            'last_30_days' => WorkoutSession::query()
                ->forUser($utente)
                ->where('started_at', '>=', $oggi->copy()->subDays(30))
                ->count(),

            // Serve a dire «non ti alleni da 5 giorni», che è l'informazione
            // che fa tornare in palestra. Un elenco senza questo numero
            // costringe a fare il conto a mente.
            'days_since_last' => $recenti->isEmpty()
                ? null
                : (int) $recenti->first()->started_at->copy()->startOfDay()->diffInDays($oggi),

            'open_session_id' => $recenti->firstWhere('ended_at', null)?->id,

            'recent' => $recenti->map(fn (WorkoutSession $s): array => [
                'id' => $s->id,
                'name' => $s->plan?->name ?? 'Sessione libera',
                'started_at' => $s->started_at->toIso8601String(),
                'duration_minutes' => $s->durationMinutes(),
                'sets_count' => $s->sets_count,
                'kcal' => $s->ended_at === null ? null : $this->calorie->kcalOf($s, $kg),
                'is_open' => $s->ended_at === null,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function corpo(User $utente): array
    {
        $ultime = BodyMetric::query()
            ->forUser($utente)
            ->whereNotNull('weight_kg')
            ->orderByDesc('date')
            ->limit(2)
            ->get();

        $attuale = $ultime->first();
        $precedente = $ultime->skip(1)->first();

        return [
            'weight_kg' => $attuale === null ? null : (float) $attuale->weight_kg,
            'weight_at' => $attuale?->date->toDateString(),
            // La differenza dalla pesata precedente: il numero da solo non dice
            // se si sta andando nella direzione giusta.
            'weight_delta' => ($attuale === null || $precedente === null)
                ? null
                : round((float) $attuale->weight_kg - (float) $precedente->weight_kg, 1),
            'target_weight_kg' => $utente->profile?->target_weight_kg === null
                ? null
                : (float) $utente->profile->target_weight_kg,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function riposo(User $utente, Carbon $adesso): ?array
    {
        $notte = HealthSample::nightOf($adesso);
        $riepilogo = $this->analizzatoreSonno->night($utente, $notte);

        if ($riepilogo === null) {
            return null;
        }

        // Sulla dashboard serve il riassunto, non l'ipnogramma: quello sta nella
        // schermata del sonno. Mandarlo qui vorrebbe dire trasferire centinaia
        // di blocchi per disegnare una riga.
        return [
            'night' => $riepilogo['night'],
            'asleep_minutes' => $riepilogo['asleep_minutes'],
            'deep_pct' => $riepilogo['deep_pct'],
            'rem_pct' => $riepilogo['rem_pct'],
            'awake_minutes' => $riepilogo['awake_minutes'],
            'overall' => $riepilogo['overall'],
            'ratings' => $riepilogo['ratings'],
        ];
    }

    /**
     * HRV e battito, ciascuno con la propria media di riferimento.
     *
     * 🚨 **Il valore assoluto non si può interpretare.** Un HRV di 42 ms è
     * ottimo per qualcuno e allarmante per qualcun altro: conta lo scostamento
     * dalla media *di quella persona*. Mandare il numero senza la media
     * significa che l'app — o l'AI — finirà per giudicarlo su una scala che non
     * esiste.
     *
     * @return array<string, mixed>
     */
    private function parametri(User $utente): array
    {
        $out = [];

        foreach (HealthMetric::cases() as $metrica) {
            $lettura = HealthReading::latestWithBaseline($utente, $metrica);

            $out[$metrica->value] = $lettura === null ? null : array_merge($lettura, [
                'label' => $metrica->label(),
                'unit' => $metrica->unit(),
            ]);
        }

        // Dice all'app se mostrare la sezione o spiegare che l'orologio non ha
        // mai mandato niente: due schermate diverse, e senza questo campo
        // dovrebbe dedurlo da tre null.
        $out['has_any'] = collect($out)->contains(fn ($v): bool => $v !== null);

        return $out;
    }
}
