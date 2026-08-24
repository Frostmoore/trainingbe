<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Training\ExerciseMatcher;
use App\Support\Training\MuscoliDegliEsercizi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La scheda dettata dal committente — 3b-B.3, 24/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Mi devi aggiungere una scheda, te la scrivo sotto»* — quindici esercizi,
 * quattro serie l'uno, con ripetizioni e recuperi.
 *
 * ── 🚨 Perche' un comando e non una riga scritta a mano nel database ──────
 *
 * ⛔ Un `INSERT` a mano sul server non lascia traccia, non si rifa' e non si
 * puo' rivedere. ⚠️ Un comando invece **e' il documento**: la scheda sta scritta
 * qui sotto come l'ha dettata, e chiunque puo' vedere cosa e' stato creato.
 *
 * 💡 Ed e' rilanciabile: se la scheda va rifatta su un altro ambiente, o dopo un
 * ripristino, e' un comando invece di un ricordo.
 *
 * ── ⚠️ Un refuso, e cosa ci ho fatto ──────────────────────────────────────
 *
 * 🚨 Il committente ha scritto *«Estensione Dorso (**Ipertensione** con
 * Peso)»*. In palestra quell'esercizio si chiama **iperestensione**, e in
 * staging la riga esiste gia' con il nome giusto. ⛔ Scriverlo come dettato
 * avrebbe creato un **secondo** esercizio, e il progresso su quel movimento si
 * sarebbe diviso in due righe. Qui si usa il nome che c'e'.
 */
final class CreaLaSchedaDettata extends Command
{
    protected $signature = 'scheda:dettata
        {--utente= : email di chi la riceve}
        {--nome=Scheda A : come si chiama}';

    protected $description = 'Crea la scheda dettata dal committente il 24/08/2026';

    /**
     * Gli esercizi, come li ha dettati.
     *
     * ⚠️ `reps` e' una **stringa**: «10-20» e' una prescrizione legittima, e un
     * intero qui perderebbe meta' della scheda. E' la stessa nota che sta in
     * `WorkoutPlanRequest` e nel prompt dei PDF — ci sta tre volte perche' e'
     * stata sbagliata almeno una.
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const ESERCIZI = [
        ['Piegamenti', '10-20', 60],
        ['Panca Inclinata (Manubrio)', '15', 60],
        ['Panca Inclinata (Bilanciere)', '15', 60],
        ['Panca Piana (Manubrio)', '15', 60],
        ['Panca Piana (Bilanciere)', '15', 60],
        ['Croci (Manubrio)', '15', 60],
        ['Curl Bicipiti (Bilanciere)', '15', 60],
        ['Concentration Curl', '15', 60],
        ['Curl Invertito (Manubrio)', '15', 60],
        ['Bicipiti Martello (Manubrio)', '20', 60],
        ['Calf Raise Gamba Singola in Piedi', '35', 60],

        // ⚠️ L'unico con trenta secondi, e non e' una svista: e' scritto cosi'.
        ['Calf Press (Macchina)', '35', 30],

        // 🚨 Il nome corretto, non quello dettato: vedi la nota sulla classe.
        ['Estensione Dorso (Iperestensione con Peso)', '15', 60],

        ['Crunch', '30', 60],
        ['Crunch Inverso', '20', 60],
    ];

    public function handle(ExerciseMatcher $matcher): int
    {
        $email = (string) ($this->option('utente') ?? '');

        $utente = $email === ''
            ? User::query()->orderBy('id')->first()
            : User::query()->where('email', $email)->first();

        if ($utente === null) {
            $this->error('Non trovo l\'utente.');

            return self::FAILURE;
        }

        $this->info(sprintf('Scheda per %s (%s).', $utente->name, $utente->email));

        /*
         * 🚨 **Tutto o niente.** Se il matcher si ferma a meta' — e da 3b-A.3.5
         * si ferma, quando un esercizio non dice che muscoli allena — non deve
         * restare una scheda con sei esercizi su quindici: sembrerebbe
         * completa, e sarebbe peggio di nessuna scheda.
         */
        $piano = DB::transaction(function () use ($utente, $matcher): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $utente->tenant_id,
                'member_id' => $utente->getKey(),
                'created_by' => $utente->getKey(),
                'name' => (string) $this->option('nome'),
                'status' => PlanStatus::Published,
                'source' => PlanSource::Manual,
            ]);

            $giorno = $piano->giornoPredefinito();
            $posizione = 0;

            foreach (self::ESERCIZI as [$nome, $reps, $riposo]) {
                /*
                 * 💡 I muscoli si passano **dalla mappa**, non si lasciano
                 * indovinare: cosi' gli esercizi che la palestra non ha ancora
                 * nascono gia' completi invece di essere rifiutati da A.3.5.
                 */
                $muscoli = MuscoliDegliEsercizi::di($nome);

                $esercizio = $matcher->match(
                    $nome,
                    $utente->tenant_id,
                    $utente,
                    $muscoli['primario'] ?? null,
                    $muscoli['secondari'] ?? null,
                );

                $piano->exercises()->create([
                    'workout_plan_day_id' => $giorno->getKey(),
                    'exercise_id' => $esercizio->getKey(),
                    'position' => $posizione++,
                    'sets' => 4,
                    'reps' => $reps,
                    'rest_sec' => $riposo,
                ]);

                $this->line(sprintf('  · %-46s 4 × %s, %ds', $nome, $reps, $riposo));
            }

            return $piano;
        });

        $this->info(sprintf(
            'Creata la scheda #%d con %d esercizi.',
            $piano->getKey(),
            $piano->exercises()->count(),
        ));

        return self::SUCCESS;
    }
}
