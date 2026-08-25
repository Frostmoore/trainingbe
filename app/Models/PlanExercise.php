<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PuoAvereAlternative;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga di una scheda: quale esercizio, quante serie, quante ripetizioni.
 *
 * 🚨 **Non ha `tenant_id`, ed e' una decisione con un prezzo.**
 *
 * La riga esiste solo dentro una scheda, che il tenant ce l'ha; duplicarlo qui
 * significherebbe tenere due copie allineate, e due copie che possono divergere
 * sono peggio di una sola. Ma questo vale **soltanto** finche' nessuno carica
 * queste righe per id diretto: `PlanExercise::find($id)` non e' filtrato da
 * nessuno scope, e da un id preso da una richiesta HTTP si arriverebbe alla
 * riga di un'altra palestra.
 *
 * **La regola, quindi, e': queste righe si raggiungono solo attraverso la
 * scheda** (`$plan->exercises`), mai per id. Dove serve un id — l'editor del
 * pannello, l'API — si carica prima la scheda (che e' filtrata) e si cerca
 * dentro. `TenantIsolationTest` non puo' accorgersi di una violazione qui,
 * quindi la protezione e' quella scritta sopra e i test di dominio (B4.6) che
 * la verificano.
 */
class PlanExercise extends Model
{
    use HasFactory;
    use PuoAvereAlternative;

    protected $fillable = [
        'workout_plan_id', 'workout_plan_day_id', 'alternativa_di_id',
        'exercise_id', 'position', 'sets', 'reps',
        'rest_sec', 'target_weight', 'duration_sec', 'notes',

        // 🆕 3b-D.10 — le serie riga per riga, e con che cosa si carica.
        'serie', 'carico',
    ];

    /**
     * ══ 🚨 TOCCARE LA SCHEDA QUANDO CAMBIA UNA SUA RIGA — 3b-B.16.5 ════════
     *
     * ══ 🚨 NATO PER LA SINCRONIZZAZIONE, RESTA PER IL PANNELLO ════════════
     *
     * 📌 Era per il telefono: *«le schede sul server si sincronizzano sul
     * telefono quando apro l'app e per le modifiche vince sempre la piu'
     * recente»*. ⛔ **Quella sincronizzazione non esiste piu'** (B.17): le
     * schede dell'iscritto vivono sul telefono e non si sincronizza niente.
     *
     * 💡 **E questa riga resta lo stesso**, perche' nel frattempo se n'e'
     * guadagnato un altro di motivo: `WorkoutPlansTable` nel pannello mostra la
     * colonna **«Modificato»** e ci ordina sopra.
     *
     * ⛔ Senza questo, `workout_plans.updated_at` cambia **solo** quando cambia
     * la riga della scheda — nome, note, stato. Un trainer che dal pannello
     * aggiunge un esercizio, cambia le serie o ne toglie uno **non la
     * toccherebbe affatto**, e la sua palestra vedrebbe scritto che quella
     * scheda non si tocca da marzo mentre l'ha modificata cinque minuti fa.
     *
     * 🚨 **Non si toglie «perche' era di B.16».** E' esattamente la mossa che
     * romperebbe il pannello in silenzio: nessun errore, una data sbagliata con
     * l'aria di essere giusta. I test stanno in
     * `tests/Feature/Training/QuandoLaSchedaECambiataTest.php`, e sono l'unica
     * cosa che se ne accorgerebbe.
     *
     * ⚠️ `$touches` copre `save()` e `delete()` sul modello. Le scritture in
     * blocco (`->delete()` su una query, `insert()`) **non** passano di qui: chi
     * le usa deve toccare la scheda a mano, ed e' scritto dove succede.
     *
     * @var list<string>
     */
    protected $touches = ['plan'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'sets' => 'integer',
            'rest_sec' => 'integer',
            'duration_sec' => 'integer',
            'target_weight' => 'float',
            'serie' => 'array',
        ];
    }

    /**
     * Il giorno a cui appartiene — G4.
     *
     * ⚠️ `workout_plan_id` resta e non e' ridondanza inutile: e' cio' che
     * permette di trovare tutti gli esercizi di una scheda con una query sola,
     * senza passare dai giorni. Le due colonne devono restare d'accordo, e a
     * tenerle d'accordo e' il controller che scrive.
     */
    /**
     * Il giorno si ricava dalla scheda quando chi scrive non lo dice — G4.
     *
     * ── 🚨 Perche' un hook e non «ricordarselo ogni volta» ────────────────
     *
     * `workout_plan_day_id` e' `NOT NULL` per una ragione forte: un esercizio
     * senza giorno non lo mostra nessuna schermata. Ma i punti che scrivono
     * esercizi sono sei — controller, import da PDF, comando di seed, copia dei
     * modelli, factory, fixture dei test — e **pretendere che tutti se lo
     * ricordino e' il modo di scoprire che uno non se l'e' ricordato**.
     *
     * 💡 Non e' magia che nasconde un errore: il giorno si ricava dalla
     * **scheda che la riga dichiara gia'**, quindi non puo' finire su un piano
     * sbagliato. E' la stessa forma di `BelongsToTenant`, che riempie
     * `tenant_id` dal contesto invece di chiederlo a ogni chiamante.
     *
     * ⚠️ Il vincolo `NOT NULL` **resta**, ed e' la garanzia vera: questo hook
     * fa in modo che non venga mai violato, non lo sostituisce.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $riga): void {
            /*
             * Verso 1: dal **piano** al giorno.
             *
             * Chi scrive conosce la scheda ma non i giorni — il controller
             * dell'API nella forma piatta, i seeder, le fixture.
             */
            if ($riga->workout_plan_day_id === null && $riga->workout_plan_id !== null) {
                $piano = WorkoutPlan::withoutGlobalScopes()->find($riga->workout_plan_id);

                $riga->workout_plan_day_id = $piano?->giornoPredefinito()?->getKey();

                return;
            }

            /*
             * 🚨 **Verso 2: dal giorno al piano — e questo mancava.**
             *
             * Un `Repeater` annidato di Filament scrive **solo** la chiave della
             * relazione da cui pende, cioe' `workout_plan_day_id`. Ma
             * `workout_plan_id` e' `NOT NULL`, quindi salvare una scheda dal
             * pannello dava un **500**:
             *
             *     Field 'workout_plan_id' doesn't have a default value
             *
             * ⚠️ Il primo hook guardava solo il verso opposto perche' l'unico
             * scrittore conosciuto era il controller dell'API, che il piano ce
             * l'ha. Il pannello no — e la pagina si **apriva** benissimo, il che
             * ha fatto sembrare che funzionasse.
             *
             * 💡 Le due colonne devono restare d'accordo, e adesso a tenerle
             * d'accordo e' il modello: chiunque scriva, da qualunque lato.
             */
            if ($riga->workout_plan_id === null && $riga->workout_plan_day_id !== null) {
                $giorno = WorkoutPlanDay::query()->find($riga->workout_plan_day_id);

                $riga->workout_plan_id = $giorno?->workout_plan_id;
            }
        });

        /*
         * ══ 🚨 IL RIASSUNTO VECCHIO SI RICALCOLA DALLE RIGHE — 3b-D.10 ═════
         *
         * ⛔ Farlo nel controller dell'API avrebbe coperto **una** delle strade
         * che scrivono queste righe. Ci scrivono anche il pannello del trainer
         * (Filament, con Eloquent diretto), l'importatore di PDF, i seeder, e
         * chiunque apra tinker — ed e' lo stesso identico motivo per cui i due
         * ganci qui sopra stanno nel modello e non in chi chiama.
         *
         * 🚨 Ognuna di quelle avrebbe potuto lasciare `sets: 3` accanto a
         * cinque righe di serie: **due formati che si contraddicono nella
         * stessa riga**, senza un errore da nessuna parte. Il pannello direbbe
         * una cosa e l'app un'altra, e chi guarda non ha modo di sapere quale
         * delle due mente.
         *
         * 💡 Cosi' invece non e' possibile: chi scrive le righe non deve
         * ricordarsi del riassunto, e chi se lo ricorda scrive numeri che
         * vengono sovrascritti da quelli veri.
         */
        static::saving(static function (self $riga): void {
            $serie = $riga->serie;

            /*
             * ⚠️ **Il pannello manda `[]` e `null`, non «niente».** Un
             * `Repeater` di Filament che nessuno ha aperto scrive un array
             * vuoto, e una `Select` non toccata scrive `null` — su una colonna
             * `NOT NULL DEFAULT 'peso'`, che il default lo applica solo quando
             * la colonna **non compare** nella `INSERT`.
             *
             * 🚨 Risultato: un 500 salvando dal pannello una scheda in cui non
             * si era toccato niente di nuovo. Trovato da un test, e i due casi
             * si normalizzano **qui** per la stessa ragione di sempre: chi
             * scrive e' piu' di uno.
             */
            if ($riga->carico === null || $riga->carico === '') {
                $riga->carico = 'peso';
            }

            if (is_array($serie) && $serie === []) {
                $riga->serie = null;
            }

            if (! is_array($serie) || $serie === []) {
                return;
            }

            foreach (self::riassuntoDelle($serie) as $campo => $valore) {
                $riga->{$campo} = $valore;
            }
        });
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlanDay::class, 'workout_plan_day_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Le serie **riga per riga**, da qualunque formato arrivi — 3b-D.10.
     *
     * ══ 🚨 L'UNICO POSTO CHE SA CHE ESISTONO DUE FORMATI ══════════════════
     *
     * ⛔ Le righe scritte prima del 25/08/2026 hanno `serie = null` e dicono la
     * stessa cosa in modo piu' povero: `sets` righe uguali, con `reps`,
     * `target_weight` e `rest_sec` ripetuti. Qui si espandono.
     *
     * 💡 **Non c'e' stato nessun backfill**, ed e' voluto: le righe senza
     * `serie` continueranno a nascere — l'importatore di PDF, un `create()` in
     * un test, il pannello di chi compila solo «serie x ripetizioni». Derivare
     * copre anche loro; una migrazione sarebbe passata ieri.
     *
     * 🚨 **La stessa forma che usa l'app** (`serie_prevista.dart`,
     * `serieDellEsercizio`), chiave per chiave: due formati per la stessa cosa
     * avrebbero voluto dire una conversione a ogni confine.
     *
     * ⛔ **Mai un elenco vuoto**: un esercizio senza serie non e' mostrabile.
     *
     * @return list<array{reps: ?int, weight: ?float, iso_sec: ?int, rest_sec: ?int}>
     */
    public function serieRighe(): array
    {
        $scritte = $this->serie;

        if (is_array($scritte) && $scritte !== []) {
            return array_values(array_map(
                static fn (array $r): array => [
                    'reps' => isset($r['reps']) ? (int) $r['reps'] : null,
                    'weight' => isset($r['weight']) ? (float) $r['weight'] : null,
                    'iso_sec' => isset($r['iso_sec']) ? (int) $r['iso_sec'] : null,
                    'rest_sec' => isset($r['rest_sec']) ? (int) $r['rest_sec'] : null,
                ],
                $scritte,
            ));
        }

        /*
         * ⚠️ **Il numero piu' basso di un intervallo**: «8-12» diventa 8. E' la
         * stessa prudenza che l'app applica leggendo la prescrizione —
         * sovrastimare il lavoro prescritto porta a credersi piu' avanti di
         * dove si e'.
         */
        preg_match('/\d+/', (string) $this->reps, $trovato);

        $riga = [
            'reps' => $trovato === [] ? null : (int) $trovato[0],
            'weight' => $this->target_weight,
            'iso_sec' => $this->duration_sec,
            'rest_sec' => $this->rest_sec,
        ];

        return array_fill(0, max(1, $this->sets ?? 1), $riga);
    }

    /**
     * Il riassunto nel formato vecchio, ricavato dalle righe — 3b-D.10.
     *
     * ══ ⚠️ PERCHE' SI CONTINUA A SCRIVERLO ════════════════════════════════
     *
     * ⛔ Non e' ridondanza per pigrizia. `sets`, `reps` e `target_weight` li
     * leggono ancora: il **pannello** (che mostra la prescrizione in una riga),
     * `prescription()`, l'app gia' installata che le serie non le conosce, e
     * ogni client futuro che non le implementera'.
     *
     * ⚠️ **Il riassunto perde le differenze fra le serie** — 12, 10 e 8
     * diventano `'12-8'` — ed e' il prezzo dichiarato della compatibilita'.
     *
     * @param  list<array<string, mixed>>  $righe
     * @return array{sets: int, reps: ?string, rest_sec: ?int, target_weight: ?float}
     */
    public static function riassuntoDelle(array $righe): array
    {
        $ripetizioni = array_values(array_filter(
            array_map(static fn (array $r): ?int => isset($r['reps']) ? (int) $r['reps'] : null, $righe),
            static fn (?int $v): bool => $v !== null,
        ));

        $primo = $ripetizioni[0] ?? null;
        $ultimo = $ripetizioni === [] ? null : $ripetizioni[count($ripetizioni) - 1];

        $pesi = array_values(array_filter(
            array_map(static fn (array $r): ?float => isset($r['weight']) ? (float) $r['weight'] : null, $righe),
            static fn (?float $v): bool => $v !== null,
        ));

        $recuperi = array_values(array_filter(
            array_map(static fn (array $r): ?int => isset($r['rest_sec']) ? (int) $r['rest_sec'] : null, $righe),
            static fn (?int $v): bool => $v !== null,
        ));

        return [
            'sets' => count($righe),
            'reps' => match (true) {
                $primo === null => null,
                $primo === $ultimo => (string) $primo,
                default => $primo.'-'.$ultimo,
            },
            'rest_sec' => $recuperi[0] ?? null,
            'target_weight' => $pesi[0] ?? null,
        ];
    }

    /** «3 × 8-12» oppure «3 × 45s»: la prescrizione in una riga. */
    public function prescription(): string
    {
        $serie = $this->sets !== null ? $this->sets.' × ' : '';

        if ($this->reps !== null && $this->reps !== '') {
            return $serie.$this->reps;
        }

        if ($this->duration_sec !== null) {
            return $serie.$this->duration_sec.'s';
        }

        return $serie !== '' ? rtrim($serie, ' × ') : '—';
    }
}
