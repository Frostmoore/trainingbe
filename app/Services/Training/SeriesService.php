<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Tempo\GiornoLocale;

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
        [$da, $a, $tutto] = $this->finestra($utente, $giorni, $offset);

        $assunte = $this->kcalAssunte($utente, $da, $a);
        $bruciate = $this->kcalBruciate($utente, $da, $a);

        $perMese = $tutto || $giorni > self::GIORNI_PRIMA_DI_AGGREGARE;

        return $perMese
            ? $this->perMese($da, $a, $assunte, $bruciate, $tutto)
            : $this->perGiorno($da, $a, $assunte, $bruciate);
    }

    // ───────────────────────── la finestra ─────────────────────────

    /**
     * 🚨 **La finestra parte da «oggi» di chi guarda** — A3. Con
     * `Carbon::today()` l'ultima barra del grafico era il giorno UTC: dopo le
     * 22:00 di Roma l'utente vedeva il grafico fermarsi a ieri, e le calorie
     * appena registrate non comparivano da nessuna parte.
     *
     * @return array{0: GiornoLocale, 1: GiornoLocale, 2: bool}
     */
    private function finestra(User $utente, int $giorni, int $offset): array
    {
        $oggi = $utente->giornoDiOggi();

        if ($giorni <= 0) {
            return [$oggi->menoAnni(self::ANNI_INDIETRO), $oggi, true];
        }

        // `offset` scorre di finestre INTERE: con 7 giorni, offset 1 è la
        // settimana prima, non «un giorno prima». Scorrere di un giorno alla
        // volta farebbe ballare le etichette a ogni tocco.
        $a = $oggi->menoGiorni($offset * $giorni);
        $da = $a->menoGiorni($giorni - 1);

        return [$da, $a, false];
    }

    // ───────────────────────── i dati grezzi ─────────────────────────

    /**
     * Calorie assunte per giorno, in **una** query.
     *
     * 🚨 **Il raggruppamento e' nel fuso di chi guarda, non in UTC** — A3, ed
     * e' il punto che l'analisi iniziale non aveva visto. `->toDateString()` su
     * `eaten_at` raggruppa per giorno **UTC**: una cena delle 00:30 di Roma
     * finiva nella barra del giorno prima. Il risultato era un grafico con una
     * serata a digiuno seguita da una colazione da 900 kcal — due barre
     * sbagliate al prezzo di una, e nessun segnale che qualcosa non tornasse.
     *
     * @return array<string, int>
     */
    private function kcalAssunte(User $utente, GiornoLocale $da, GiornoLocale $a): array
    {
        return FoodEntry::query()
            ->forUser($utente)
            ->whereBetween('eaten_at', $da->finestraFinoA($a))
            ->get(['eaten_at', 'kcal'])
            ->groupBy(fn (FoodEntry $v): string => GiornoLocale::etichettaDi($v->eaten_at, $da->fuso))
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
    private function kcalBruciate(User $utente, GiornoLocale $da, GiornoLocale $a): array
    {
        /*
         * ⚠️ `daily_burns.date` e' una colonna `date`, cioe' **gia' un'etichetta**:
         * qui si confrontano etichette con etichette, e non ci va la finestra.
         * E' la meta' di A3 che si sbaglia nel verso opposto.
         */
        $manuali = DailyBurn::query()
            ->forUser($utente)
            ->whereBetween('date', [$da->etichetta, $a->etichetta])
            ->get(['date', 'kcal'])
            ->mapWithKeys(fn (DailyBurn $d): array => [$d->date->toDateString() => (int) $d->kcal])
            ->all();

        $kg = $this->calorie->bodyweight($utente);

        $daSessioni = WorkoutSession::query()
            ->forUser($utente)
            ->whereBetween('started_at', $da->finestraFinoA($a))
            ->get()
            ->groupBy(fn (WorkoutSession $s): string => GiornoLocale::etichettaDi($s->started_at, $da->fuso))
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
    private function perGiorno(GiornoLocale $da, GiornoLocale $a, array $assunte, array $bruciate): array
    {
        $etichette = [];
        $giorni = [];
        $consumate = [];
        $spese = [];

        foreach ($da->finoA($a) as $g) {
            $etichette[] = $g->locale()->format('d/m');
            $giorni[] = $g->etichetta;
            $consumate[] = $assunte[$g->etichetta] ?? 0;
            $spese[] = $bruciate[$g->etichetta] ?? 0;
        }

        return [
            'metric' => 'calories',
            'labels' => $etichette,

            /*
             * 🚨 **Le date vere, accanto alle etichette** — 19/08/2026.
             *
             * `labels` e' `d/m`, cioe' testo da mostrare: non ci si puo'
             * ricostruire un giorno sopra. ⚠️ E all'app serve, perche' le
             * calorie bruciate misurate dall'orologio **stanno solo sul
             * telefono** e vanno unite a questa serie **per giorno**.
             *
             * 💡 Senza queste date l'app dovrebbe indovinare a quale giorno
             * corrisponde ogni colonna contando all'indietro da oggi — e
             * sbaglierebbe al primo scorrimento indietro nello storico.
             */
            'dates' => $giorni,
            'consumed' => $consumate,
            'burned' => $spese,
            'granularity' => 'day',
            'period' => $da->locale()->format('d/m').' – '.$a->locale()->format('d/m/Y'),
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
    private function perMese(GiornoLocale $da, GiornoLocale $a, array $assunte, array $bruciate, bool $tutto): array
    {
        $etichette = [];
        $giorni = [];
        $consumate = [];
        $spese = [];

        for ($mese = $da->inizioMese(); $mese->nonDopoDi($a); $mese = $mese->piuMesi(1)) {
            $inizio = $mese;
            // L'ultimo mese si ferma al giorno chiesto, non alla fine del mese:
            // altrimenti la media di agosto includerebbe giorni non ancora
            // vissuti, contati come zero.
            $fine = $mese->fineMese()->nonDopoDi($a) ? $mese->fineMese() : $a;

            $etichette[] = $mese->locale()->format('m/y');

            // 💡 Il primo giorno del mese: sull'aggregato mensile l'app non
            // fonde niente, ma la chiave resta leggibile invece che assente.
            $giorni[] = $inizio->etichetta;

            $c = [];
            $b = [];

            foreach ($inizio->finoA($fine) as $g) {
                if (($assunte[$g->etichetta] ?? 0) > 0) {
                    $c[] = $assunte[$g->etichetta];
                }

                if (($bruciate[$g->etichetta] ?? 0) > 0) {
                    $b[] = $bruciate[$g->etichetta];
                }
            }

            $consumate[] = $c === [] ? 0 : (int) round(array_sum($c) / count($c));
            $spese[] = $b === [] ? 0 : (int) round(array_sum($b) / count($b));
        }

        return [
            'metric' => 'calories',
            'labels' => $etichette,
            'dates' => $giorni,
            'consumed' => $consumate,
            'burned' => $spese,
            'granularity' => 'month',
            'period' => $tutto
                ? 'tutto lo storico (media al giorno, per mese)'
                : $da->locale()->format('m/y').' – '.$a->locale()->format('m/y').' (media al giorno)',
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
