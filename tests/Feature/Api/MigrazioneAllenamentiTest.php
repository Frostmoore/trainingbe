<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\DailyBurn;
use App\Models\Exercise;
use App\Models\SessionSet;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il trasloco degli allenamenti sul telefono — FASE 11.3, 21/08/2026.
 *
 * == 🚨 COSA DIFENDE QUESTO FILE ============================================
 *
 * Non che il pacchetto contenga i campi giusti — quello si vede leggendo. **Che
 * il server rifiuti di segnare "fatto" quando i conteggi non tornano.**
 *
 * ⚠️ E' l'unica difesa contro la perdita di dati di tutta la fase: un "fatto"
 * accettato a torto non da' nessun sintomo, e diventa una cancellazione
 * irreversibile il giorno che le tabelle cadranno (11.6). 🚨 Chi lo scopre lo
 * scopre quando i suoi allenamenti non ci sono piu' ne' di qua ne' di la'.
 */
class MigrazioneAllenamentiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    #[Test]
    public function the_package_carries_sessions_sets_and_manual_burns(): void
    {
        $seduta = $this->allena();
        $this->serie($seduta);
        $this->bruciaAMano(800);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/migrazione/allenamenti')
            ->assertOk();

        $this->assertSame(1, $risposta->json('data.counts.sessions'));
        $this->assertSame(1, $risposta->json('data.counts.sets'));
        $this->assertSame(1, $risposta->json('data.counts.daily_burns'));

        // 🚨 L'`id` del server c'e', ed e' quello che permette all'app di non
        // duplicare a una seconda passata.
        $this->assertSame($seduta->id, $risposta->json('data.sessions.0.id'));

        /*
         * ⚠️ **Il MET viaggia con la serie**, non con l'esercizio: il catalogo
         * resta sul server, e il calcolo delle calorie deve funzionare sul
         * telefono senza rete.
         */
        $this->assertSame(6.0, $risposta->json('data.sessions.0.sets.0.met'));
        $this->assertSame(800, $risposta->json('data.daily_burns.0.kcal'));
    }

    /**
     * 🚨 **Il test piu' importante del file.**
     *
     * ⛔ Meglio non segnare che segnare per sbaglio: un utente non segnato
     * riprova al prossimo avvio, un utente segnato a torto perde i dati.
     */
    #[Test]
    public function the_server_refuses_to_mark_done_when_the_counts_disagree(): void
    {
        $seduta = $this->allena();
        $this->serie($seduta);

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/migrazione/allenamenti/fatta', [
                // L'app dice di aver scritto una seduta e **zero** serie.
                'counts' => ['sessions' => 1, 'sets' => 0, 'daily_burns' => 0],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'conteggi_diversi')
            ->assertJsonPath('attesi.sets', 1);

        $this->assertNull($this->iscritto->fresh()->workouts_migrated_at);
    }

    #[Test]
    public function the_server_marks_done_when_the_counts_match(): void
    {
        $seduta = $this->allena();
        $this->serie($seduta);
        $this->bruciaAMano(500);

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/migrazione/allenamenti/fatta', [
                'counts' => ['sessions' => 1, 'sets' => 1, 'daily_burns' => 1],
            ])
            ->assertOk();

        $this->assertNotNull($this->iscritto->fresh()->workouts_migrated_at);
    }

    /**
     * 💡 Chi non ha mai registrato niente migra comunque, con zero righe.
     *
     * ⚠️ Senza questo caso resterebbe **per sempre** fra i non migrati, e
     * bloccherebbe la caduta delle tabelle in 11.6 senza avere niente da
     * perdere.
     */
    #[Test]
    public function someone_with_no_workouts_migrates_with_zeroes(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/migrazione/allenamenti/fatta', [
                'counts' => ['sessions' => 0, 'sets' => 0, 'daily_burns' => 0],
            ])
            ->assertOk();

        $this->assertNotNull($this->iscritto->fresh()->workouts_migrated_at);
    }

    #[Test]
    public function the_state_says_whether_the_move_already_happened(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/migrazione/allenamenti/stato')
            ->assertOk()
            ->assertJsonPath('data.migrated', false);

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/migrazione/allenamenti/fatta', [
                'counts' => ['sessions' => 0, 'sets' => 0, 'daily_burns' => 0],
            ])
            ->assertOk();

        // 💡 Serve all'app per non riscaricare il pacchetto a ogni avvio.
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/migrazione/allenamenti/stato')
            ->assertOk()
            ->assertJsonPath('data.migrated', true);
    }

    /**
     * 🚨 Il pacchetto contiene **solo** la roba di chi lo chiede.
     *
     * ⚠️ Non e' una precauzione teorica: qui dentro c'e' il diario di
     * allenamento di una persona, ed e' esattamente il genere di dato che il
     * committente ha voluto togliere dal server.
     */
    #[Test]
    public function the_package_never_carries_someone_elses_workouts(): void
    {
        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'lucia@alfa.test');

        $this->allena();
        $this->allena(chi: $altro);

        $risposta = $this->comeApp($altro)
            ->getJson('/api/v1/migrazione/allenamenti')
            ->assertOk();

        $this->assertSame(1, $risposta->json('data.counts.sessions'));
    }

    #[Test]
    public function it_needs_a_logged_in_user(): void
    {
        $this->getJson('/api/v1/migrazione/allenamenti')->assertUnauthorized();
        $this->postJson('/api/v1/migrazione/allenamenti/fatta')->assertUnauthorized();
    }

    // ───────────────────────── aiuto ─────────────────────────

    private function allena(?User $chi = null): WorkoutSession
    {
        $chi ??= $this->iscritto;

        return $this->ctx()->runAs($chi->tenant, fn () => WorkoutSession::create([
            'user_id' => $chi->getKey(),
            'started_at' => Carbon::now()->subHour(),
            'ended_at' => Carbon::now(),
        ]));
    }

    private function serie(WorkoutSession $seduta): SessionSet
    {
        return $this->ctx()->runAs($this->alfa, function () use ($seduta): SessionSet {
            $esercizio = Exercise::create([
                'name' => 'Squat',
                'muscle_group' => 'glutes',
                'met' => 6.0,
            ]);

            return SessionSet::create([
                'workout_session_id' => $seduta->getKey(),
                'exercise_id' => $esercizio->getKey(),
                'set_number' => 1,
                'reps' => 10,
                'weight' => 60.0,
            ]);
        });
    }

    private function bruciaAMano(int $kcal): DailyBurn
    {
        return $this->ctx()->runAs($this->alfa, fn () => DailyBurn::create([
            'user_id' => $this->iscritto->getKey(),
            'date' => Carbon::now()->toDateString(),
            'kcal' => $kcal,
        ]));
    }
}
