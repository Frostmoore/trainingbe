<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Nutrition\DiaryService;
use App\Services\Training\WorkoutCalorieService;
use Illuminate\Support\Carbon;

/**
 * Il riepilogo di oggi, in una richiesta sola — D4.
 *
 * 🚨 **Un endpoint e non quattro.** La schermata principale mostra calorie,
 * allenamenti recenti e peso: con chiamate separate ne basta una lenta per far
 * comparire la schermata a pezzi, e su rete mobile capita sempre. Qui si paga
 * una query in più e si mostra tutto insieme o niente.
 *
 * ⚠️ **Ogni sezione può essere `null`, e il `null` è un'informazione.** Nessuna
 * di queste cose è garantita: il profilo può essere incompleto, il peso può non
 * essere mai stato registrato. Restituire zeri al posto dei dati assenti
 * farebbe disegnare valori che l'app mostrerebbe come pessimi invece che come
 * mancanti.
 *
 * 🚨 **SONNO, HRV E BATTITO NON PASSANO PIÙ DA QUI — S1 di
 * `plan_security_and_retention.md`.**
 *
 * Fino a `v5.2.0` questo servizio restituiva anche `sleep` e `vitals`, letti da
 * `health_samples` e `health_readings`. Quelle tabelle **non esistono più**: i
 * dati del sensore restano sul telefono di chi li produce (decisione D9), e
 * l'app se li calcola in locale.
 *
 * ⚠️ **Non è una funzione persa, è una funzione traslocata.** Chi cerca il
 * riposo sulla dashboard lo trova nell'app, in `features/health/`. Chi volesse
 * rimetterlo qui deve prima rileggere §C11 di `todo-2026-08-11.md`: il motivo
 * per cui non c'è non è tecnico.
 */
class DashboardService
{
    /** Quanti allenamenti recenti mostrare: quelli che si ricordano. */
    private const ULTIMI_ALLENAMENTI = 5;

    public function __construct(
        private readonly DiaryService $diario,
        private readonly WorkoutCalorieService $calorie,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forToday(User $utente, ?Carbon $adesso = null): array
    {
        $adesso ??= Carbon::now();

        /*
         * ⏸️ **A3 — QUI VA IL CONFINE DEL GIORNO DI CHI GUARDA, e non c'e'
         * ancora.** Le fondamenta ci sono (`User::inizioDiOggi()`,
         * `User::dataDiOggi()`, colonna `users.timezone`), il resto no.
         *
         * 🚨 **Non basta sostituire questa riga**, ed e' il motivo per cui non
         * e' stato fatto a meta': `$oggi` finisce in `DiaryService::forDate()`,
         * che passa per `FoodEntry::scopeOnDate()`, che rifa'
         * `startOfDay()`/`endOfDay()` **in UTC**. Cambiare solo qui darebbe un
         * riepilogo che mostra il giorno giusto in intestazione e **quello
         * sbagliato nei dati** — cioe' peggio del difetto, perche' incoerente
         * invece che uniformemente sbagliato.
         *
         * ⚠️ La migrazione va fatta **tutta insieme**: `scopeOnDate`,
         * `DiaryService`, `SeriesService`, `CalendarService`, il raggruppamento
         * per settimana e l'hash della cache del consiglio. Con il test che
         * serve: un utente in `Europe/Rome` alle 00:30 deve vedere il giorno
         * nuovo — senza, **di giorno funziona comunque** e non ci si accorge di
         * niente.
         */
        $oggi = $adesso->copy()->startOfDay();

        return [
            /*
             * ⚠️ **Quando si fara' A3, qui va `$utente->dataDiOggi($adesso)` —
             * e NON `$oggi->toDateString()`.**
             *
             * E' la trappola trovata provando a farlo: `inizioDiOggi()`
             * restituisce l'**istante** della mezzanotte locale riportato in
             * UTC, che per Roma sono **le 22:00 del giorno prima**. Chiedergli
             * la data darebbe l'etichetta sbagliata — cioe' lo stesso difetto,
             * spostato di un metodo.
             *
             * 💡 Istante ed etichetta sono due cose diverse: il primo serve a
             * confrontare timestamp, la seconda a scriverci sopra «11 agosto».
             */
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

            /*
             * ⚠️ Qui c'erano 'sleep' e 'vitals'. Vedi il dartdoc della classe:
             * i dati del sensore non passano piu' dal server (S1).
             *
             * L'app regge l'assenza di entrambe le chiavi senza modifiche:
             * `DashboardSummary.fromJson` le legge gia' come opzionali e
             * `RecoveryCard` esce prima se non ci sono.
             */
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
     * Il corpo — ma il server ne sa **una cosa sola**.
     *
     * 🚨 **Peso e misure non stanno piu' qui** (S5.4, decisione D9-bis):
     * `body_metrics` non esiste, e l'app li legge dal proprio archivio locale.
     *
     * ⚠️ Resta il **peso obiettivo**, ed e' voluto: e' una **preferenza** che
     * la persona ha dichiarato nel profilo, non una misura del suo corpo. Vive
     * in `profiles` e ci resta.
     *
     * @return array<string, mixed>
     */
    private function corpo(User $utente): array
    {
        return [
            'target_weight_kg' => $utente->profile?->target_weight_kg === null
                ? null
                : (float) $utente->profile->target_weight_kg,
        ];
    }

    /*
     * ⚠️ Qui vivevano `riposo()` e `parametri()`. Cancellati in S1.4.
     *
     * La regola che portavano — **un valore assoluto di HRV non si puo'
     * interpretare, conta solo lo scostamento dalla media di quella persona** —
     * non e' stata buttata: e' stata riscritta identica in Dart, in
     * `lib/src/features/health/media_di_riferimento.dart` (fase S4.1).
     *
     * 🚨 Chi la reimplementasse qui riporterebbe sul server i dati che questo
     * piano esiste per toglierne.
     */
}
