<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;

/**
 * Le serie temporali per i grafici — C3.
 *
 * Due metriche sole: **peso** e **calorie assunte contro bruciate**. Sono quelle
 * dell'app storica, e sono quelle su cui una persona prende decisioni.
 *
 * 🚨 **Perché un servizio e non due query nel controller.** Le regole qui sotto
 * sono facili da riscrivere leggermente diverse in un altro punto, e quando
 * dashboard e calendario mostrano due numeri diversi per lo stesso giorno la
 * fiducia nell'applicazione finisce. È già successo nell'app storica.
 */
class SeriesService
{
    /**
     * Oltre questa finestra si aggrega per mese.
     *
     * ⚠️ Non è un limite tecnico: 400 barre su uno schermo di telefono sono
     * larghe mezzo pixel e non si leggono. Sopra i tre mesi la domanda cambia da
     * «cosa ho fatto martedì» a «come sto andando», e la risposta giusta è una
     * media.
     */
    public const GIORNI_PRIMA_DI_AGGREGARE = 92;

    /** Il passato che «tutto lo storico» copre davvero. */
    private const ANNI_INDIETRO = 10;

    public function __construct(
        private readonly WorkoutCalorieService $calorie,
    ) {}

    /**
     * Il peso nel tempo: una serie di punti datati, non una griglia di giorni.
     *
     * ⚠️ Il peso si registra quando capita, non tutti i giorni. Riempire i buchi
     * con zeri disegnerebbe un grafico che precipita a zero ogni volta che uno
     * si dimentica di pesarsi; riempirli con l'ultimo valore noto disegnerebbe
     * un plateau che non è mai stato misurato. Si mostrano i punti che esistono.
     *
     * @param  int  $giorni  `0` = tutto lo storico
     * @return array<string, mixed>
     */
    /*
     * ⚠️ Qui viveva `weight(User $utente, int $giorni): array`. Cancellato in
     * S5.4: il peso non sta piu' sul server (decisione D9-bis), e la serie la
     * costruisce l'app dal proprio archivio locale
     * (`weightSeriesProvider` in `dashboard_controller.dart`).
     *
     * 🚨 `calories()` invece RESTA: le calorie del diario non sono un dato del
     * corpo. Due serie con la stessa forma e due sorgenti diverse, ed e' voluto.
     */

    public function calories(User $utente, int $giorni, int $offset = 0): array
    {
        [$da, $a, $tutto] = $this->finestra($giorni, $offset);

        $assunte = $this->kcalAssunte($utente, $da, $a);
        $bruciate = $this->kcalBruciate($utente, $da, $a);

        $perMese = $tutto || $giorni > self::GIORNI_PRIMA_DI_AGGREGARE;

        return $perMese
            ? $this->perMese($da, $a, $assunte, $bruciate, $tutto)
            : $this->perGiorno($da, $a, $assunte, $bruciate);
    }

    // ───────────────────────── la finestra ─────────────────────────

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function finestra(int $giorni, int $offset): array
    {
        if ($giorni <= 0) {
            return [Carbon::today()->subYears(self::ANNI_INDIETRO), Carbon::today(), true];
        }

        // `offset` scorre di finestre INTERE: con 7 giorni, offset 1 è la
        // settimana prima, non «un giorno prima». Scorrere di un giorno alla
        // volta farebbe ballare le etichette a ogni tocco.
        $a = Carbon::today()->subDays($offset * $giorni);
        $da = $a->copy()->subDays($giorni - 1);

        return [$da, $a, false];
    }

    // ───────────────────────── i dati grezzi ─────────────────────────

    /**
     * Calorie assunte per giorno, in **una** query.
     *
     * @return array<string, int>
     */
    private function kcalAssunte(User $utente, Carbon $da, Carbon $a): array
    {
        return FoodEntry::query()
            ->forUser($utente)
            ->whereBetween('eaten_at', [$da->copy()->startOfDay(), $a->copy()->endOfDay()])
            ->get(['eaten_at', 'kcal'])
            ->groupBy(fn (FoodEntry $v): string => $v->eaten_at->toDateString())
            ->map(fn ($gruppo): int => (int) round($gruppo->sum('kcal')))
            ->all();
    }

    /**
     * Calorie bruciate per giorno.
     *
     * 🚨 **Il valore manuale vince e NON si somma alle sessioni**: è una
     * dichiarazione complessiva («oggi ho bruciato 800»), non un contributo.
     * Sommarlo raddoppierebbe la giornata di chi corregge il numero dopo essersi
     * allenato. È la stessa regola di `WorkoutCalorieService::dailyBurned()`,
     * applicata qui in blocco perché chiamarla giorno per giorno vorrebbe dire
     * due query per ogni giorno — 730 su una finestra di un anno.
     *
     * @return array<string, int>
     */
    private function kcalBruciate(User $utente, Carbon $da, Carbon $a): array
    {
        $manuali = DailyBurn::query()
            ->forUser($utente)
            ->whereBetween('date', [$da->toDateString(), $a->toDateString()])
            ->get(['date', 'kcal'])
            ->mapWithKeys(fn (DailyBurn $d): array => [$d->date->toDateString() => (int) $d->kcal])
            ->all();

        $kg = $this->calorie->bodyweight($utente);

        $daSessioni = WorkoutSession::query()
            ->forUser($utente)
            ->whereBetween('started_at', [$da->copy()->startOfDay(), $a->copy()->endOfDay()])
            ->get()
            ->groupBy(fn (WorkoutSession $s): string => $s->started_at->toDateString())
            ->map(fn ($gruppo): int => (int) $gruppo->sum(
                fn (WorkoutSession $s): int => $this->calorie->kcalOf($s, $kg),
            ))
            ->all();

        return array_replace($daSessioni, $manuali);
    }

    // ───────────────────────── le due granularità ─────────────────────────

    /**
     * @param  array<string, int>  $assunte
     * @param  array<string, int>  $bruciate
     * @return array<string, mixed>
     */
    private function perGiorno(Carbon $da, Carbon $a, array $assunte, array $bruciate): array
    {
        $etichette = [];
        $consumate = [];
        $spese = [];

        for ($g = $da->copy(); $g <= $a; $g->addDay()) {
            $chiave = $g->toDateString();
            $etichette[] = $g->format('d/m');
            $consumate[] = $assunte[$chiave] ?? 0;
            $spese[] = $bruciate[$chiave] ?? 0;
        }

        return [
            'metric' => 'calories',
            'labels' => $etichette,
            'consumed' => $consumate,
            'burned' => $spese,
            'granularity' => 'day',
            'period' => $da->format('d/m').' – '.$a->format('d/m/Y'),
            'averages' => $this->medie($consumate, $spese),
        ];
    }

    /**
     * Aggregazione mensile come **media giornaliera**, non come somma.
     *
     * 🚨 Una somma mensile accanto a barre giornaliere sembra un'esplosione di
     * calorie, e confrontata con un target giornaliero non vuol dire niente.
     *
     * @param  array<string, int>  $assunte
     * @param  array<string, int>  $bruciate
     * @return array<string, mixed>
     */
    private function perMese(Carbon $da, Carbon $a, array $assunte, array $bruciate, bool $tutto): array
    {
        $etichette = [];
        $consumate = [];
        $spese = [];

        for ($mese = $da->copy()->startOfMonth(); $mese <= $a; $mese->addMonth()) {
            $inizio = $mese->copy()->startOfMonth();
            $fine = $mese->copy()->endOfMonth()->min($a);

            $etichette[] = $mese->format('m/y');

            $c = [];
            $b = [];

            for ($g = $inizio->copy(); $g <= $fine; $g->addDay()) {
                $chiave = $g->toDateString();

                if (($assunte[$chiave] ?? 0) > 0) {
                    $c[] = $assunte[$chiave];
                }

                if (($bruciate[$chiave] ?? 0) > 0) {
                    $b[] = $bruciate[$chiave];
                }
            }

            $consumate[] = $c === [] ? 0 : (int) round(array_sum($c) / count($c));
            $spese[] = $b === [] ? 0 : (int) round(array_sum($b) / count($b));
        }

        return [
            'metric' => 'calories',
            'labels' => $etichette,
            'consumed' => $consumate,
            'burned' => $spese,
            'granularity' => 'month',
            'period' => $tutto
                ? 'tutto lo storico (media al giorno, per mese)'
                : $da->format('m/y').' – '.$a->format('m/y').' (media al giorno)',
            'averages' => $this->medie($consumate, $spese),
        ];
    }

    /**
     * Le medie del periodo, **solo sui giorni con dati**.
     *
     * 🚨 Dividere per 7 quando si è registrato 3 giorni su 7 non dà «la media
     * della settimana»: dà un numero più basso, che fa credere di essere in
     * deficit. È l'errore che fa fallire un percorso senza che nessuno capisca
     * perché — la stessa ragione per cui il prompt delle calorie bruciate
     * raccomanda di restare prudenti.
     *
     * @param  list<int>  $consumate
     * @param  list<int>  $spese
     * @return array{consumed: int, burned: int, days_with_data: int}
     */
    private function medie(array $consumate, array $spese): array
    {
        $conDati = array_values(array_filter($consumate, static fn (int $v): bool => $v > 0));
        $bruciati = array_values(array_filter($spese, static fn (int $v): bool => $v > 0));

        return [
            'consumed' => $conDati === [] ? 0 : (int) round(array_sum($conDati) / count($conDati)),
            'burned' => $bruciati === [] ? 0 : (int) round(array_sum($bruciati) / count($bruciati)),
            'days_with_data' => count($conDati),
        ];
    }
}
