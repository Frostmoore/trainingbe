<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SleepStage;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\HealthSample;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B9 — sonno e ingest dall'orologio.
 */
class HealthApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        $this->token = str_repeat('a', 64);
        $this->iscritto->forceFill(['health_ingest_token' => $this->token])->save();
    }

    // ───────────────────────── ingest ─────────────────────────

    #[Test]
    public function it_accepts_sleep_samples_with_the_per_user_token(): void
    {
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->token,
            'samples' => $this->notte(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.accepted', 4);

        $this->assertSame(4, HealthSample::withoutGlobalScopes()->count());
        $this->assertSame($this->alfa->id, HealthSample::withoutGlobalScopes()->first()->tenant_id);
    }

    /**
     * 🚨 L'orologio rimanda gli stessi campioni a ogni sincronizzazione.
     *
     * Senza il vincolo unico, una notte di sonno risulterebbe di trenta ore.
     */
    #[Test]
    public function sending_the_same_samples_twice_does_not_duplicate_them(): void
    {
        $corpo = ['token' => $this->token, 'samples' => $this->notte()];

        $this->postJson('/api/v1/health/ingest', $corpo)->assertCreated();
        $this->postJson('/api/v1/health/ingest', $corpo)->assertCreated();

        $this->assertSame(4, HealthSample::withoutGlobalScopes()->count());
    }

    /** Token sconosciuto e utente disattivato danno la stessa risposta. */
    #[Test]
    public function an_unknown_token_is_indistinguishable_from_a_disabled_user(): void
    {
        $a = $this->postJson('/api/v1/health/ingest', [
            'token' => str_repeat('z', 64),
            'samples' => $this->notte(),
        ])->assertUnauthorized();

        $this->iscritto->forceFill(['is_active' => false])->save();

        $b = $this->postJson('/api/v1/health/ingest', [
            'token' => $this->token,
            'samples' => $this->notte(),
        ])->assertUnauthorized();

        $this->assertSame($a->json('message'), $b->json('message'));
    }

    #[Test]
    public function a_suspended_gym_stops_the_ingest(): void
    {
        $this->alfa->update(['status' => TenantStatus::Suspended]);

        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->token,
            'samples' => $this->notte(),
        ])->assertForbidden();
    }

    /**
     * 🚨 Un campione delle 02:00 appartiene alla notte del giorno **precedente**.
     *
     * Senza questa regola, chi va a letto alle 23:30 avrebbe il sonno spezzato su
     * due giorni e nessuna delle due notti risulterebbe sufficiente.
     */
    #[Test]
    public function a_sample_after_midnight_belongs_to_the_previous_night(): void
    {
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->token,
            'samples' => [[
                'start' => '2026-08-10T02:00:00+02:00',
                'end' => '2026-08-10T03:00:00+02:00',
                'stage' => 5,
            ]],
        ])->assertCreated();

        $this->assertSame(
            '2026-08-09',
            HealthSample::withoutGlobalScopes()->first()->night->toDateString(),
        );
    }

    // ───────────────────────── analisi ─────────────────────────

    #[Test]
    public function it_returns_the_hypnogram_and_the_rating(): void
    {
        $this->postJson('/api/v1/health/ingest', [
            'token' => $this->token,
            'samples' => $this->notte(),
        ])->assertCreated();

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/health/sleep?night='.Carbon::today()->subDay()->toDateString())
            ->assertOk();

        $dati = $risposta->json('data');

        $this->assertNotNull($dati, 'Nessun riepilogo per una notte che ha campioni.');
        $this->assertCount(4, $dati['hypnogram']);
        $this->assertSame(SleepStage::Deep->value, $dati['hypnogram'][1]['stage']);
        $this->assertArrayHasKey('overall', $dati);
        $this->assertArrayHasKey('disclaimer', $dati);
    }

    /**
     * 🚨 Il giudizio complessivo e' il **peggiore**, non la media.
     *
     * Una notte di otto ore con poco sonno profondo non e' «buona in media»: e'
     * una notte lunga e poco riposante, e mediarla la farebbe passare per normale.
     */
    #[Test]
    public function the_overall_rating_is_the_worst_indicator_not_the_average(): void
    {
        // Otto ore, ma quasi tutto sonno leggero: dormito ok, profondo pessimo.
        $base = Carbon::today()->subDay()->setTime(23, 0);

        $campioni = [
            ['start' => $base->copy()->toIso8601String(), 'end' => $base->copy()->addMinutes(465)->toIso8601String(), 'stage' => 4],
            ['start' => $base->copy()->addMinutes(465)->toIso8601String(), 'end' => $base->copy()->addMinutes(480)->toIso8601String(), 'stage' => 5],
        ];

        $this->postJson('/api/v1/health/ingest', ['token' => $this->token, 'samples' => $campioni])
            ->assertCreated();

        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/health/sleep?night='.Carbon::today()->subDay()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertSame('ok', $dati['ratings']['asleep_minutes'], 'Otto ore dovrebbero valere «ok».');
        $this->assertSame('bad', $dati['ratings']['deep_pct']);
        $this->assertSame('bad', $dati['overall'], 'Il giudizio complessivo ha mediato invece di prendere il peggiore.');
    }

    #[Test]
    public function a_night_without_samples_returns_nothing(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/health/sleep?night=2020-01-01')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // ───────────────────────── token ─────────────────────────

    #[Test]
    public function rotating_the_token_invalidates_the_old_one(): void
    {
        $nuovo = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/health/ingest-token')
            ->assertCreated()
            ->json('data.token');

        $this->assertNotSame($this->token, $nuovo);

        $this->postJson('/api/v1/health/ingest', ['token' => $this->token, 'samples' => $this->notte()])
            ->assertUnauthorized();

        $this->postJson('/api/v1/health/ingest', ['token' => $nuovo, 'samples' => $this->notte()])
            ->assertCreated();
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * Una notte plausibile: leggero, profondo, REM, un risveglio.
     *
     * I codici sono quelli di Health Connect (`4/2` leggero, `5` profondo,
     * `6` REM, `3` sveglio), non i nostri: e' esattamente cio' che il controller
     * deve tradurre.
     *
     * @return list<array<string, mixed>>
     */
    private function notte(): array
    {
        $base = Carbon::today()->subDay()->setTime(23, 0);

        return [
            ['start' => $base->copy()->toIso8601String(), 'end' => $base->copy()->addMinutes(120)->toIso8601String(), 'stage' => 4],
            ['start' => $base->copy()->addMinutes(120)->toIso8601String(), 'end' => $base->copy()->addMinutes(210)->toIso8601String(), 'stage' => 5],
            ['start' => $base->copy()->addMinutes(210)->toIso8601String(), 'end' => $base->copy()->addMinutes(320)->toIso8601String(), 'stage' => 6],
            ['start' => $base->copy()->addMinutes(320)->toIso8601String(), 'end' => $base->copy()->addMinutes(340)->toIso8601String(), 'stage' => 3],
        ];
    }
}
