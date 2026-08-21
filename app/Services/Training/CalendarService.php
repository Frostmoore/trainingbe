<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\FoodEntry;
use App\Models\User;
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

        /*
         * ⛔ **Le sedute e le bruciate non si leggono piu' qui** — FASE 11.6,
         * 21/08/2026.
         *
         * 📌 Il committente: *«Nessun allenamento deve risiedere sul server,
         * devono stare tutti nell'app»*.
         *
         * 🚨 Il calendario e' l'ultima schermata in cui cibo e allenamento
         * convivevano, e da qui in poi hanno **due case diverse**: le calorie
         * mangiate stanno ancora sul server, gli allenamenti no. ⚠️ L'app le
         * unisce a valle (`calendarProvider`), che e' l'unico posto dove le due
         * fonti possono incontrarsi senza che una menta per l'altra.
         */

        return [
            'date' => $giorno->etichetta,
            'title' => ucfirst($giorno->locale()->translatedFormat('l d F Y')),
            'kcal' => (int) round($voci->sum('kcal')),
            'entries' => $voci->map(fn (FoodEntry $v): array => $this->diario->voce($v))->all(),
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

        /*
         * ⛔ Come sopra: allenamenti e bruciate stanno sul telefono — FASE 11.6.
         * 🚨 Un `workouts` a zero per ogni cella sarebbe stato un mese vuoto e
         * credibile, senza nessun errore da nessuna parte.
         */

        $oggi = $utente->giornoDiOggi();
        $celle = [];

        foreach ($da->finoA($a) as $g) {
            $chiave = $g->etichetta;
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
