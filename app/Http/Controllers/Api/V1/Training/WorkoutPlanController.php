<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Enums\MuscleGroup;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkoutPlanRequest;
use App\Models\PlanExercise;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanDay;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Le schede dell'iscritto — B4.5, estese in C2.
 *
 * 🚨 **Due filtri, non uno.** Il global scope limita alla palestra; qui si
 * aggiunge `member_id = utente corrente`. Il primo da solo lascerebbe un
 * iscritto leggere la scheda di un suo compagno di palestra passando un id
 * qualsiasi — che e' l'esatto contrario di quello che ci si aspetta da un dato
 * personale.
 *
 * E si filtra su `published`: una bozza e' lavoro in corso del trainer, e finche'
 * non la pubblica non deve comparire nell'app.
 *
 * ── C2: l'iscritto si scrive le proprie schede (decisione D1) ──────────────
 *
 * Nella stessa tabella convivono due cose diverse: la scheda **prescritta dal
 * trainer**, che si esegue e non si tocca, e quella che **l'iscritto si e'
 * scritto**, che e' sua. Il discriminante e' `created_by` e sta in
 * `WorkoutPlanPolicy`; qui si usa `Gate`, non si riscrive la regola.
 */
class WorkoutPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $piani = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'data' => $piani->map(fn (WorkoutPlan $p): array => $this->riassunto($p))->all(),
        ]);
    }

    /**
     * I modelli della palestra — S7.
     *
     * ── 🚨 Serve perché l'assegnazione è uscita dal pannello ──────────────
     *
     * Il trainer manda una scheda **dalla chat, dall'app**: è l'unico posto in
     * cui esistono le chiavi per cifrarla. Ma i modelli li scrive nel pannello,
     * quindi l'app deve poterli **leggere** — e questo è l'endpoint che glieli
     * dà, per intero, pronti da serializzare dentro un messaggio.
     *
     * ⚠️ **Solo i modelli** (`member_id IS NULL`), e solo a chi allena. Un
     * iscritto che chiamasse questa rotta si vedrebbe l'intero patrimonio di
     * schede della palestra: è il lavoro del trainer, e non è materiale suo.
     *
     * 💡 I modelli **restano sul server in chiaro**, ed è deliberato (S7.3):
     * non parlano di nessuno — sono esercizi, serie e ripetizioni — e sono il
     * patrimonio della palestra. Quello che non deve restare scritto è il
     * **legame fra una persona e un programma**, e quello adesso viaggia solo
     * dentro una busta cifrata.
     */
    public function templates(Request $request): JsonResponse
    {
        $utente = $request->user();

        if (! $utente->isTrainer() && ! $utente->isGymAdmin()) {
            // 403 e non 404: qui non c'è nessun id da indovinare, quindi non
            // c'è nessun oracolo da proteggere. Dire la verità aiuta chi sta
            // scrivendo il client.
            return response()->json(['message' => __('Non sei un trainer.')], 403);
        }

        $modelli = WorkoutPlan::query()
            ->templates()
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $modelli->map(fn (WorkoutPlan $p): array => $this->dettaglio($p))->all(),
        ]);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        $piano = WorkoutPlan::query()
            ->forMember($request->user())
            ->published()
            ->with(['exercises.exercise', 'creator'])
            ->find($plan);

        if ($piano === null) {
            // 404 e non 403: distinguere «non esiste» da «non e' tua» direbbe a
            // chi prova gli id quali esistono.
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        return response()->json(['data' => $this->dettaglio($piano)]);
    }

    // ───────────────────────── C2: scrittura ─────────────────────────

    /**
     * Una scheda che l'iscritto scrive per sé.
     *
     * ⚠️ **Nasce gia' pubblicata.** Lo stato `draft` esiste perche' il trainer
     * possa lavorarci prima di consegnarla; una scheda che uno scrive per sé non
     * ha nessuno da cui essere rivista, e lasciarla in bozza la renderebbe
     * invisibile a `index()`, che filtra `published()`. Sarebbe un salvataggio
     * riuscito che produce una scheda sparita.
     */
    public function store(WorkoutPlanRequest $request): JsonResponse
    {
        $utente = $request->user();

        $piano = DB::transaction(function () use ($request, $utente): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $utente->tenant_id,
                'member_id' => $utente->getKey(),
                'created_by' => $utente->getKey(),
                'name' => $request->validated()['name'],
                'notes' => $request->validated()['notes'] ?? null,
                // D3 — il promemoria privato di chi scrive.
                'rif_allievo' => $request->validated()['rif_allievo'] ?? null,
                'status' => PlanStatus::Published,
                'source' => PlanSource::Manual,
                'published_at' => now(),
            ]);

            $this->scriviIlContenuto($piano, $request, $utente);

            return $piano;
        });

        return response()->json(
            ['data' => $this->dettaglio($piano->load(['exercises.exercise', 'creator']))],
            201,
        );
    }

    public function update(WorkoutPlanRequest $request, int $plan): JsonResponse
    {
        $utente = $request->user();
        $piano = WorkoutPlan::query()->find($plan);

        if ($piano === null || $utente->cannot('view', $piano)) {
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        // 403 e non 404: qui la scheda esiste **ed e' sua**, semplicemente non e'
        // sua da modificare. Dirle «non trovata» sarebbe una bugia su una cosa
        // che ha davanti agli occhi.
        if ($utente->cannot('update', $piano)) {
            return response()->json([
                'message' => __('Questa scheda l\'ha scritta il tuo trainer: puoi eseguirla, non modificarla.'),
                'code' => 'plan_not_editable',
            ], 403);
        }

        /*
         * ══ ⛔ IL 409 DI CONFLITTO NON C'E' PIU' — 3b-B.17.7, 25/08/2026 ════
         *
         * B.16.6 aveva aggiunto un protocollo: chi scriveva dichiarava con
         * `base_updated_at` su quale versione stava lavorando, e se nel
         * frattempo la scheda era cambiata prendeva un **409** invece di
         * sovrascriverla. Nasceva da un danno vero — il 24/08 un salvataggio a
         * fine allenamento aveva cancellato due esercizi.
         *
         * 🚨 **Quel danno non e' piu' possibile da qui**: il salvataggio a fine
         * allenamento non passa piu' dal server. Le schede dell'iscritto vivono
         * sul telefono (B.17), e questa rotta la usa solo il compositore del
         * trainer — che il campo non l'ha mai mandato.
         *
         * ⚠️ **Se ne va perche' era facoltativo e nessuno lo mandava**, non
         * perche' l'idea fosse sbagliata: un protocollo che nessuno parla non
         * protegge niente e va tenuto in piedi lo stesso. 💡 Il giorno in cui
         * due trainer scrivessero sullo stesso modello, si rifa' — la forma sta
         * nel commit `v11.6.0`.
         */
        DB::transaction(function () use ($request, $piano, $utente): void {
            $dati = [
                'name' => $request->validated()['name'],
                'notes' => $request->validated()['notes'] ?? null,
            ];

            // ⚠️ `rif_allievo` si tocca **solo se e' nella richiesta**: assente
            // vuol dire «non l'ho mandato», non «cancellalo». L'app vecchia non
            // lo manda affatto, e una rinomina non deve fargli perdere il
            // promemoria.
            if ($request->has('rif_allievo')) {
                $dati['rif_allievo'] = $request->validated()['rif_allievo'] ?? null;
            }

            $piano->update($dati);

            $this->scriviIlContenuto($piano, $request, $utente);
        });

        return response()->json(['data' => $this->dettaglio($piano->fresh()->load(['exercises.exercise', 'creator']))]);
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        $utente = $request->user();
        $piano = WorkoutPlan::query()->find($plan);

        if ($piano === null || $utente->cannot('view', $piano)) {
            return response()->json(['message' => __('Scheda non trovata.')], 404);
        }

        if ($utente->cannot('delete', $piano)) {
            /*
             * ⛔ **Un motivo solo, adesso.** Il messaggio si sdoppiava per
             * distinguere «non e' tua» da «ha allenamenti registrati», e la
             * seconda non esiste piu': dalla FASE 11.6.3 il server le sedute
             * non ce le ha, quindi l'unico motivo per negare e' la proprieta'.
             *
             * 💡 Meglio un messaggio solo e vero che due di cui uno non puo'
             * mai capitare.
             */
            return response()->json([
                'message' => __('Questa scheda non è tua da eliminare.'),
                'code' => 'plan_not_deletable',
            ], 403);
        }

        $piano->delete();

        return response()->json(null, 204);
    }

    /**
     * Riscrive le righe della scheda.
     *
     * ⚠️ **Cancella e riscrive.** È accettabile perché `plan_exercises` non ha
     * figli: lo storico (`session_sets`) punta a `exercises`, non a queste
     * righe. Se un giorno qualcosa vi puntasse, questo `delete()` porterebbe via
     * anche quello — scritto qui perché non lo si scopra a danno fatto.
     *
     * @param  list<array<string, mixed>>  $righe
     */
    /**
     * Scrive il contenuto della scheda, nella forma in cui e' arrivato — G5.2.
     *
     * ── ⚠️ Due forme, e nessuna delle due si puo' togliere ────────────────
     *
     * | Richiesta | Cosa fa |
     * |---|---|
     * | porta `days` | scrive l'albero: giorni, alternative, esercizi |
     * | porta `exercises` (piatto) | tutto nel giorno predefinito — 🚨 la forma dell'**app gia' installata** |
     * | non porta ne' l'uno ne' l'altro | **non tocca niente**: e' una rinomina |
     *
     * 🚨 La terza riga e' quella che si dimentica. `days` assente e `days`
     * vuoto sono cose diverse: la prima vuol dire «non l'ho mandato», la
     * seconda «la scheda non ha piu' giorni». Confonderle svuoterebbe una
     * scheda a ogni rinomina.
     */
    private function scriviIlContenuto(WorkoutPlan $piano, WorkoutPlanRequest $request, User $utente): void
    {
        $giorni = $request->giorni();

        if ($giorni !== null) {
            $this->sincronizzaGiorni($piano, $giorni, $utente);

            return;
        }

        if ($request->has('exercises')) {
            $this->sincronizzaRighe($piano, $request->righe(), $utente);
        }
    }

    /**
     * Riscrive l'albero: giorni, esercizi, alternative — G5.2 (D2).
     *
     * ── ⚠️ Cancella e riscrive, come `sincronizzaRighe()` ─────────────────
     *
     * Un piano si modifica **come un tutto**: si sposta un esercizio da un
     * giorno all'altro, si duplica una giornata, si riordina. Con endpoint
     * granulari ogni gesto dell'interfaccia diventa tre richieste, e un'app che
     * perde la rete a meta' lascia la scheda in uno stato che non e' ne' quello
     * di prima ne' quello di dopo.
     *
     * 🚨 **Le alternative si scrivono dopo i principali**, perche'
     * `alternativa_di_id` deve puntare a righe che esistono gia'. E' la stessa
     * ragione delle due passate di `assignTo()`.
     *
     * @param  list<array<string, mixed>>  $giorni
     */
    private function sincronizzaGiorni(WorkoutPlan $piano, array $giorni, User $utente): void
    {
        // ⚠️ `daysConAlternative()`: `days()` esclude le alternative, e
        // cancellare solo i principali lascerebbe in tabella giorni alternativi
        // orfani — che, senza il loro originale, tornerebbero a comparire come
        // giorni veri.
        $piano->daysConAlternative()->delete();

        $matcher = app(ExerciseMatcher::class);
        $posizione = 0;

        foreach ($giorni as $datiGiorno) {
            $giorno = $piano->days()->create([
                'position' => $posizione++,
                'name' => $datiGiorno['name'] ?? null,
                'notes' => $datiGiorno['notes'] ?? null,
            ]);

            $this->scriviGliEsercizi($piano, $giorno, $datiGiorno['exercises'] ?? [], $matcher, $utente);

            // Le alternative di giornata: un livello solo, con i loro esercizi.
            $p = 0;

            foreach ($datiGiorno['alternatives'] ?? [] as $alt) {
                $giornoAlt = $piano->daysConAlternative()->create([
                    'alternativa_di_id' => $giorno->getKey(),
                    'position' => $p++,
                    'name' => $alt['name'] ?? null,
                    'notes' => $alt['notes'] ?? null,
                ]);

                $this->scriviGliEsercizi($piano, $giornoAlt, $alt['exercises'] ?? [], $matcher, $utente);
            }
        }
    }

    /**
     * Gli esercizi di un giorno, con le loro alternative.
     *
     * @param  list<array<string, mixed>>  $righe
     */
    private function scriviGliEsercizi(
        WorkoutPlan $piano,
        WorkoutPlanDay $giorno,
        array $righe,
        ExerciseMatcher $matcher,
        User $utente,
    ): void {
        $posizione = 0;

        foreach ($righe as $riga) {
            $nome = trim((string) ($riga['name'] ?? ''));

            if ($nome === '') {
                continue;
            }

            $principale = $this->creaEsercizio($piano, $giorno, $riga, $matcher, $utente, $posizione++, null);

            $p = 0;

            foreach ($riga['alternatives'] ?? [] as $alt) {
                if (trim((string) ($alt['name'] ?? '')) === '') {
                    continue;
                }

                $this->creaEsercizio($piano, $giorno, $alt, $matcher, $utente, $p++, $principale->getKey());
            }
        }
    }

    /**
     * I numeri di una riga: le serie, e il riassunto vecchio — 3b-D.10.
     *
     * ══ 🚨 UN POSTO SOLO PER TUTTE E DUE LE STRADE DI SCRITTURA ═══════════
     *
     * ⛔ `sets`, `reps`, `rest_sec` e `target_weight` erano copiati **due
     * volte**: una per gli esercizi dentro i giorni e una per la lista piatta.
     * 🚨 Aggiungendo le serie sarebbero diventate due copie che divergono — e
     * la prima a divergere sarebbe stata quella meno usata, cioe' quella che
     * nessuno prova.
     *
     * ── 💡 E il riassunto si RICAVA dalle righe, quando ci sono ───────────
     *
     * Chi manda le serie non deve anche calcolare `sets` e `reps`: lo fa il
     * server, cosi' i due formati **non possono** contraddirsi. ⚠️ Chi non le
     * manda — l'app gia' installata, l'importatore di PDF — continua a mandare
     * i numeri vecchi, e si scrivono quelli.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private static function numeriDi(array $riga): array
    {
        $serie = $riga['serie'] ?? null;

        /*
         * 💡 **Il riassunto non si calcola qui.** Ci pensa `PlanExercise` a
         * ogni salvataggio (vedi `booted()`), cosi' vale anche per il pannello
         * del trainer e per l'importatore di PDF, che non passano di qua.
         *
         * ⚠️ I numeri vecchi si passano lo stesso: servono a chi le serie non
         * le manda — l'app gia' installata — e quando le manda vengono
         * sovrascritti da quelli veri.
         */
        return [
            'serie' => is_array($serie) && $serie !== [] ? $serie : null,
            'carico' => $riga['carico'] ?? 'peso',
            'sets' => $riga['sets'] ?? null,
            'reps' => $riga['reps'] ?? null,
            'rest_sec' => $riga['rest_sec'] ?? null,
            'target_weight' => $riga['target_weight'] ?? null,
        ];
    }

    /**
     * I muscoli dichiarati per una riga, pronti da passare al matcher.
     *
     * 🚨 Torna `null` per «non lo so», non `[]`: le due cose vogliono dire
     * l'opposto, e il matcher le distingue. ⚠️ `array_key_exists` e non
     * `isset`, perche' `isset` su un valore nullo e' falso — e una riga che
     * manda `secondary_muscles: []` sta dicendo **«nessuno»**, che e' una
     * risposta legittima e va conservata.
     *
     * @param  array<string, mixed>  $riga
     * @return array{primario: MuscleGroup|null, secondari: list<string>|null}
     */
    private static function muscoliDi(array $riga): array
    {
        $primario = $riga['muscle_group'] ?? null;

        return [
            'primario' => is_string($primario) ? MuscleGroup::tryFrom($primario) : null,
            'secondari' => array_key_exists('secondary_muscles', $riga) && is_array($riga['secondary_muscles'])
                ? array_values(array_map(strval(...), $riga['secondary_muscles']))
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $riga
     */
    private function creaEsercizio(
        WorkoutPlan $piano,
        WorkoutPlanDay $giorno,
        array $riga,
        ExerciseMatcher $matcher,
        User $utente,
        int $posizione,
        ?int $alternativaDi,
    ): PlanExercise {
        // 🚨 Il nome si riconcilia con la libreria, anche per le alternative:
        // «panca manubri» scritta come alternativa deve finire sullo stesso
        // esercizio di quando e' scritta come principale, o il progresso su
        // quel movimento risulta diviso in due.
        $esercizio = $matcher->match(
            (string) $riga['name'],
            $utente->tenant_id,
            $utente,
            // 🆕 3b-A.3.4 — quello che il compositore sa dei muscoli entra nel
            // catalogo insieme all'esercizio, invece di andare perso.
            ...self::muscoliDi($riga),
        );

        return $piano->exercisesConAlternative()->create([
            'workout_plan_day_id' => $giorno->getKey(),
            'alternativa_di_id' => $alternativaDi,
            'exercise_id' => $esercizio->getKey(),
            'position' => $posizione,
            'duration_sec' => $riga['duration_sec'] ?? null,
            'notes' => $riga['notes'] ?? null,
            ...self::numeriDi($riga),
        ]);
    }

    private function sincronizzaRighe(WorkoutPlan $piano, array $righe, User $utente): void
    {
        // ⚠️ `exercisesConAlternative()` e non `exercises()`: la seconda
        // esclude le alternative, quindi una `delete()` su di lei lascerebbe
        // in tabella le alternative degli esercizi appena cancellati —
        // orfane, con un `alternativa_di_id` che punta al nulla.
        $piano->exercisesConAlternative()->delete();

        // G4 — ogni esercizio appartiene a un giorno, e la colonna e' NOT NULL.
        $giorno = $piano->giornoPredefinito();

        $matcher = app(ExerciseMatcher::class);
        $posizione = 0;

        foreach ($righe as $riga) {
            // 🚨 Il nome si riconcilia con la libreria: se l'esercizio esiste
            // già — della palestra o della piattaforma — si riusa quello. Senza,
            // dopo un mese la libreria ha «Panca piana», «panca piana» e «Panca
            // Piana bilanciere», e il progresso su quell'esercizio risulta
            // diviso su tre righe diverse.
            $esercizio = $matcher->match(
                (string) $riga['name'],
                $utente->tenant_id,
                $utente,
                ...self::muscoliDi($riga),
            );

            $piano->exercises()->create([
                'workout_plan_day_id' => $giorno->getKey(),
                'exercise_id' => $esercizio->getKey(),
                'position' => $posizione++,
                'duration_sec' => $riga['duration_sec'] ?? null,
                'notes' => $riga['notes'] ?? null,
                ...self::numeriDi($riga),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function riassunto(WorkoutPlan $p): array
    {
        $utente = request()->user();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'notes' => $p->notes,
            'exercises_count' => $p->exercises->count(),

            // C23 — la copertina. In un elenco di sei «Full body A/B/C» e' la
            // sola cosa che le distingue a colpo d'occhio.
            'image_url' => $p->imageUrl(),
            'starts_at' => $p->starts_at?->toDateString(),
            'ends_at' => $p->ends_at?->toDateString(),
            'published_at' => $p->published_at?->toIso8601String(),

            /*
             * ══ 🚨 QUANDO E' CAMBIATA — 3b-B.16.5, 24/08/2026 ══════════════
             *
             * 📌 *«le schede sul server si sincronizzano sul telefono quando
             * apro l'app e per le modifiche vince sempre la piu' recente»*.
             *
             * 💡 E' l'unico dato che permette al telefono di sapere se la sua
             * copia e' ancora buona **senza riscaricare tutta la scheda**.
             *
             * ⚠️ Vale anche quando cambia solo una riga: `PlanExercise` e
             * `WorkoutPlanDay` hanno `$touches = ['plan']` apposta. Senza,
             * aggiungere un esercizio dal pannello non avrebbe toccato questo
             * campo, e il telefono avrebbe tenuto la versione vecchia
             * **credendola aggiornata**.
             */
            'updated_at' => $p->updated_at?->toIso8601String(),

            /*
             * 🚨 Chi puo' modificarla lo dice il server.
             *
             * Senza questo campo l'app dovrebbe dedurlo confrontando
             * `created_by` con l'utente, cioe' riscrivere in Dart una regola di
             * autorizzazione che vive in `WorkoutPlanPolicy`. Due copie della
             * stessa regola divergono sempre, e la copia che sbaglia e' quella
             * che mostra all'utente un pulsante «Modifica» che il server poi
             * rifiuta.
             */
            'editable' => $utente !== null && $utente->can('update', $p),

            // Chi l'ha scritta: serve all'app per dire «scheda del tuo trainer»
            // invece di un generico elenco senza contesto.
            'author' => $p->creator === null ? null : [
                'id' => $p->creator->id,
                'name' => $p->creator->name,
                'is_me' => $p->created_by === $utente?->getKey(),
            ],
        ];
    }

    /**
     * Il «Rif. Allievo», **solo a chi l'ha scritto** — R4, G7.
     *
     * 🚨 **La chiave sparisce, non arriva vuota.** Una chiave sempre presente e
     * a volte piena direbbe comunque *che un riferimento esiste*, e su un elenco
     * di modelli anonimi anche solo questo e' un'informazione sul lavoro di un
     * collega.
     *
     * ⚠️ Gemella di `NutritionPlanController::rifAllievo()`. Sono due perche' i
     * due controller non condividono niente, e la duplicazione e' **voluta**:
     * un metodo comune in una classe base renderebbe possibile toglierlo da un
     * lato solo senza che niente se ne accorga.
     *
     * @return array<string, mixed>
     */
    private function rifAllievo(WorkoutPlan $p, ?User $chiGuarda): array
    {
        return $chiGuarda !== null && $chiGuarda->getKey() === $p->created_by
            ? ['rif_allievo' => $p->rif_allievo]
            : [];
    }

    /**
     * Una scheda per intero.
     *
     * ── 🚨 `exercises` E `days`: due viste della stessa cosa, e servono entrambe ──
     *
     * `exercises` e' la lista **piatta** dei soli esercizi principali, ed e' la
     * forma che l'app gia' installata sa leggere: toglierla spegnerebbe la
     * scheda su ogni telefono non ancora aggiornato. ⚠️ E' la stessa ragione per
     * cui `WorkoutPlanRequest` continua ad accettare `exercises` piatto in
     * scrittura — le due compatibilita' vanno tenute **insieme**, o si finisce
     * con un'app che sa scrivere una forma e leggerne un'altra.
     *
     * `days` e' l'albero vero: giorni, alternative dei giorni, esercizi e loro
     * alternative. E' quello che serve al compositore (G7.2).
     *
     * 💡 **Senza `days` il compositore non poteva esistere.** La scrittura
     * annidata c'era da `G5.2`, ma questo metodo tornava solo la lista piatta:
     * riaprire una scheda a tre giorni ne avrebbe mostrato uno solo, e il primo
     * salvataggio avrebbe **cancellato gli altri due**. Un round-trip che perde
     * dati e' peggio di una funzione mancante, perche' distrugge invece di
     * rifiutarsi.
     *
     * @return array<string, mixed>
     */
    private function dettaglio(WorkoutPlan $p, ?User $chiGuarda = null): array
    {
        $giorni = $p->days()
            ->with(['exercisesConAlternative.exercise', 'alternative.exercisesConAlternative.exercise'])
            ->orderBy('position')
            ->get();

        return array_merge($this->riassunto($p), [
            ...$this->rifAllievo($p, $chiGuarda ?? request()->user()),

            // D15 — l'identita' stabile. 💡 E' cio' che permette al telefono di
            // chi riceve di riconoscere una **versione nuova** della stessa
            // scheda e sostituirla, invece di affiancarla.
            'origine_id' => $p->origine_id,

            'exercises' => $p->exercises->map(fn ($r): array => $this->esercizio($r))->all(),
            'days' => $giorni->map(fn (WorkoutPlanDay $g): array => $this->giorno($g))->values()->all(),
        ]);
    }

    /**
     * Un giorno, con i suoi esercizi e le sue alternative — D2.
     *
     * 💡 **Un solo livello di ricorsione, e non e' una svista**: un giorno
     * alternativo ha i propri esercizi, ma non ha a sua volta altri giorni
     * alternativi. Se li avesse, «quale giorno sto seguendo» smetterebbe di
     * avere una risposta unica.
     *
     * @return array<string, mixed>
     */
    private function giorno(WorkoutPlanDay $g): array
    {
        return [
            'id' => $g->getKey(),
            'name' => $g->name,
            'notes' => $g->notes,
            'position' => $g->position,

            // ⚠️ `exercisesConAlternative` e poi si separa **qui**: la relazione
            // `exercises()` esclude le alternative (G4), quindi caricando quella
            // le alternative non arriverebbero mai — e sparirebbero al primo
            // salvataggio fatto dal compositore.
            'exercises' => $g->exercisesConAlternative
                ->whereNull('alternativa_di_id')
                ->map(fn ($r): array => $this->esercizio($r, $g))
                ->values()
                ->all(),

            'alternatives' => $g->alternative->map(fn (WorkoutPlanDay $a): array => [
                'id' => $a->getKey(),
                'name' => $a->name,
                'notes' => $a->notes,
                'position' => $a->position,
                'exercises' => $a->exercisesConAlternative
                    ->whereNull('alternativa_di_id')
                    ->map(fn ($r): array => $this->esercizio($r, $a))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Un esercizio, ovunque compaia.
     *
     * 🚨 **Il `name` in cima e' quello dell'esercizio del catalogo**, ed e' il
     * campo che il compositore rimanda indietro in scrittura: `WorkoutPlanRequest`
     * vuole il **nome**, non l'id, perche' la riconciliazione la fa
     * `ExerciseMatcher`. Tornare solo l'id costringerebbe l'app a tenersi una
     * copia del catalogo per poter risalvare quello che ha appena letto.
     *
     * @return array<string, mixed>
     */
    private function esercizio(PlanExercise $r, ?WorkoutPlanDay $giorno = null): array
    {
        return [
            'id' => $r->id,
            'position' => $r->position,
            'name' => $r->exercise?->name,
            'exercise' => [
                'id' => $r->exercise?->id,
                'name' => $r->exercise?->name,
                'muscle_group' => $r->exercise?->muscle_group?->value,
                'equipment' => $r->exercise?->equipment,
                // C23 — la miniatura accanto all'esercizio, nella scheda e
                // nel player: durante l'allenamento un'immagine dice quale
                // movimento e' molto piu' in fretta di un nome.
                'image_url' => $r->exercise?->imageUrl(),

                /*
                 * 🆕 **Il MET viaggia con l'esercizio** — FASE 11.2, 21/08/2026.
                 *
                 * 🚨 Da quando gli allenamenti stanno sul telefono, il calcolo
                 * delle calorie (`MET x kg x ore`) gira **li'**. Ma il catalogo
                 * degli esercizi resta sul server — e' roba condivisa, non e' di
                 * nessuno (`plan_tutto_sul_telefono.md` §2.2) — quindi il MET
                 * deve arrivare insieme all'esercizio, o l'app dovrebbe chiedere
                 * il catalogo ogni volta che ricalcola.
                 *
                 * ⚠️ 🆕 **`null` per 175 esercizi su 314** — era 1 su 121 prima
                 * di 3b-L. Gli esercizi importati da workout-guide non portano
                 * con se' un MET, e non se ne inventa uno: l'app usa il ripiego
                 * generico, esattamente come faceva `metOf()` qui.
                 *
                 * 💡 L'eccezione sono gli **allungamenti**, che il MET ce
                 * l'hanno (2.3): li' il ripiego generico da 5.0 non sarebbe
                 * stato «impreciso», sarebbe stato **il doppio del vero**.
                 */
                'met' => $r->exercise?->met,
            ],
            'sets' => $r->sets,
            'reps' => $r->reps,
            'rest_sec' => $r->rest_sec,
            'duration_sec' => $r->duration_sec,
            'target_weight' => $r->target_weight,
            'notes' => $r->notes,
            'prescription' => $r->prescription(),

            /*
             * 🆕 3b-D.10 — le serie riga per riga. 🚨 **Sempre presenti**, anche
             * per una scheda scritta prima che la colonna esistesse: li'
             * `serieRighe()` le deriva. 💡 Cosi' l'app non ha un ramo «se e'
             * vecchia», che e' il ramo che non si prova mai.
             */
            'serie' => $r->serieRighe(),
            'carico' => $r->carico,

            // Le alternative arrivano solo quando l'esercizio sta dentro un
            // giorno: nella lista piatta non c'e' il giorno da cui prenderle.
            'alternatives' => $giorno === null
                ? []
                : $giorno->exercisesConAlternative
                    ->where('alternativa_di_id', $r->getKey())
                    ->map(fn ($a): array => $this->esercizio($a))
                    ->values()
                    ->all(),
        ];
    }
}
