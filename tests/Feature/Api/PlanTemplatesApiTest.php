<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * S7 — i modelli della palestra, letti dall'app.
 *
 * ── 🎯 Perché questo endpoint esiste ──────────────────────────────────────
 *
 * L'assegnazione è uscita dal pannello. Il trainer manda una scheda **dalla
 * chat, dall'app**, perché è l'unico posto in cui esistono le chiavi per
 * cifrarla — ma i modelli li scrive nel pannello, e da lì l'app deve poterli
 * leggere per serializzarli dentro un messaggio.
 *
 * 💡 **I modelli restano sul server in chiaro, ed è deliberato**: sono esercizi,
 * serie e ripetizioni, non parlano di nessuno, e sono il patrimonio della
 * palestra. Quello che non deve restare scritto è il **legame fra una persona e
 * un programma** — e quello adesso viaggia solo dentro una busta cifrata.
 */
class PlanTemplatesApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    #[Test]
    public function a_trainer_reads_the_gym_templates(): void
    {
        $this->modello('Push — petto e spalle');

        $this->comeApp($this->trainer)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Push — petto e spalle');
    }

    /**
     * 🚨 Un iscritto qui non entra.
     *
     * Si vedrebbe **l'intero patrimonio di schede della palestra**: è il lavoro
     * del trainer, e non è materiale suo. Le proprie schede le legge da
     * `GET /workout-plans`, che è un'altra rotta e un'altra domanda.
     */
    #[Test]
    public function a_member_gets_nothing_from_here(): void
    {
        $this->modello('Push — petto e spalle');

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertForbidden();
    }

    /**
     * ⚠️ **Solo i modelli.** Un'istanza assegnata a qualcuno non è un modello, e
     * comparire qui vorrebbe dire mostrare a un trainer il programma che un
     * collega ha dato a un suo iscritto.
     */
    #[Test]
    public function assigned_instances_never_show_up_among_templates(): void
    {
        $this->modello('Modello');

        $assegnata = $this->modello('Assegnata a Mario');
        $this->ctx()->runAs($this->alfa, function () use ($assegnata): void {
            $assegnata->forceFill(['member_id' => $this->iscritto->getKey()])->save();
        });

        $this->comeApp($this->trainer)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Modello');
    }

    /** I modelli di un'altra palestra non esistono, per il global scope. */
    #[Test]
    public function the_templates_of_another_gym_are_invisible(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $altroTrainer = $this->creaUtente($beta, UserRole::Trainer, 'clara@beta.test');

        $this->modello('Solo di Alfa');

        $this->comeApp($altroTrainer)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * 💡 **Con gli esercizi dentro, non solo il titolo.**
     *
     * L'app deve poter serializzare la scheda **per intero** dentro il
     * messaggio: chi la riceve la conserva anche se domani cambia palestra, e
     * un elenco di id non gli servirebbe a niente.
     */
    #[Test]
    public function a_template_arrives_complete_enough_to_be_sent(): void
    {
        $modello = $this->modello('Push');

        $this->ctx()->runAs($this->alfa, function () use ($modello): void {
            $esercizio = Exercise::create([
                'tenant_id' => $this->alfa->id,
                'name' => 'Panca piana',
                'muscle_group' => 'chest',
            ]);

            $modello->exercises()->create([
                'exercise_id' => $esercizio->getKey(),
                'position' => 1,
                'sets' => 4,
                'reps' => '8-10',
            ]);
        });

        $this->comeApp($this->trainer)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertOk()
            ->assertJsonPath('data.0.exercises.0.exercise.name', 'Panca piana')
            ->assertJsonPath('data.0.exercises.0.sets', 4)
            ->assertJsonPath('data.0.exercises.0.reps', '8-10');
    }

    private function modello(string $titolo): WorkoutPlan
    {
        return $this->ctx()->runAs($this->alfa, fn () => WorkoutPlan::create([
            'tenant_id' => $this->alfa->id,
            'name' => $titolo,
            'created_by' => $this->trainer->getKey(),
            'status' => PlanStatus::Published,
            'published_at' => now(),
        ]));
    }
}
