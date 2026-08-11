<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Enums\AiFeature;
use App\Enums\KcalSource;
use App\Models\DailyBurn;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\WorkoutAiContext;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Le calorie bruciate — B4.3.
 *
 * 🚨 **La regola che tiene in piedi tutto: il valore manuale batte sempre la
 * stima, e le aggregazioni passano da qui senza mai ricalcolare.**
 *
 * Nell'app storica dashboard, calendario e diario mostravano tre numeri diversi
 * per lo stesso allenamento, perche' ognuno faceva il proprio conto. Non era un
 * errore di calcolo: era l'assenza di un posto solo in cui il calcolo vivesse.
 * Questo e' quel posto. Chi ha bisogno di un numero chiama `kcalOf()` o
 * `dailyBurned()` — mai la formula direttamente.
 *
 * **Perche' la formula MET esiste comunque, visto che c'e' l'AI.** Perche' l'AI
 * puo' non rispondere: chiave scaduta, quota finita, fornitore giu'. In quei
 * casi l'utente deve vedere un numero ragionevole, non un trattino. La formula
 * e' la rete di sicurezza, e per questo `estimateAndStore()` non lascia mai
 * passare un'eccezione dell'AI verso il chiamante.
 */
class WorkoutCalorieService
{
    /**
     * Il MET di riferimento per un allenamento con i pesi.
     *
     * 5.0 e' il valore del Compendium of Physical Activities per «resistance
     * training, moderate effort». Prudente di proposito: sovrastimare le calorie
     * bruciate porta la persona a mangiare di piu' credendo di essere in
     * deficit, ed e' l'errore che fa fallire un percorso senza che nessuno
     * capisca perche'.
     */
    public const MET = 5.0;

    /** Il peso usato quando non se ne conosce nessuno. */
    public const FALLBACK_WEIGHT_KG = 75.0;

    /**
     * Il tetto oltre il quale una stima non e' credibile.
     *
     * 3.000 kcal e' piu' di quanto bruci una maratona: nessuna seduta in
     * palestra ci arriva, e un numero simile e' certamente un errore del
     * modello. Serve come rete anche quando non c'e' una formula con cui
     * confrontare.
     */
    public const MAX_PLAUSIBLE_KCAL = 3000;

    public function __construct(
        private readonly AiManager $ai,
    ) {}

    // ───────────────────────── ingredienti ─────────────────────────

    /**
     * Il peso da usare per la stima.
     *
     * 🚨 **Il server non sa piu' quanto pesi nessuno** — S5.4. `body_metrics`
     * non esiste piu': peso e misure restano sul telefono (decisione D9-bis).
     *
     * ⚠️ Restituisce sempre il **peso di ripiego**. Non e' una svista: chi
     * vuole una stima accurata deve **mandare il proprio peso nella
     * richiesta**, e l'app lo fa quando conclude un allenamento
     * (`POST /workout-sessions/{id}/finish`, campo `weight_kg`). Il valore
     * transita e non viene conservato.
     *
     * 💡 Sui totali giornalieri il ripiego basta: quelli sommano stime gia'
     * calcolate al momento giusto, non le rifanno.
     */
    public function bodyweight(User $user): float
    {
        return self::FALLBACK_WEIGHT_KG;
    }

    /** Il valore dichiarato dall'utente per quel giorno, se c'e'. */
    public function manualDaily(User $user, Carbon $date): ?int
    {
        return DailyBurn::forDate($user, $date)?->kcal;
    }

    // ───────────────────────── il calcolo ─────────────────────────

    /**
     * La formula: MET × peso × ore.
     *
     * Usa il MET dell'esercizio quando la libreria lo conosce, altrimenti quello
     * generico. Una sessione senza esercizi registrati vale comunque la durata:
     * chi si e' allenato senza segnare le serie ha comunque bruciato qualcosa.
     */
    public function formulaKcal(WorkoutSession $session, float $kg): int
    {
        $ore = $session->durationMinutes() / 60;

        if ($ore <= 0) {
            return 0;
        }

        return (int) round($this->metOf($session) * $kg * $ore);
    }

    /**
     * Il numero da mostrare per questa sessione.
     *
     * Ordine: cio' che e' salvato (qualunque sia la fonte) e, se non c'e'
     * niente, la formula. **Non chiama l'AI**: e' un metodo di lettura, e una
     * lettura che fa una chiamata a pagamento e' una trappola per chiunque la
     * usi dentro un ciclo.
     */
    public function kcalOf(WorkoutSession $session, ?float $kg = null): int
    {
        if ($session->kcal_burned !== null) {
            return $session->kcal_burned;
        }

        return $this->formulaKcal($session, $kg ?? $this->bodyweight($session->user));
    }

    /**
     * Le calorie bruciate in un giorno.
     *
     * Il valore manuale del giorno vince su tutto e **non si somma** alle
     * sessioni: e' una dichiarazione complessiva («oggi ho bruciato 800»), non
     * un contributo aggiuntivo. Sommarlo raddoppierebbe la giornata di chi
     * corregge il numero dopo essersi allenato.
     */
    public function dailyBurned(User $user, Carbon $date, ?float $kg = null): DailyBurnResult
    {
        $manuale = $this->manualDaily($user, $date);

        $sessioni = WorkoutSession::query()
            ->forUser($user)
            ->onDate($date)
            ->get();

        if ($manuale !== null) {
            return new DailyBurnResult($manuale, KcalSource::Manual, $sessioni->count());
        }

        if ($sessioni->isEmpty()) {
            return new DailyBurnResult(0, KcalSource::Formula, 0);
        }

        $kg ??= $this->bodyweight($user);
        $totale = 0;
        $daAi = false;

        foreach ($sessioni as $s) {
            $totale += $this->kcalOf($s, $kg);
            $daAi = $daAi || $s->kcal_source === KcalSource::Ai;
        }

        return new DailyBurnResult(
            $totale,
            $daAi ? KcalSource::Ai : KcalSource::Formula,
            $sessioni->count(),
        );
    }

    // ───────────────────────── la stima ─────────────────────────

    /**
     * Chiede all'AI una stima e la salva, con la formula come rete di sicurezza.
     *
     * 🚨 **Idempotente e rispettosa del manuale.** Non tocca niente se il valore
     * e' stato scritto da una persona; se e' gia' stata fatta una stima non la
     * rifa', perche' la chiamerebbe ogni volta che si riapre una sessione — e
     * ogni chiamata costa.
     *
     * **Non lascia uscire eccezioni.** Se l'AI non risponde si scrive il valore
     * della formula: l'utente ha appena finito di allenarsi e deve vedere un
     * numero, non un errore che riguarda un fornitore di cui non sa niente.
     */
    public function estimateAndStore(WorkoutSession $session, ?User $user = null, ?float $kgDaRichiesta = null): void
    {
        if ($session->hasManualKcal()) {
            return;
        }

        if ($session->kcal_burned !== null && $session->kcal_source === KcalSource::Ai) {
            return;
        }

        $user ??= $session->user;

        // ⚠️ Il peso mandato dall'app vince sul ripiego: e' l'unico modo che il
        // server ha di stimare bene, adesso che non conserva piu' i pesi.
        $kg = $kgDaRichiesta ?? $this->bodyweight($user);

        $formula = $this->formulaKcal($session, $kg);

        try {
            $stima = $this->ai->for(AiFeature::WorkoutKcal)->workoutCalories(
                $this->contextFor($session, $kg),
                AiCallContext::for($user, AiFeature::WorkoutKcal),
            );

            if ($stima > 0 && $this->plausibile($stima, $formula)) {
                $session->forceFill([
                    'kcal_burned' => $stima,
                    'kcal_source' => KcalSource::Ai,
                ])->save();

                return;
            }
        } catch (Throwable) {
            // Silenzio voluto: il provider ha gia' registrato l'errore nel
            // proprio log di consumo, e qui la cosa giusta e' andare avanti.
        }

        $session->forceFill([
            'kcal_burned' => $formula,
            'kcal_source' => KcalSource::Formula,
        ])->save();
    }

    /**
     * Sovrascrittura manuale.
     *
     * `null` rimette il valore in mano alla stima: e' il modo per disfare una
     * correzione senza dover indovinare il numero di prima.
     */
    public function setManual(WorkoutSession $session, ?int $kcal): void
    {
        if ($kcal === null) {
            $session->forceFill(['kcal_burned' => null, 'kcal_source' => null])->save();
            $this->estimateAndStore($session);

            return;
        }

        $session->forceFill([
            'kcal_burned' => max(0, $kcal),
            'kcal_source' => KcalSource::Manual,
        ])->save();
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Una stima assurda si scarta: un modello che risponde 12.000 kcal per
     * un'ora di pesi ha sbagliato, e scriverlo comunque manderebbe in negativo
     * il bilancio calorico della giornata.
     *
     * 🚨 **Due controlli e non uno, e il secondo va condizionato.** Il confronto
     * con la formula funziona solo se la formula ha prodotto qualcosa: su una
     * sessione senza durata misurabile — aperta e chiusa subito, o sincronizzata
     * male dall'app — la formula vale 0, e `stima < 0 × 4` scarterebbe **ogni**
     * stima. Il risultato sarebbe una sessione a zero calorie, che sembra un
     * errore dell'AI e invece e' il nostro controllo. Percio' quando non c'e'
     * niente con cui confrontare resta solo il tetto assoluto.
     */
    private function plausibile(int $stima, int $formula): bool
    {
        if ($stima > self::MAX_PLAUSIBLE_KCAL) {
            return false;
        }

        return $formula <= 0 || $stima < $formula * 4;
    }

    private function metOf(WorkoutSession $session): float
    {
        $met = $session->sets()
            ->with('exercise')
            ->get()
            ->map(fn ($s) => $s->exercise?->met)
            ->filter(fn (?float $m): bool => $m !== null && $m > 0);

        if ($met->isEmpty()) {
            return self::MET;
        }

        return (float) $met->avg();
    }

    private function contextFor(WorkoutSession $session, float $kg): WorkoutAiContext
    {
        $esercizi = [];

        foreach ($session->sets()->with('exercise')->get()->groupBy('exercise_id') as $serie) {
            $primo = $serie->first();

            $esercizi[] = [
                'name' => $primo->exercise?->name ?? 'Esercizio',
                'sets' => $serie->count(),
                'reps' => $primo->reps,
                'weight' => $primo->weight,
            ];
        }

        return new WorkoutAiContext(
            durationMinutes: $session->durationMinutes(),
            bodyweightKg: $kg,
            exercises: $esercizi,
            planName: $session->plan?->name,
        );
    }
}
