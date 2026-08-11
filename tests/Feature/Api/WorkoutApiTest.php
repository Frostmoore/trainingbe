<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\KcalSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * B4.6 — il dominio allenamento visto dall'app.
 *
 * I test attraversano richieste HTTP vere, con il token di Sanctum: e' l'unico
 * modo per verificare anche la catena di middleware, che e' dove
 * l'isolamento fra palestre viene applicato davvero.
 */
class WorkoutApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $alfa;

    private Tenant $beta;

    private User $iscritto;

    private User $altroIscritto;

    private User $iscrittoBeta;

    private Exercise $panca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->altroIscritto = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
        $this->iscrittoBeta = $this->creaUtente($this->beta, UserRole::Member, 'anna@beta.test');

        // Esercizio della piattaforma: nessun tenant.
        $this->panca = $this->ctx()->runWithoutTenant(fn () => Exercise::create([
            'name' => 'Panca piana', 'muscle_group' => 'chest', 'equipment' => 'bilanciere', 'met' => 5.0,
        ]));
    }

    private function comeIscritto(?User $u = null): static
    {
        return $this->comeApp($u ?? $this->iscritto);
    }

    // ───────────────────────── schede ─────────────────────────

    #[Test]
    public function it_returns_only_the_published_plans_of_the_member(): void
    {
        $mia = $this->pianoPer($this->iscritto, 'La mia scheda', PlanStatus::Published);
        $bozza = $this->pianoPer($this->iscritto, 'Bozza', PlanStatus::Draft);
        $altrui = $this->pianoPer($this->altroIscritto, 'Di Luigi', PlanStatus::Published);

        $risposta = $this->comeIscritto()->getJson('/api/v1/workout-plans')->assertOk();

        $nomi = collect($risposta->json('data'))->pluck('name');

        $this->assertContains('La mia scheda', $nomi);
        $this->assertNotContains('Bozza', $nomi, 'Una bozza del trainer e\' arrivata nell\'app dell\'iscritto.');
        $this->assertNotContains('Di Luigi', $nomi, 'Un iscritto vede la scheda di un altro.');

        unset($mia, $bozza, $altrui);
    }

    /**
     * 🚨 Il caso che il global scope da solo non copre.
     *
     * Il filtro per palestra lascerebbe passare la scheda di un compagno della
     * **stessa** palestra: e' il filtro su `member_id` a impedirlo.
     */
    #[Test]
    public function a_member_cannot_open_the_plan_of_a_gym_mate(): void
    {
        $altrui = $this->pianoPer($this->altroIscritto, 'Di Luigi', PlanStatus::Published);

        $this->comeIscritto()
            ->getJson("/api/v1/workout-plans/{$altrui->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_member_of_another_gym_cannot_see_anything(): void
    {
        $this->pianoPer($this->iscritto, 'Scheda di Alfa', PlanStatus::Published);

        $risposta = $this->comeIscritto($this->iscrittoBeta)
            ->getJson('/api/v1/workout-plans')
            ->assertOk();

        $this->assertSame([], $risposta->json('data'));
    }

    #[Test]
    public function the_plan_detail_includes_the_exercises(): void
    {
        $piano = $this->pianoPer($this->iscritto, 'Full body', PlanStatus::Published);

        $piano->exercises()->create([
            'exercise_id' => $this->panca->id,
            'position' => 1, 'sets' => 4, 'reps' => '8-12', 'rest_sec' => 90,
        ]);

        $this->comeIscritto()
            ->getJson("/api/v1/workout-plans/{$piano->id}")
            ->assertOk()
            ->assertJsonPath('data.exercises.0.exercise.name', 'Panca piana')
            ->assertJsonPath('data.exercises.0.reps', '8-12')
            ->assertJsonPath('data.exercises.0.prescription', '4 × 8-12');
    }

    // ───────────────────────── sessioni ─────────────────────────

    #[Test]
    public function it_opens_and_closes_a_session(): void
    {
        $this->aiFinta()->willReturnKcal(310);

        $creata = $this->comeIscritto()
            ->postJson('/api/v1/workout-sessions', [])
            ->assertCreated()
            ->assertJsonPath('data.is_open', true);

        $id = $creata->json('data.id');

        $this->comeIscritto()
            ->postJson("/api/v1/workout-sessions/{$id}/finish", [])
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.kcal', 310)
            ->assertJsonPath('data.kcal_source', 'ai');
    }

    /**
     * 🚨 Il salvataggio di una serie e' un UPSERT.
     *
     * L'app rimanda lo stesso salvataggio quando la rete va e viene: senza il
     * vincolo e l'`updateOrCreate`, lo storico mostrerebbe il doppio del volume
     * che la persona ha davvero sollevato.
     */
    #[Test]
    public function repeating_the_same_set_does_not_duplicate_it(): void
    {
        $sessione = $this->sessioneAperta();

        $corpo = ['exercise_id' => $this->panca->id, 'set_number' => 1, 'reps' => 10, 'weight' => 60];

        $this->comeIscritto()->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", $corpo)->assertCreated();
        $this->comeIscritto()->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", $corpo)->assertCreated();

        $this->assertSame(1, $sessione->sets()->count());
    }

    #[Test]
    public function it_updates_a_set_that_was_already_logged(): void
    {
        $sessione = $this->sessioneAperta();

        $this->comeIscritto()->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", [
            'exercise_id' => $this->panca->id, 'set_number' => 1, 'reps' => 8, 'weight' => 60,
        ])->assertCreated();

        $this->comeIscritto()->postJson("/api/v1/workout-sessions/{$sessione->id}/sets", [
            'exercise_id' => $this->panca->id, 'set_number' => 1, 'reps' => 10, 'weight' => 62.5,
        ])->assertCreated();

        $serie = $sessione->sets()->first();

        $this->assertSame(10, $serie->reps);
        $this->assertSame(62.5, $serie->weight);
    }

    #[Test]
    public function a_member_cannot_touch_the_session_of_another(): void
    {
        $altrui = $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->altroIscritto->id, 'started_at' => now()->subHour(),
        ]));

        $this->comeIscritto()->getJson("/api/v1/workout-sessions/{$altrui->id}")->assertNotFound();
        $this->comeIscritto()->deleteJson("/api/v1/workout-sessions/{$altrui->id}")->assertNotFound();
        $this->comeIscritto()->postJson("/api/v1/workout-sessions/{$altrui->id}/sets", [
            'exercise_id' => $this->panca->id, 'set_number' => 1,
        ])->assertNotFound();
    }

    // ───────────────────────── calorie ─────────────────────────

    /**
     * 🚨 La regola piu' importante del dominio: **il manuale batte la stima**.
     */
    #[Test]
    public function a_manual_value_is_never_overwritten_by_an_estimate(): void
    {
        $this->aiFinta()->willReturnKcal(999);

        $sessione = $this->sessioneAperta();

        $this->comeIscritto()
            ->patchJson("/api/v1/workout-sessions/{$sessione->id}/kcal", ['kcal' => 450])
            ->assertOk()
            ->assertJsonPath('data.kcal', 450)
            ->assertJsonPath('data.kcal_source', 'manual');

        // Chiudere la sessione fa scattare la stima: non deve toccare niente.
        $this->comeIscritto()
            ->postJson("/api/v1/workout-sessions/{$sessione->id}/finish", [])
            ->assertOk()
            ->assertJsonPath('data.kcal', 450)
            ->assertJsonPath('data.kcal_source', 'manual');
    }

    /** `kcal: null` disfa la correzione e restituisce il valore alla stima. */
    #[Test]
    public function clearing_the_manual_value_gives_the_estimate_back(): void
    {
        $this->aiFinta()->willReturnKcal(275);

        $sessione = $this->sessioneAperta();
        $sessione->forceFill(['ended_at' => now()])->save();

        $this->comeIscritto()->patchJson("/api/v1/workout-sessions/{$sessione->id}/kcal", ['kcal' => 450]);

        $this->comeIscritto()
            ->patchJson("/api/v1/workout-sessions/{$sessione->id}/kcal", ['kcal' => null])
            ->assertOk()
            ->assertJsonPath('data.kcal', 275)
            ->assertJsonPath('data.kcal_source', 'ai');
    }

    /**
     * Se l'AI non risponde, l'utente vede comunque un numero.
     *
     * Ha appena finito di allenarsi: un trattino, o peggio un errore che parla
     * di un fornitore di cui non sa niente, e' inaccettabile.
     */
    #[Test]
    public function the_formula_covers_for_the_ai_when_it_fails(): void
    {
        $this->aiFinta()->willThrow(new \RuntimeException('fornitore giu\''));

        $sessione = $this->sessioneAperta(startedMinutesAgo: 60);

        /*
         * 🚨 Il peso arriva **nella richiesta** — S5.4.
         *
         * Prima si scriveva una riga in `body_metrics` e il server la leggeva.
         * Quella tabella non esiste piu' (D9-bis): il peso lo manda l'app, che
         * ce l'ha nel proprio archivio, e il server lo usa per questa stima
         * **senza conservarlo**.
         */
        $risposta = $this->comeIscritto()
            ->postJson("/api/v1/workout-sessions/{$sessione->id}/finish", ['weight_kg' => 80])
            ->assertOk()
            ->assertJsonPath('data.kcal_source', 'formula');

        // MET 5.0 × 80 kg × 1 h = 400
        $this->assertSame(400, $risposta->json('data.kcal'));
    }

    /** Una stima assurda si scarta: non deve mandare in negativo la giornata. */
    #[Test]
    public function an_absurd_estimate_is_discarded(): void
    {
        $this->aiFinta()->willReturnKcal(50_000);

        $sessione = $this->sessioneAperta(startedMinutesAgo: 60);

        $this->comeIscritto()
            ->postJson("/api/v1/workout-sessions/{$sessione->id}/finish", [])
            ->assertOk()
            ->assertJsonPath('data.kcal_source', 'formula');
    }

    // ───────────────────────── misure ─────────────────────────


    // ───────────────────────── esercizi ─────────────────────────

    #[Test]
    public function the_library_shows_global_plus_own_exercises(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => Exercise::create([
            'name' => 'Esercizio di Alfa', 'is_custom' => true,
        ]));
        $this->ctx()->runAs($this->beta, fn () => Exercise::create([
            'name' => 'Esercizio di Beta', 'is_custom' => true,
        ]));

        $nomi = collect($this->comeIscritto()->getJson('/api/v1/exercises')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Panca piana', $nomi, 'La libreria globale non arriva alla palestra.');
        $this->assertContains('Esercizio di Alfa', $nomi);
        $this->assertNotContains('Esercizio di Beta', $nomi, 'Un esercizio di un\'altra palestra e\' visibile.');
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function pianoPer(User $membro, string $nome, PlanStatus $stato): WorkoutPlan
    {
        return $this->ctx()->runAs($membro->tenant, function () use ($membro, $nome, $stato): WorkoutPlan {
            $p = WorkoutPlan::create([
                'member_id' => $membro->id,
                'name' => $nome,
                'status' => $stato,
            ]);

            if ($stato === PlanStatus::Published) {
                $p->forceFill(['published_at' => now()])->save();
            }

            return $p;
        });
    }

    private function sessioneAperta(int $startedMinutesAgo = 45): WorkoutSession
    {
        return $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->id,
            'started_at' => now()->subMinutes($startedMinutesAgo),
        ]));
    }
}
