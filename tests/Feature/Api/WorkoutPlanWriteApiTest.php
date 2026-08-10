<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\SessionSet;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * C2 — l'iscritto si scrive le proprie schede (decisione D1).
 *
 * Il cuore di questi test non è il CRUD: è la **distinzione fra la scheda
 * prescritta dal trainer e quella che l'iscritto si è scritto**, che convivono
 * nella stessa tabella e si distinguono per `created_by`.
 */
class WorkoutPlanWriteApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $iscritto;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'coach@alfa.test');
    }

    /** Una scheda scritta dal trainer per l'iscritto: prescrizione, non proprietà. */
    private function schedaDelTrainer(string $nome = 'Programma forza'): WorkoutPlan
    {
        return $this->ctx()->runAs($this->alfa, function () use ($nome): WorkoutPlan {
            $p = WorkoutPlan::create([
                'member_id' => $this->iscritto->id,
                'created_by' => $this->trainer->id,
                'name' => $nome,
                'status' => PlanStatus::Published,
            ]);

            $p->forceFill(['published_at' => now()])->save();

            return $p;
        });
    }

    /** @return array<string, mixed> */
    private function schedaValida(string $nome = 'Full body A'): array
    {
        return [
            'name' => $nome,
            'notes' => 'Tre volte a settimana',
            'exercises' => [
                ['name' => 'Panca piana', 'sets' => 4, 'reps' => '8-12', 'rest_sec' => 90, 'target_weight' => 60],
                ['name' => 'Squat', 'sets' => 3, 'reps' => 'cedimento', 'rest_sec' => 120],
            ],
        ];
    }

    // ───────────────────────── creazione ─────────────────────────

    #[Test]
    public function a_member_creates_a_plan_and_sees_it_immediately(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Full body A')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.author.is_me', true)
            ->assertJsonCount(2, 'data.exercises');

        // 🚨 Nasce pubblicata. Se restasse in bozza `index()` non la mostrerebbe:
        // un salvataggio riuscito che produce una scheda sparita.
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/workout-plans')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Full body A');
    }

    #[Test]
    public function the_plan_belongs_to_the_member_and_to_their_gym(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->assertCreated();

        $this->assertDatabaseHas('workout_plans', [
            'name' => 'Full body A',
            'tenant_id' => $this->alfa->id,
            'member_id' => $this->iscritto->getKey(),
            'created_by' => $this->iscritto->getKey(),
            'status' => PlanStatus::Published->value,
        ]);
    }

    /**
     * 🚨 «8-12», «cedimento», «max», «10+10» sono prescrizioni legittime.
     * Convertirle in intero credendo di correggere una svista rompe metà delle
     * schede reali.
     */
    #[Test]
    public function reps_survive_exactly_as_written(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->assertCreated();

        $reps = collect($risposta->json('data.exercises'))->pluck('reps')->all();

        $this->assertSame(['8-12', 'cedimento'], $reps);
    }

    #[Test]
    public function an_empty_row_left_by_the_editor_does_not_block_the_save(): void
    {
        // L'editor tiene volentieri una riga vuota in fondo, pronta da
        // compilare: rifiutare il salvataggio per quella è attrito gratuito.
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Con riga vuota',
                'exercises' => [
                    ['name' => 'Panca piana', 'sets' => 3],
                    ['name' => '   '],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.exercises');
    }

    #[Test]
    public function a_plan_without_a_name_is_rejected(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', ['exercises' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ───────────────────── modifica: il cuore di D1 ─────────────────────

    #[Test]
    public function a_member_edits_the_plan_they_wrote(): void
    {
        $id = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->json('data.id');

        $this->comeApp($this->iscritto)
            ->putJson("/api/v1/workout-plans/{$id}", [
                'name' => 'Full body A (rivista)',
                'exercises' => [['name' => 'Stacco da terra', 'sets' => 5, 'reps' => '5']],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Full body A (rivista)')
            ->assertJsonCount(1, 'data.exercises');
    }

    /**
     * 🚨 **Il test che tiene in piedi la decisione D1.**
     *
     * L'iscritto vede la scheda del trainer, la esegue, e **non la modifica**.
     * Se questo passasse, la prescrizione del trainer non sarebbe più una
     * prescrizione.
     */
    #[Test]
    public function a_member_cannot_edit_the_plan_written_by_the_trainer(): void
    {
        $scheda = $this->schedaDelTrainer();

        $this->comeApp($this->iscritto)
            ->putJson("/api/v1/workout-plans/{$scheda->id}", ['name' => 'La cambio io'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_not_editable');

        $this->assertDatabaseHas('workout_plans', [
            'id' => $scheda->id,
            'name' => 'Programma forza',
        ]);
    }

    #[Test]
    public function the_list_says_which_plans_are_editable(): void
    {
        $this->schedaDelTrainer();

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida('La mia'))
            ->assertCreated();

        $righe = collect($this->comeApp($this->iscritto)
            ->getJson('/api/v1/workout-plans')
            ->assertOk()
            ->json('data'))
            ->keyBy('name');

        // Senza questo campo l'app dovrebbe dedurlo da `created_by`, cioè
        // riscrivere in Dart una regola che vive in WorkoutPlanPolicy — e la
        // copia che sbaglia mostra un pulsante «Modifica» che il server rifiuta.
        $this->assertTrue($righe['La mia']['editable']);
        $this->assertFalse($righe['Programma forza']['editable']);
        $this->assertFalse($righe['Programma forza']['author']['is_me']);
        $this->assertSame('Coach', $righe['Programma forza']['author']['name']);
    }

    /**
     * Un trainer è anche una persona che si allena: la scheda che scrive per sé
     * resta sua. Distinguere per ruolo invece che per `created_by` gliela
     * renderebbe non modificabile.
     */
    #[Test]
    public function a_trainer_can_edit_the_plan_they_wrote_for_themselves(): void
    {
        $id = $this->comeApp($this->trainer, ['trainer'])
            ->postJson('/api/v1/workout-plans', $this->schedaValida('La mia personale'))
            ->assertCreated()
            ->json('data.id');

        $this->comeApp($this->trainer, ['trainer'])
            ->putJson("/api/v1/workout-plans/{$id}", ['name' => 'Aggiornata'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Aggiornata');
    }

    // ───────────────────────── eliminazione ─────────────────────────

    #[Test]
    public function a_member_deletes_the_plan_they_wrote(): void
    {
        $id = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->json('data.id');

        $this->comeApp($this->iscritto)
            ->deleteJson("/api/v1/workout-plans/{$id}")
            ->assertNoContent();

        $this->comeApp($this->iscritto)
            ->getJson("/api/v1/workout-plans/{$id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_plan_with_recorded_workouts_is_not_deleted(): void
    {
        $id = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->json('data.id');

        $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->id,
            'workout_plan_id' => $id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]));

        // Cancellarla lascerebbe uno storico senza origine: «cosa stavo facendo
        // quel giorno?» non avrebbe più risposta.
        $this->comeApp($this->iscritto)
            ->deleteJson("/api/v1/workout-plans/{$id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_not_deletable');
    }

    #[Test]
    public function a_member_cannot_delete_the_plan_of_the_trainer(): void
    {
        $scheda = $this->schedaDelTrainer();

        $this->comeApp($this->iscritto)
            ->deleteJson("/api/v1/workout-plans/{$scheda->id}")
            ->assertStatus(403);
    }

    // ───────────────────────── palestre diverse ─────────────────────────

    #[Test]
    public function a_plan_of_another_gym_is_not_found_not_forbidden(): void
    {
        $estraneo = $this->creaUtente($this->beta, UserRole::Member, 'anna@beta.test');

        $id = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->json('data.id');

        // 404 e non 403: un 403 confermerebbe che quell'id esiste.
        $this->comeApp($estraneo)
            ->putJson("/api/v1/workout-plans/{$id}", ['name' => 'Rubata'])
            ->assertNotFound();

        $this->comeApp($estraneo)
            ->deleteJson("/api/v1/workout-plans/{$id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_gym_mate_cannot_edit_someone_elses_plan(): void
    {
        $compagno = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $id = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', $this->schedaValida())
            ->json('data.id');

        // Stessa palestra: il global scope non basta, serve il filtro su member.
        $this->comeApp($compagno)
            ->putJson("/api/v1/workout-plans/{$id}", ['name' => 'Rubata'])
            ->assertNotFound();
    }

    // ─────────────────── C2.3 — esercizi personalizzati ───────────────────

    #[Test]
    public function a_new_exercise_is_created_for_the_gym_not_for_the_person(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/exercises', ['name' => 'Pulley basso presa stretta'])
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.is_global', false);

        // 🚨 D3: `tenant_id` valorizzato. Se nascesse del singolo iscritto, il
        // trainer vedrebbe venti nomi diversi per la stessa cosa e lo storico di
        // due persone non sarebbe confrontabile.
        $this->assertDatabaseHas('exercises', [
            'name' => 'Pulley basso presa stretta',
            'tenant_id' => $this->alfa->id,
            'created_by' => $this->iscritto->getKey(),
            'is_custom' => true,
        ]);
    }

    #[Test]
    public function an_exercise_that_already_exists_is_reused_not_duplicated(): void
    {
        $this->ctx()->runWithoutTenant(fn () => Exercise::create([
            'name' => 'Panca piana', 'muscle_group' => 'chest',
        ]));

        $prima = Exercise::withoutGlobalScopes()->count();

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/exercises', ['name' => 'panca piana'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.name', 'Panca piana');

        $this->assertSame(
            $prima,
            Exercise::withoutGlobalScopes()->count(),
            'È nato un doppione: la libreria degenera e le statistiche per esercizio si dividono.',
        );
    }

    #[Test]
    public function an_exercise_created_by_one_gym_is_invisible_to_another(): void
    {
        $estraneo = $this->creaUtente($this->beta, UserRole::Member, 'anna@beta.test');

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/exercises', ['name' => 'Macchina strana di Alfa'])
            ->assertCreated();

        $nomi = collect($this->comeApp($estraneo)
            ->getJson('/api/v1/exercises?q=strana')
            ->assertOk()
            ->json('data'))->pluck('name');

        $this->assertNotContains('Macchina strana di Alfa', $nomi);
    }

    // ─────────────── C2.4 — l'esercizio aggiunto al volo in sala ───────────────

    /**
     * In sala la scheda non corrisponde quasi mai alla realtà: una macchina
     * occupata, un esercizio sostituito. Costringere a un id significherebbe
     * creare prima l'esercizio in una seconda richiesta, che può fallire
     * lasciando la serie non registrata.
     */
    #[Test]
    public function a_set_can_be_logged_by_exercise_name(): void
    {
        $sessione = $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->id,
            'started_at' => now()->subMinutes(20),
        ]));

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", [
                'exercise_name' => 'Croci ai cavi bassi',
                'set_number' => 1,
                'reps' => 12,
                'weight' => 12.5,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('exercises', [
            'name' => 'Croci ai cavi bassi',
            'tenant_id' => $this->alfa->id,
        ]);
    }

    #[Test]
    public function the_same_name_logged_twice_does_not_create_two_exercises(): void
    {
        $sessione = $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->id,
            'started_at' => now()->subMinutes(20),
        ]));

        foreach ([1, 2, 3] as $n) {
            $this->comeApp($this->iscritto)
                ->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", [
                    'exercise_name' => 'Croci ai cavi bassi',
                    'set_number' => $n,
                    'reps' => 10,
                ])
                ->assertCreated();
        }

        $this->assertSame(
            1,
            Exercise::withoutGlobalScopes()->where('name', 'Croci ai cavi bassi')->count(),
        );

        $this->assertSame(3, SessionSet::where('workout_session_id', $sessione->id)->count());
    }

    #[Test]
    public function a_set_without_exercise_id_and_without_name_is_rejected(): void
    {
        $sessione = $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->id,
            'started_at' => now()->subMinutes(20),
        ]));

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", ['set_number' => 1, 'reps' => 10])
            ->assertStatus(422);
    }
}
