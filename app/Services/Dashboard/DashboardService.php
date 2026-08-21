<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Nutrition\DiaryService;
use App\Services\Training\WorkoutCalorieService;
use App\Support\Tempo\GiornoLocale;
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
         * ✅ **A3 — il confine del giorno e' quello di chi guarda.**
         *
         * `GiornoLocale` tiene insieme le due cose che prima erano confuse in un
         * `Carbon` solo: l'**etichetta** con cui la persona chiama il giorno, e
         * la **finestra di istanti** con cui si interroga il database. Da qui
         * scende in `DiaryService` e nei due `scopeOnDate`, che ricevono lo
         * stesso oggetto invece di ricalcolare il confine per conto proprio.
         *
         * 🚨 Era esattamente questo il motivo per cui non si poteva fare a
         * meta': cambiare solo questa riga avrebbe dato l'intestazione giusta
         * sopra i dati di ieri.
         */
        $oggi = $utente->giornoDiOggi($adesso);

        // L'ora locale: da qui in giu' «le 8 del mattino» sono le 8 di chi
        // guarda, non le 8 di Greenwich.
        $adessoLocale = $adesso->copy()->setTimezone($utente->fusoOrario());

        return [
            /*
             * ⚠️ **L'etichetta, e NON `$oggi->inizio()->toDateString()`.**
             *
             * E' la trappola trovata provando a fare questa migrazione:
             * `inizio()` restituisce l'**istante** della mezzanotte locale
             * riportato in UTC, che per Roma sono **le 22:00 del giorno prima**.
             * Chiedergli la data darebbe l'etichetta sbagliata — cioe' lo stesso
             * difetto, spostato di un metodo.
             */
            'date' => $oggi->etichetta,

            /*
             * 🚨 L'ORA, e non solo la data.
             *
             * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono due
             * situazioni opposte: la prima è una giornata che sta per andare
             * fuori controllo, la seconda è una giornata finita. Senza l'ora,
             * qualunque lettura dei totali — quella dell'app e quella dell'AI —
             * è cieca su questa differenza.
             *
             * ⚠️ **E dev'essere l'ora LOCALE.** Con `$adesso` in UTC, alle 8 del
             * mattino a Roma qui usciva `6`, e il consiglio dell'AI parlava di
             * una giornata appena cominciata a chi stava per pranzare. Era la
             * meta' meno visibile di A3: la data sbagliata si nota, un'ora
             * sbagliata di due sembra solo un consiglio poco azzeccato.
             */
            'now' => $adessoLocale->toIso8601String(),
            'hour' => (int) $adessoLocale->format('G'),
            'day_progress_pct' => $this->quantaGiornataEPassata($adessoLocale),

            'nutrition' => $this->nutrizione($utente, $oggi),
            /*
             * ⛔ **`training` non c'e' piu'** — FASE 11.6, 21/08/2026.
             *
             * 🚨 Era il riassunto degli allenamenti: ultimo, conteggio a 30
             * giorni, sedute recenti. Tutto da `workout_sessions`.
             *
             * ⚠️ L'app non lo legge gia' piu' da `v8.2.0`: la scheda
             * «Allenamento» si contraddiceva da sola proprio perche' meta'
             * veniva da qui e meta' dallo storico unificato (difetto O.D.8).
             */
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
     *
     * 🚨 **`$adesso` dev'essere gia' nel fuso di chi guarda** — A3. Le 6 e le 23
     * sono ore di una giornata vissuta, non istanti UTC: passando un `Carbon` in
     * UTC, a Roma d'estate la «giornata sveglia» comincerebbe alle 8.
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
    private function nutrizione(User $utente, GiornoLocale $oggi): array
    {
        $giornata = $this->diario->forDate($utente, $oggi);

        return [
            'totals' => $giornata['totals'] ?? null,
            'targets' => $giornata['targets'] ?? null,
            'entries_count' => collect($giornata['meals'] ?? [])
                ->sum(fn (array $m): int => count($m['entries'] ?? [])),
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
