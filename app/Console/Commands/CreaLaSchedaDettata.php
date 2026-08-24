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
 * Le schede dettate dal committente — 3b-B.3, 24/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Mi devi aggiungere una scheda, te la scrivo sotto»* e poi *«Prima di
 * procedere aggiungimi due schede. Giorno 2: … Giorno 3: …»*.
 *
 * ── ⚠️ Tre schede o una scheda a tre giorni? ──────────────────────────────
 *
 * 🚨 Il modello sa fare tutte e due: `WorkoutPlan` ha i `WorkoutPlanDay`. Qui
 * sono **tre schede separate** perche' e' come sono state chieste — *«aggiungimi
 * due **schede**»* — e perche' la prima era gia' nata da sola.
 *
 * 💡 Se un giorno si volesse una scheda unica a tre giorni, il modo c'e' e non
 * costa niente: si crea un piano e si aggiungono tre `WorkoutPlanDay`. E' una
 * decisione di prodotto, non un limite del codice.
 *
 * ── 🚨 Perche' un comando e non righe scritte a mano nel database ─────────
 *
 * ⛔ Un `INSERT` a mano sul server non lascia traccia, non si rifa' e non si
 * puo' rivedere. ⚠️ Un comando invece **e' il documento**: le schede stanno
 * scritte qui sotto come sono state dettate, e chiunque puo' vedere cosa e'
 * stato creato — e rifarlo dopo un ripristino.
 *
 * ── ⚠️ Un refuso, e cosa ci ho fatto ──────────────────────────────────────
 *
 * 🚨 Il committente ha scritto *«Estensione Dorso (**Ipertensione** con
 * Peso)»*, tre volte. In palestra quell'esercizio si chiama
 * **iperestensione**, e in staging la riga esiste gia' con il nome giusto.
 * ⛔ Scriverlo come dettato avrebbe creato un **secondo** esercizio, e il
 * progresso su quel movimento si sarebbe diviso in due righe.
 */
final class CreaLaSchedaDettata extends Command
{
    protected $signature = 'scheda:dettata
        {--utente= : email di chi le riceve}
        {--giorno=* : quali creare (1, 2, 3); vuoto = tutte}';

    protected $description = 'Crea le schede dettate dal committente il 24/08/2026';

    /**
     * Le tre schede, come sono state dettate.
     *
     * ⚠️ `reps` e' una **stringa**: «10-20» e' una prescrizione legittima, e un
     * intero qui perderebbe meta' della scheda. E' la stessa nota che sta in
     * `WorkoutPlanRequest` e nel prompt dei PDF — ci sta tre volte perche' e'
     * stata sbagliata almeno una.
     *
     * @var array<string, array{nome: string, esercizi: list<array{0: string, 1: int, 2: string, 3: int}>}>
     */
    private const SCHEDE = [
        '1' => [
            'nome' => 'Giorno 1',
            'esercizi' => [
                ['Piegamenti', 4, '10-20', 60],
                ['Panca Inclinata (Manubrio)', 4, '15', 60],
                ['Panca Inclinata (Bilanciere)', 4, '15', 60],
                ['Panca Piana (Manubrio)', 4, '15', 60],
                ['Panca Piana (Bilanciere)', 4, '15', 60],
                ['Croci (Manubrio)', 4, '15', 60],
                ['Curl Bicipiti (Bilanciere)', 4, '15', 60],
                ['Concentration Curl', 4, '15', 60],
                ['Curl Invertito (Manubrio)', 4, '15', 60],
                ['Bicipiti Martello (Manubrio)', 4, '20', 60],
                ['Calf Raise Gamba Singola in Piedi', 4, '35', 60],

                // ⚠️ L'unico con trenta secondi, e non e' una svista: e'
                // scritto cosi' in tutte e tre le schede.
                ['Calf Press (Macchina)', 4, '35', 30],

                // 🚨 Il nome corretto, non quello dettato: vedi la nota sulla
                // classe.
                ['Estensione Dorso (Iperestensione con Peso)', 4, '15', 60],

                ['Crunch', 4, '30', 60],
                ['Crunch Inverso', 4, '20', 60],
            ],
        ],

        '2' => [
            'nome' => 'Giorno 2',
            'esercizi' => [
                ['Piegamenti', 4, '10-20', 60],
                ['Rematore Inclinato (Bilanciere)', 4, '20', 60],
                ['Rematore Inclinato (Manubrio)', 4, '15', 60],

                // 💡 Dettato in minuscolo («rematore corda»): qui prende la
                // maiuscola come tutti gli altri. La riconciliazione passa
                // dalla forma canonica, quindi non cambia niente per il
                // matcher — cambia per chi legge la scheda.
                ['Rematore corda', 4, '15', 60],

                ['Croci Inverse (Manubrio)', 4, '15', 60],
                ['Push Press', 4, '15', 60],
                ['Lento in Avanti (Manubrio)', 4, '15', 60],
                ['Alzate Laterali (Cavo)', 4, '15', 60],
                ['Alzata Frontale (Manubrio)', 4, '15', 60],
                ['Skullcrusher (Manubrio)', 4, '15', 60],
                ['Estensione Tricipiti Braccio Singolo (Manubrio)', 4, '15', 60],
                ['Calf Raise Gamba Singola in Piedi', 4, '35', 60],
                ['Calf Press (Macchina)', 4, '35', 30],
                ['Estensione Dorso (Iperestensione con Peso)', 4, '15', 60],
                ['Crunch', 4, '30', 60],
                ['Crunch Inverso', 4, '20', 60],
            ],
        ],

        '3' => [
            'nome' => 'Giorno 3',
            'esercizi' => [
                ['Piegamenti', 4, '10-20', 60],

                // ⚠️ **Cinque serie, non quattro**: e' l'unico esercizio di
                // tutte e tre le schede che ne ha cinque, ed e' scritto cosi'.
                ['Squat (Corpo Libero)', 5, '15-20', 60],

                ['Panca Piana (Bilanciere)', 4, '15', 60],
                ['Curl Bicipiti (Manubrio)', 4, '15', 60],
                ['Rematore Manubrio', 4, '15', 60],
                ['Arnold Press (Manubrio)', 4, '15', 60],
                ['Skullcrusher (Manubrio)', 4, '15', 60],
                ['Calf Raise Gamba Singola in Piedi', 4, '35', 60],
                ['Calf Press (Macchina)', 4, '35', 30],
                ['Estensione Dorso (Iperestensione con Peso)', 4, '15', 60],
                ['Crunch', 4, '30', 60],
                ['Crunch Inverso', 4, '20', 60],
            ],
        ],
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

        $quali = $this->option('giorno');
        $quali = $quali === [] ? array_keys(self::SCHEDE) : $quali;

        $this->info(sprintf('Schede per %s (%s).', $utente->name, $utente->email));

        foreach ($quali as $chiave) {
            $scheda = self::SCHEDE[(string) $chiave] ?? null;

            if ($scheda === null) {
                $this->warn(sprintf('Il giorno «%s» non esiste.', $chiave));

                continue;
            }

            /*
             * ⛔ **Non si creano due volte.** Rilanciare il comando dopo un
             * ripristino deve rifare quello che manca, non raddoppiare quello
             * che c'e' gia'.
             */
            $gia = WorkoutPlan::withoutGlobalScopes()
                ->where('member_id', $utente->getKey())
                ->where('name', $scheda['nome'])
                ->exists();

            if ($gia) {
                $this->line(sprintf('  «%s» c\'era già: salto.', $scheda['nome']));

                continue;
            }

            $piano = $this->crea($utente, $scheda, $matcher);

            $this->info(sprintf(
                '  Creata «%s» (#%d) con %d esercizi.',
                $scheda['nome'],
                $piano->getKey(),
                $piano->exercises()->count(),
            ));
        }

        return self::SUCCESS;
    }

    /** @param  array{nome: string, esercizi: list<array{0: string, 1: int, 2: string, 3: int}>}  $scheda */
    private function crea(User $utente, array $scheda, ExerciseMatcher $matcher): WorkoutPlan
    {
        /*
         * 🚨 **Tutto o niente.** Se il matcher si ferma a meta' — e da 3b-A.3.5
         * si ferma, quando un esercizio non dice che muscoli allena — non deve
         * restare una scheda con sei esercizi su sedici: sembrerebbe completa,
         * e sarebbe peggio di nessuna scheda.
         */
        return DB::transaction(function () use ($utente, $scheda, $matcher): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $utente->tenant_id,
                'member_id' => $utente->getKey(),
                'created_by' => $utente->getKey(),
                'name' => $scheda['nome'],
                'status' => PlanStatus::Published,
                'source' => PlanSource::Manual,
            ]);

            $giorno = $piano->giornoPredefinito();
            $posizione = 0;

            foreach ($scheda['esercizi'] as [$nome, $serie, $reps, $riposo]) {
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
                    'sets' => $serie,
                    'reps' => $reps,
                    'rest_sec' => $riposo,
                ]);

                $this->line(sprintf(
                    '    · %-48s %d × %s, %ds',
                    $nome,
                    $serie,
                    $reps,
                    $riposo,
                ));
            }

            return $piano;
        });
    }
}
