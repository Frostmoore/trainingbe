<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Enums\KcalSource;
use App\Models\DailyBurn;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Tempo\GiornoLocale;

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

    /*
     * 🚨 **Nessuna dipendenza**, dal 15/08/2026: qui dentro non si chiama piu'
     * niente che stia fuori. Era `AiManager`, e la sua sparizione e' la prova
     * piu' corta che il calcolo e' diventato quello che era sempre stato — una
     * moltiplicazione.
     */

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
    public function manualDaily(User $user, GiornoLocale $giorno): ?int
    {
        return DailyBurn::forDate($user, $giorno)?->kcal;
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
    public function dailyBurned(User $user, GiornoLocale $giorno, ?float $kg = null): DailyBurnResult
    {
        $manuale = $this->manualDaily($user, $giorno);

        $sessioni = WorkoutSession::query()
            ->forUser($user)
            ->onDate($giorno)
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
     * Calcola le calorie e le salva. **Senza AI** — 15/08/2026.
     *
     * ── 🚨 Perche' l'AI e' stata tolta da qui ─────────────────────────────
     *
     * *«Per stimare le calorie di un allenamento non serve l'AI, e' un calcolo
     * matematico quindi offloadiamolo al server. Sarebbe uno spreco perche' i
     * dati li abbiamo tutti.»* — committente, 15/08/2026. Aveva ragione, e i
     * numeri lo dicono meglio di quanto sembrasse:
     *
     * | | |
     * |---|---|
     * | La formula era gia' qui | girava **su ogni sessione**, prima di chiamare il modello |
     * | E non era rozza | `metOf()` legge il MET del **singolo esercizio**: 120 esercizi su 121 ce l'hanno, da 3.0 a 11.0 |
     * | Il peso c'e' | lo manda l'app nella richiesta (`$kgDaRichiesta`), senza che il server lo conservi |
     * | Costo misurato | **0,00077 USD** a sessione, e mai cacheato: il prompt e' sotto la soglia |
     *
     * 🚨 **E c'era di peggio di uno spreco.** Il controllo di plausibilita'
     * accettava la risposta del modello fino a **quattro volte** il valore della
     * formula: l'AI non stava rifinendo un calcolo: poteva sostituirlo con un
     * numero quattro volte piu' grande, e lo scrivevamo.
     *
     * 💡 Quindi togliere l'AI non toglie precisione — toglie **l'unica fonte di
     * incoerenza** da un numero che sappiamo gia' calcolare, e lo rende
     * riproducibile: due volte lo stesso allenamento, due volte lo stesso
     * numero.
     *
     * ⚠️ **Idempotente e rispettosa del manuale**, come prima: non tocca niente
     * se il valore l'ha scritto una persona.
     *
     * 📌 `$user` resta nella firma anche se non serve piu': lo passano tre
     * chiamanti, e toglierlo sarebbe una modifica piu' grande di quella che
     * questo metodo sta facendo. Vedi il debito in fondo all'atlante.
     */
    public function estimateAndStore(WorkoutSession $session, ?User $user = null, ?float $kgDaRichiesta = null): void
    {
        if ($session->hasManualKcal()) {
            return;
        }

        $kg = $kgDaRichiesta ?? $this->bodyweight($user ?? $session->user);

        /*
         * ⚠️ Niente `try`/`catch`: non c'e' piu' niente che possa fallire. Era
         * li' perche' il fornitore poteva non rispondere — un problema che una
         * moltiplicazione non ha.
         */
        $session->forceFill([
            'kcal_burned' => $this->formulaKcal($session, $kg),
            'kcal_source' => KcalSource::Formula,
        ])->save();
    }

    /**
     * Il MET medio della seduta, letto **esercizio per esercizio**.
     *
     * 🚨 **E' il motivo per cui l'AI qui non serviva.** Non e' un valore fisso:
     * ogni esercizio del catalogo ha il proprio MET — 120 su 121 ce l'hanno, da
     * 3.0 a 11.0 — quindi una seduta di squat e stacchi pesa gia' piu' di una di
     * bicipiti, senza chiedere niente a nessuno.
     *
     * ⚠️ `self::MET` e' il ripiego per una seduta di soli esercizi senza MET —
     * cioe' custom scritti dall'utente. Prudente di proposito: sovrastimare le
     * calorie bruciate porta la persona a mangiare di piu' credendo di essere in
     * deficit.
     */
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

}
