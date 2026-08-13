<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Models\PlanExercise;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Riempie un iscritto con schede di prova realistiche.
 *
 * 🚨 **Serve a poter provare il player.** Un account senza schede apre
 * l'allenamento su una schermata vuota: non si riesce a provare il pezzo di app
 * che conta di piu' — registrare le serie in palestra — e per farlo a mano
 * servono venti minuti di data entry a ogni ambiente nuovo.
 *
 * 🚨 **Le tre schede NON sono uguali per autore, ed e' il punto.**
 * Due sono scritte da un trainer e una dall'iscritto stesso: e' l'unico modo per
 * provare davvero la decisione D1, cioe' che `WorkoutPlanPolicy` distingue per
 * `created_by` e non per ruolo. Con tre schede tutte dello stesso autore, il
 * pulsante «Modifica» risulterebbe sempre presente o sempre assente e il difetto
 * si scoprirebbe in produzione.
 *
 * ⚠️ **Idempotente**: si riconosce dal nome della scheda dentro (palestra,
 * iscritto). Rilanciarlo non duplica, riallinea — perche' un comando che a ogni
 * esecuzione lascia un ambiente diverso e' un comando che non si osa rieseguire.
 *
 * ⚠️ Non e' un seeder di `DatabaseSeeder`: prende un'email in ingresso e non
 * deve mai partire da solo insieme agli altri.
 */
class SeedMemberPlans extends Command
{
    protected $signature = 'training:seed-plans
                            {email : email dell\'iscritto (dentro la sua palestra)}
                            {--dry : mostra cosa farebbe senza scrivere niente}';

    protected $description = 'Crea schede di allenamento di prova per un iscritto.';

    /**
     * Le schede: nome, note, autore (`trainer` o `member`) e righe.
     *
     * Ogni riga: esercizio, serie, ripetizioni, recupero in secondi, peso
     * obiettivo, note.
     *
     * I nomi degli esercizi sono **quelli della libreria di piattaforma**
     * (`ExerciseLibrarySeeder`): il comando li cerca sulla forma canonica e si
     * ferma se non li trova, invece di crearli. Crearli qui riempirebbe la
     * libreria di una palestra con roba nata da un comando di prova.
     *
     * @var list<array{nome: string, note: string, autore: string, righe: list<array{0:string,1:int,2:string,3:int,4:?float,5:?string}>}>
     */
    private const SCHEDE = [
        [
            'nome' => 'Push — petto, spalle, tricipiti',
            'note' => 'Primo giorno del ciclo. Se arrivi in fondo alle serie senza fatica, aggiungi 2,5 kg la settimana dopo.',
            'autore' => 'trainer',
            'righe' => [
                ['Panca piana', 4, '8-10', 120, 60.0, 'Scapole strette, piedi a terra.'],
                ['Panca inclinata', 3, '10-12', 90, 22.0, 'Manubri, angolo 30°.'],
                ['Shoulder press', 3, '10-12', 90, 16.0, null],
                ['Alzate laterali', 3, '12-15', 60, 8.0, 'Meglio leggero e pulito.'],
                ['Push down tricipiti', 3, '12-15', 60, 25.0, null],
                ['Dips', 2, 'cedimento', 90, null, 'A corpo libero, fin dove arrivi.'],
            ],
        ],
        [
            'nome' => 'Pull — schiena e bicipiti',
            'note' => 'Secondo giorno. Sullo stacco fermati se la schiena perde la posizione: e\' il segnale, non la stanchezza.',
            'autore' => 'trainer',
            'righe' => [
                ['Stacco da terra', 4, '5', 180, 90.0, 'Riscalda con due serie leggere.'],
                ['Trazioni', 4, 'max', 120, null, 'Con elastico se non arrivi a 6.'],
                ['Rematore con bilanciere', 3, '8-10', 90, 50.0, null],
                ['Pulley basso', 3, '10-12', 75, 45.0, null],
                ['Curl con bilanciere', 3, '10-12', 60, 25.0, null],
                ['Curl a martello', 3, '12', 60, 12.0, null],
            ],
        ],
        [
            'nome' => 'Gambe e core',
            'note' => 'Scritta da me. Terzo giorno, quello che salto sempre.',
            'autore' => 'member',
            'righe' => [
                ['Squat', 4, '6-8', 180, 80.0, 'Scendi almeno al parallelo.'],
                ['Pressa', 3, '10-12', 120, 140.0, null],
                ['Leg curl', 3, '12', 75, 35.0, null],
                ['Hip thrust', 3, '10-12', 90, 60.0, null],
                ['Calf in piedi', 4, '15-20', 45, 50.0, 'Ferma un secondo in alto.'],
                ['Plank', 3, '45 sec', 60, null, 'A tempo, non a ripetizioni.'],
            ],
        ],
    ];

    public function handle(TenantContext $tenancy): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $prova = (bool) $this->option('dry');

        // ⚠️ Senza `withoutGlobalScopes` non si trova nessuno: il global scope
        // filtra sul tenant corrente, che in CLI non e' impostato. E' lo stesso
        // motivo per cui tutto il resto gira dentro `runAs()`.
        $iscritto = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($iscritto === null) {
            $this->error("Nessun utente con email {$email}.");

            return self::FAILURE;
        }

        $tenant = $iscritto->tenant;

        if ($tenant === null) {
            $this->error("L'utente {$email} non appartiene a nessuna palestra.");

            return self::FAILURE;
        }

        $this->info("Iscritto: {$iscritto->name} (#{$iscritto->getKey()}) — palestra: {$tenant->name} (#{$tenant->getKey()})");

        return $tenancy->runAs($tenant, function () use ($iscritto, $prova): int {
            $trainer = $this->trovaTrainer($iscritto);

            if ($trainer === null) {
                $this->warn('Nessun trainer in questa palestra: le schede «del trainer» resteranno senza autore.');
                $this->warn('Nell\'app risulteranno comunque NON modificabili, che e\' il comportamento giusto.');
            } else {
                $this->line("Trainer: {$trainer->name} (#{$trainer->getKey()})");
            }

            $esercizi = $this->risolviEsercizi();

            if ($esercizi === null) {
                return self::FAILURE;
            }

            if ($prova) {
                foreach (self::SCHEDE as $scheda) {
                    $autore = $scheda['autore'] === 'member' ? 'iscritto (modificabile)' : 'trainer (sola lettura)';
                    $this->line("  [dry] {$scheda['nome']} — ".count($scheda['righe'])." esercizi — {$autore}");
                }

                $this->info('Niente scritto: era una prova.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($iscritto, $trainer, $esercizi): void {
                foreach (self::SCHEDE as $scheda) {
                    $this->creaScheda($scheda, $iscritto, $trainer, $esercizi);
                }
            });

            $this->newLine();
            $this->info('Fatto. Nell\'app: scheda Allenamento → le tre schede, con «Modifica» solo su «Gambe e core».');

            return self::SUCCESS;
        });
    }

    /**
     * Il trainer a cui attribuire le schede non modificabili.
     *
     * Si prende il primo trainer collegato all'iscritto; se non ce n'e' uno
     * collegato, un trainer qualsiasi della palestra. `null` e' un esito
     * accettabile — vedi l'avviso in `handle()`.
     */
    private function trovaTrainer(User $iscritto): ?User
    {
        $collegato = $iscritto->assignedTrainers()->first();

        if ($collegato !== null) {
            return $collegato;
        }

        return User::query()
            ->where('id', '!=', $iscritto->getKey())
            ->whereHas('roles', fn ($q) => $q->where('name', 'trainer'))
            ->first();
    }

    /**
     * Mappa nome → esercizio, risolta sulla forma canonica.
     *
     * 🚨 Si ferma se ne manca anche uno solo, invece di saltarlo: una scheda a
     * cui mancano due esercizi su sei sembra funzionante ed e' un'altra cosa da
     * quella che il comando dice di creare. Il rimedio e' lanciare prima
     * `db:seed --class=ExerciseLibrarySeeder`, e va detto.
     *
     * @return array<string, Exercise>|null
     */
    private function risolviEsercizi(): ?array
    {
        $nomi = [];

        foreach (self::SCHEDE as $scheda) {
            foreach ($scheda['righe'] as $riga) {
                $nomi[$riga[0]] = Exercise::normalize($riga[0]);
            }
        }

        $trovati = Exercise::query()
            ->whereIn('slug_normalized', array_values($nomi))
            ->get()
            ->keyBy('slug_normalized');

        $mappa = [];
        $mancanti = [];

        foreach ($nomi as $nome => $slug) {
            $esercizio = $trovati->get($slug);

            if ($esercizio === null) {
                $mancanti[] = $nome;

                continue;
            }

            $mappa[$nome] = $esercizio;
        }

        if ($mancanti !== []) {
            $this->error('Mancano questi esercizi nella libreria: '.implode(', ', $mancanti));
            $this->line('Rimedio: php artisan db:seed --class=ExerciseLibrarySeeder');

            return null;
        }

        return $mappa;
    }

    /**
     * @param  array{nome: string, note: string, autore: string, righe: list<array{0:string,1:int,2:string,3:int,4:?float,5:?string}>}  $scheda
     * @param  array<string, Exercise>  $esercizi
     */
    private function creaScheda(array $scheda, User $iscritto, ?User $trainer, array $esercizi): void
    {
        $autore = $scheda['autore'] === 'member' ? $iscritto : $trainer;

        $piano = WorkoutPlan::updateOrCreate(
            [
                'member_id' => $iscritto->getKey(),
                'name' => $scheda['nome'],
            ],
            [
                'tenant_id' => $iscritto->tenant_id,
                'created_by' => $autore?->getKey(),
                'notes' => $scheda['note'],
                'source' => 'manual',
            ],
        );

        // Le righe si riscrivono tutte: rilanciando il comando dopo aver
        // cambiato una prescrizione qui sopra, la scheda deve rispecchiare
        // questo file e non la somma delle due versioni.
        PlanExercise::where('workout_plan_id', $piano->getKey())->delete();

        // G4 — la colonna del giorno e' NOT NULL.
        $giorno = $piano->giornoPredefinito();

        foreach ($scheda['righe'] as $posizione => [$nome, $serie, $ripetizioni, $recupero, $peso, $note]) {
            PlanExercise::create([
                'workout_plan_id' => $piano->getKey(),
                'workout_plan_day_id' => $giorno->getKey(),
                'exercise_id' => $esercizi[$nome]->getKey(),
                'position' => $posizione,
                'sets' => $serie,
                'reps' => $ripetizioni,
                'rest_sec' => $recupero,
                'target_weight' => $peso,
                'notes' => $note,
            ]);
        }

        // `publish()` per ultimo: prima delle righe, l'app potrebbe scaricare
        // una scheda pubblicata e vuota.
        $piano->publish();

        $etichetta = $scheda['autore'] === 'member' ? 'modificabile' : 'sola lettura';
        $this->line("  ✓ {$scheda['nome']} — ".count($scheda['righe'])." esercizi — {$etichetta}");
    }
}
