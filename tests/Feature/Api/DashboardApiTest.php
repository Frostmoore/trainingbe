<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\HealthMetric;
use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\BodyMetric;
use App\Models\FoodEntry;
use App\Models\HealthReading;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * D3 e D4 — HRV, battito e il riepilogo della schermata principale.
 */
class DashboardApiTest extends TestCase
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

    /** Il token per-utente dell'ingest: si genera come lo genera l'app. */
    private function tokenIngest(): string
    {
        $token = str_repeat('a', 64);

        $this->iscritto->forceFill(['health_ingest_token' => $token])->save();

        return $token;
    }

    private function misura(HealthMetric $metrica, float $valore, int $giorniFa = 0): void
    {
        $quando = Carbon::now()->subDays($giorniFa);

        $this->ctx()->runAs($this->alfa, fn () => HealthReading::create([
            'user_id' => $this->iscritto->getKey(),
            'source' => 'test',
            'metric' => $metrica->value,
            'measured_at' => $quando,
            'day' => $quando->toDateString(),
            'value' => $valore,
        ]));
    }

    // ───────────────── D3: ingest di HRV e battito ─────────────────

    #[Test]
    public function the_watch_can_send_hrv_and_heart_rate(): void
    {
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->tokenIngest(),
            'readings' => [
                ['metric' => 'hrv', 'measured_at' => now()->toIso8601String(), 'value' => 48.5],
                ['metric' => 'resting_hr', 'measured_at' => now()->toIso8601String(), 'value' => 54],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.readings_accepted', 2)
            ->assertJsonPath('data.readings_discarded', 0);

        $this->assertDatabaseHas('health_readings', [
            'user_id' => $this->iscritto->getKey(),
            'metric' => 'hrv',
            'tenant_id' => $this->alfa->id,
        ]);
    }

    /**
     * 🚨 Un HRV di 900 ms non è un dato: è un sensore che ha sbagliato.
     *
     * Se entrasse sposterebbe la media della persona, e da quel momento **ogni**
     * scostamento calcolato su di essa sarebbe falso — senza che niente lo
     * segnali.
     */
    #[Test]
    public function an_impossible_reading_is_discarded_and_counted(): void
    {
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->tokenIngest(),
            'readings' => [
                ['metric' => 'hrv', 'measured_at' => now()->toIso8601String(), 'value' => 900],
                ['metric' => 'hr', 'measured_at' => now()->toIso8601String(), 'value' => 300],
                ['metric' => 'hrv', 'measured_at' => now()->subHour()->toIso8601String(), 'value' => 45],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.readings_accepted', 1)
            // Contato, non ignorato in silenzio: uno scarto muto è
            // indistinguibile da un dato mai inviato.
            ->assertJsonPath('data.readings_discarded', 2);

        $this->assertSame(1, HealthReading::withoutGlobalScopes()->count());
    }

    #[Test]
    public function sending_the_same_reading_twice_does_not_duplicate_it(): void
    {
        $quando = now()->toIso8601String();

        // L'orologio rimanda gli stessi campioni a ogni sincronizzazione.
        foreach ([1, 2, 3] as $_) {
            $this->postJson('/api/v1/health/ingest', [
                'token' => $this->tokenIngest(),
                'readings' => [['metric' => 'hrv', 'measured_at' => $quando, 'value' => 50]],
            ])->assertCreated();
        }

        $this->assertSame(1, HealthReading::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_sleep_only_payload_still_works(): void
    {
        // ⚠️ Il ponte sul telefono manda solo `samples`: renderlo facoltativo
        // non deve aver rotto le versioni già installate.
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->tokenIngest(),
            'samples' => [
                [
                    'start' => now()->subHours(8)->toIso8601String(),
                    'end' => now()->subHours(7)->toIso8601String(),
                    'stage' => 5,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.accepted', 1);
    }

    #[Test]
    public function a_payload_with_neither_samples_nor_readings_is_rejected(): void
    {
        // Un 201 «accettati: 0» sarebbe un successo che non ha accettato nulla.
        $this->postJson('/api/v1/health/ingest', ['token' => $this->tokenIngest()])
            ->assertStatus(422);
    }

    // ───────────────── D4: il riepilogo ─────────────────

    #[Test]
    public function the_dashboard_answers_with_every_section(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'date',
                'now',
                'hour',
                'day_progress_pct',
                'nutrition' => ['totals', 'targets', 'burned', 'entries_count'],
                'training' => ['last_30_days', 'days_since_last', 'recent'],
                'body' => ['weight_kg', 'weight_delta', 'target_weight_kg'],
                'vitals' => ['has_any'],
            ]]);
    }

    /**
     * 🚨 **L'ora è parte del riepilogo**, non un dettaglio.
     *
     * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono due
     * situazioni opposte. Senza `day_progress_pct` né l'app né l'AI possono
     * distinguerle, e daranno lo stesso consiglio in entrambi i casi.
     */
    #[Test]
    public function the_day_progress_is_measured_on_waking_hours_not_on_24(): void
    {
        $servizio = app(DashboardService::class);

        $alle8 = $servizio->forToday($this->iscritto, Carbon::today()->setTime(8, 0));
        $alle20 = $servizio->forToday($this->iscritto, Carbon::today()->setTime(20, 0));
        $alle3 = $servizio->forToday($this->iscritto, Carbon::today()->setTime(3, 0));

        // Alle 8 del mattino è passato circa il 12% della giornata sveglia
        // (6→23), non il 33% delle ore: usare le 24 ore farebbe sembrare
        // «indietro» chiunque a metà mattina.
        $this->assertSame(12, $alle8['day_progress_pct']);
        $this->assertSame(82, $alle20['day_progress_pct']);
        $this->assertSame(0, $alle3['day_progress_pct']);
    }

    #[Test]
    public function the_vitals_carry_the_persons_own_baseline(): void
    {
        // Sette giorni attorno a 50, oggi 40.
        foreach (range(1, 7) as $g) {
            $this->misura(HealthMetric::Hrv, 50, $g);
        }

        $this->misura(HealthMetric::Hrv, 40);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        // 🚨 Il valore assoluto non si può giudicare: 40 ms sono ottimi per
        // qualcuno e pessimi per un altro. Conta lo scostamento dalla media
        // **di questa persona**, ed è per questo che viaggia insieme al valore.
        $this->assertSame(40.0, $risposta->json('data.vitals.hrv.value'));
        $this->assertSame(50.0, $risposta->json('data.vitals.hrv.average'));
        $this->assertSame(-20.0, $risposta->json('data.vitals.hrv.delta_pct'));
    }

    /**
     * ⚠️ La media **esclude** la misura di oggi: includendola la avvicinerebbe
     * artificialmente, e uno scostamento vero sembrerebbe più piccolo di
     * quanto è.
     */
    #[Test]
    public function todays_reading_is_not_part_of_its_own_baseline(): void
    {
        $this->misura(HealthMetric::Hrv, 60, 1);
        $this->misura(HealthMetric::Hrv, 20);

        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        // Con la misura di oggi dentro, la media sarebbe 40 e lo scostamento
        // -50%. Senza, la media è 60 e lo scostamento è -66,7%.
        $this->assertSame(60.0, $risposta->json('data.vitals.hrv.average'));
    }

    #[Test]
    public function without_any_watch_data_the_vitals_say_so_instead_of_showing_zeros(): void
    {
        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        // Uno zero verrebbe disegnato come un HRV pessimo invece che come un
        // dato mai arrivato.
        $this->assertNull($risposta->json('data.vitals.hrv'));
        $this->assertFalse($risposta->json('data.vitals.has_any'));
        $this->assertNull($risposta->json('data.sleep'));
    }

    #[Test]
    public function the_dashboard_counts_the_days_since_the_last_workout(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->getKey(),
            'started_at' => Carbon::today()->subDays(5)->setTime(18, 0),
            'ended_at' => Carbon::today()->subDays(5)->setTime(19, 0),
        ]));

        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        // «Non ti alleni da 5 giorni» è l'informazione che fa tornare in
        // palestra; un elenco di date costringe a fare il conto a mente.
        $this->assertSame(5, $risposta->json('data.training.days_since_last'));
        $this->assertSame(1, $risposta->json('data.training.last_30_days'));
    }

    #[Test]
    public function the_dashboard_shows_the_weight_and_how_it_changed(): void
    {
        $this->ctx()->runAs($this->alfa, function (): void {
            BodyMetric::create([
                'user_id' => $this->iscritto->getKey(),
                'date' => Carbon::today()->subDays(7)->toDateString(),
                'weight_kg' => 85.0,
            ]);

            BodyMetric::create([
                'user_id' => $this->iscritto->getKey(),
                'date' => Carbon::today()->toDateString(),
                'weight_kg' => 84.2,
            ]);
        });

        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        $this->assertSame(84.2, $risposta->json('data.body.weight_kg'));
        // Il numero da solo non dice se si sta andando nella direzione giusta.
        $this->assertSame(-0.8, $risposta->json('data.body.weight_delta'));
    }

    #[Test]
    public function the_dashboard_sums_what_was_eaten_today(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => Carbon::now(),
            'meal' => MealType::Lunch,
            'description' => 'Pasto',
            'kcal' => 700,
        ]));

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.nutrition.totals.kcal', 700.0)
            ->assertJsonPath('data.nutrition.entries_count', 1);
    }

    #[Test]
    public function the_dashboard_of_a_gym_mate_never_leaks_in(): void
    {
        $compagno = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $compagno->getKey(),
            'eaten_at' => Carbon::now(),
            'meal' => MealType::Lunch,
            'description' => 'Pasto di Luigi',
            'kcal' => 4321,
        ]));

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.nutrition.totals.kcal', 0.0)
            ->assertJsonPath('data.nutrition.entries_count', 0);
    }

    #[Test]
    public function the_dashboard_needs_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
