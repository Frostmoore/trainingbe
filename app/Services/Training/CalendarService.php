<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Nutrition\DiaryService;
use App\Support\Tempo\GiornoLocale;

/**
 * Il calendario: il mese a colpo d'occhio e il dettaglio di un giorno — C4.
 *
 * È la vista che risponde a «come è andata questa settimana?» senza aprire
 * sette schermate. Nell'app storica è la pagina più usata dopo il diario.
 */
class CalendarService
{
    public function __construct(
        private readonly WorkoutCalorieService $calorie,
        private readonly DiaryService $diario,
    ) {}

    /**
     * Le celle di un mese, **dal lunedì della prima settimana alla domenica
     * dell'ultima**.
     *
     * 🚨 La griglia è di sette colonne e la prima è lunedì: se le celle
     * cominciassero dal primo del mese, un mese che comincia di mercoledì
     * mostrerebbe tutte le date sotto l'intestazione sbagliata. È un errore che
     * non dà nessun segnale — il calendario sembra a posto finché qualcuno non
     * confronta un giorno con la realtà.
     *
     * @return array<string, mixed>
     */
    public function month(User $utente, GiornoLocale $mese): array
    {
        $primo = $mese->inizioMese();
        $ultimo = $mese->fineMese();

        $da = $primo->inizioSettimana();
        $a = $ultimo->fineSettimana();

        return [
            'month' => $primo->locale()->format('Y-m'),
            'title' => ucfirst($primo->locale()->translatedFormat('F Y')),
            'prev' => $primo->piuMesi(-1)->locale()->format('Y-m'),
            'next' => $primo->piuMesi(1)->locale()->format('Y-m'),
            'target_kcal' => $this->targetDiOggi($utente),
            'days' => $this->celle($utente, $da, $a, $primo->locale()->month),
        ];
    }

    /**
     * Le celle di una settimana. Stessa forma del mese: l'app disegna due
     * layout, non due modelli di dati.
     *
     * @return array<string, mixed>
     */
    public function week(User $utente, GiornoLocale $giorno): array
    {
        $da = $giorno->inizioSettimana();
        $a = $giorno->fineSettimana();

        return [
            'week' => $da->etichetta,
            'title' => $da->locale()->translatedFormat('d M').' – '.$a->locale()->translatedFormat('d M Y'),
            'prev' => $da->menoGiorni(7)->etichetta,
            'next' => $da->piuGiorni(7)->etichetta,
            'target_kcal' => $this->targetDiOggi($utente),
            'days' => $this->celle($utente, $da, $a, null),
        ];
    }

    /**
     * Il dettaglio di un giorno: cosa ha mangiato e cosa ha fatto.
     *
     * @return array<string, mixed>
     */
    public function day(User $utente, GiornoLocale $giorno): array
    {
        $voci = FoodEntry::query()
            ->forUser($utente)
            ->onDate($giorno)
            ->orderBy('eaten_at')
            ->get();

        $sessioni = WorkoutSession::query()
            ->forUser($utente)
            ->onDate($giorno)
            ->with('plan')
            ->withCount('sets')
            ->orderBy('started_at')
            ->get();

        $bruciate = $this->calorie->dailyBurned($utente, $giorno);
        $kg = $this->calorie->bodyweight($utente);

        return [
            'date' => $giorno->etichetta,
            'title' => ucfirst($giorno->locale()->translatedFormat('l d F Y')),
            'kcal' => (int) round($voci->sum('kcal')),
            'burned' => $bruciate->toArray(),
            'entries' => $voci->map(fn (FoodEntry $v): array => $this->diario->voce($v))->all(),
            'sessions' => $sessioni->map(fn (WorkoutSession $s): array => [
                'id' => $s->id,
                'plan_name' => $s->plan?->name,
                'started_at' => $s->started_at->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'duration_min' => $s->ended_at === null
                    ? null
                    : max(1, (int) round($s->started_at->diffInMinutes($s->ended_at))),
                'sets_count' => $s->sets_count,
                'kcal' => $s->ended_at === null ? null : $this->calorie->kcalOf($s, $kg),
                'kcal_source' => $s->kcal_source?->value,
                'is_open' => $s->ended_at === null,
            ])->all(),
        ];
    }

    // ───────────────────────── il motore ─────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function celle(User $utente, GiornoLocale $da, GiornoLocale $a, ?int $meseCorrente): array
    {
        $fuso = $da->fuso;

        /*
         * 🚨 **I `groupBy` sono nel fuso di chi guarda** — A3.
         *
         * Prima raggruppavano su `->toDateString()` del timestamp, cioe' **in
         * UTC**: una cena delle 00:30 di Roma cadeva nella casella del giorno
         * prima. Sul calendario e' il difetto piu' visibile di tutti, perche' si
         * vede la casella sbagliata accanto a quella giusta.
         */
        $assunte = FoodEntry::query()
            ->forUser($utente)
            ->whereBetween('eaten_at', $da->finestraFinoA($a))
            ->get(['eaten_at', 'kcal'])
            ->groupBy(fn (FoodEntry $v): string => GiornoLocale::etichettaDi($v->eaten_at, $fuso))
            ->map(fn ($g): int => (int) round($g->sum('kcal')))
            ->all();

        $kg = $this->calorie->bodyweight($utente);

        $sessioni = WorkoutSession::query()
            ->forUser($utente)
            ->whereBetween('started_at', $da->finestraFinoA($a))
            ->get()
            ->groupBy(fn (WorkoutSession $s): string => GiornoLocale::etichettaDi($s->started_at, $fuso));

        // ⚠️ `daily_burns.date` e' gia' un'etichetta: qui niente finestra.
        $manuali = DailyBurn::query()
            ->forUser($utente)
            ->whereBetween('date', [$da->etichetta, $a->etichetta])
            ->get(['date', 'kcal'])
            ->mapWithKeys(fn (DailyBurn $d): array => [$d->date->toDateString() => (int) $d->kcal])
            ->all();

        $oggi = $utente->giornoDiOggi();
        $celle = [];

        foreach ($da->finoA($a) as $g) {
            $chiave = $g->etichetta;
            $delGiorno = $sessioni[$chiave] ?? collect();

            $celle[] = [
                'date' => $chiave,
                'day' => $g->locale()->day,
                'dow' => ucfirst($g->locale()->translatedFormat('D')),

                /*
                 * 🚨 `null` se non c'è NIENTE registrato, `0` se c'è qualcosa
                 * che vale zero calorie.
                 *
                 * Appiattirli su `0` renderebbe una giornata a digiuno
                 * indistinguibile da una non registrata, e l'app disegnerebbe
                 * una barra vuota per entrambe — dicendo a chi si è dimenticato
                 * di scrivere che ha mangiato zero.
                 */
                'kcal' => array_key_exists($chiave, $assunte) ? $assunte[$chiave] : null,

                'workouts' => $delGiorno->count(),
                'burned' => $manuali[$chiave] ?? (int) $delGiorno->sum(
                    fn (WorkoutSession $s): int => $this->calorie->kcalOf($s, $kg),
                ),
                'in_month' => $meseCorrente === null || $g->locale()->month === $meseCorrente,

                /*
                 * ⚠️ «Oggi» e' quello di chi guarda, non `Carbon::isToday()`
                 * — che confronta col giorno **UTC**. Dopo le 22:00 di Roma il
                 * pallino di oggi si spostava sulla casella di domani.
                 */
                'today' => $g->eUgualeA($oggi),
            ];
        }

        return $celle;
    }

    /**
     * Il target di riferimento per le barre.
     *
     * ⚠️ È quello **di oggi**, usato per tutto il mese: ricalcolarlo giorno per
     * giorno vorrebbe dire ricostruire peso e profilo com'erano allora, e non
     * conserviamo quello storico. Un unico riferimento dichiarato è più onesto
     * di trenta riferimenti plausibili.
     */
    private function targetDiOggi(User $utente): ?int
    {
        $t = $utente->profile?->computedTargets();

        return $t['kcal'] ?? null;
    }
}
