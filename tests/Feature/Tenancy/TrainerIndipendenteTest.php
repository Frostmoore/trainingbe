<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il trainer indipendente e i suoi utenti — F6 della Parte B, 13/08/2026.
 *
 * ── 🚨 Perché il `join_code` non si poteva riusare ──────────────────────────
 *
 * `tenants.join_code` è il codice **della palestra**: chiunque lo conosca entra,
 * quante volte vuole, per sempre. Un invito di un trainer a **una persona** deve
 * essere monouso, a scadenza e revocabile uno per volta — tre proprietà che il
 * codice palestra non ha nessuna.
 *
 * 💡 Il caso concreto che rende necessaria la monouso: **un link che finisce in
 * una chat di gruppo non deve far entrare venti persone**.
 */
class TrainerIndipendenteTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function inviti(): InvitiDelTrainer
    {
        return app(InvitiDelTrainer::class);
    }

    private function creaTrainer(string $email = 'trainer@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)(
            'Trainer Indipendente',
            $email,
            ['password' => self::FAKE_PASSWORD, 'username' => str_replace(['@', '.'], '', $email)],
            UserRole::FreeTrainer,
        );
    }

    /** @param array<string, mixed> $extra */
    private function modulo(string $token, array $extra = []): array
    {
        return array_merge([
            'token' => $token,
            'name' => 'Nuovo Utente',
            'email' => 'nuovo@esempio.test',
            'username' => 'nuovo.utente',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
            'age_confirmed' => true,
            'terms_accepted' => true,
        ], $extra);
    }

    // ─────────────────────── F6.2 — l'invito ───────────────────────

    #[Test]
    public function an_invite_lets_exactly_one_person_in(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))
            ->assertCreated()
            ->assertJsonPath('data.roles', [UserRole::FreeUser->value]);

        // 🚨 Il secondo che prova lo stesso link non entra.
        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token, [
            'email' => 'secondo@esempio.test', 'username' => 'secondo',
        ]))->assertStatus(422);

        $this->assertSame(1, $trainer->assignedMembers()->count());
    }

    #[Test]
    public function an_expired_invite_is_refused(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);
        $invito->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))
            ->assertStatus(422);

        $this->assertSame(0, $trainer->assignedMembers()->count());
    }

    #[Test]
    public function a_revoked_invite_is_refused(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);
        $invito->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))
            ->assertStatus(422);
    }

    /**
     * 🚨 Un solo messaggio per «non esiste», «già usato», «scaduto» e
     * «revocato»: distinguerli permetterebbe di provare token a tappeto e capire
     * quali sono esistiti, e quando.
     */
    #[Test]
    public function every_bad_invite_says_the_same_thing(): void
    {
        $trainer = $this->creaTrainer();
        $scaduto = $this->inviti()->invita($trainer);
        $scaduto->forceFill(['expires_at' => now()->subMinute()])->save();

        $a = $this->postJson('/api/v1/auth/register-with-invite', $this->modulo('inesistente-xxxxxxxxxxxxxxxxxx'));
        $b = $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($scaduto->token));

        $this->assertSame($a->json('message'), $b->json('message'));
    }

    // ─────────────────── 🚨 F6.3 — 18+ dal primo commit ───────────────────

    /**
     * **La porta nuova nasce con lo sbarramento già montato** (§2.4).
     *
     * ⚠️ Non è una precauzione teorica: questa rotta riusa `RegisterRequest`
     * proprio perché il giorno in cui qualcuno le scrivesse un modulo suo, le
     * due caselle sarebbero la prima cosa a sparire.
     */
    #[Test]
    public function the_age_gate_is_there_from_the_first_commit(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token, [
            'age_confirmed' => false,
        ]))->assertStatus(422)->assertJsonValidationErrors('age_confirmed');

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token, [
            'terms_accepted' => false,
        ]))->assertStatus(422)->assertJsonValidationErrors('terms_accepted');

        $this->assertSame(0, $trainer->assignedMembers()->count());

        // ⚠️ E l'invito **non** si è consumato: chi ha sbagliato a spuntare una
        // casella non deve ritrovarsi il link bruciato.
        $this->assertTrue($invito->fresh()->eValido());
    }

    // ─────────────────── F6.1 — di chi è il legame ───────────────────

    /**
     * 🚨 `trainer_member.tenant_id` è quello del **trainer** — C4.
     *
     * ⚠️ È lui che «possiede» il rapporto: con il tenant dell'utente, il trainer
     * non vedrebbe i propri legami sotto il proprio scope — cioè non vedrebbe i
     * propri utenti.
     */
    #[Test]
    public function the_link_belongs_to_the_trainer_tenant(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))->assertCreated();

        $legame = DB::table('trainer_member')->first();

        $this->assertSame($trainer->tenant_id, $legame->tenant_id);
        $this->assertNotSame(
            $trainer->tenant_id,
            User::withoutGlobalScopes()->where('email', 'nuovo@esempio.test')->firstOrFail()->tenant_id,
            'L\'utente invitato deve avere un tenant SUO, non quello del trainer.',
        );
    }

    /** E il trainer li vede, dal proprio contesto. */
    #[Test]
    public function the_trainer_sees_their_people(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))->assertCreated();

        $this->actingAs($trainer->fresh(), 'sanctum')
            ->getJson('/api/v1/trainer/members')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Nuovo Utente')
            ->assertJsonPath('data.0.attivo', true);
    }

    /** ⚠️ Un iscritto qualunque non può invitare nessuno. */
    #[Test]
    public function a_plain_member_cannot_invite_anyone(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->actingAs($libero, 'sanctum')
            ->postJson('/api/v1/trainer/invites')
            ->assertStatus(403);
    }

    // ─────────────────── F6.5 — il limite del piano ───────────────────

    /**
     * ⚠️ **Gli inviti ancora validi contano come posti occupati.**
     *
     * Senza, un trainer con tre posti potrebbe mandare trenta inviti e
     * ritrovarsi con trenta utenti: il limite verrebbe verificato quando è
     * troppo tardi per dire di no a qualcuno che ha già cliccato.
     */
    #[Test]
    public function pending_invites_count_against_the_limit(): void
    {
        $this->seed(PlanSeeder::class);

        $trainer = $this->creaTrainer();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_FREE)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        $this->assertSame(3, $this->inviti()->postiRimasti($trainer->fresh()));

        $this->inviti()->invita($trainer);
        $this->inviti()->invita($trainer);
        $this->inviti()->invita($trainer);

        $this->assertSame(0, $this->inviti()->postiRimasti($trainer->fresh()));

        $this->expectException(ValidationException::class);
        $this->inviti()->invita($trainer);
    }

    /** 💡 Un invito revocato libera il posto. */
    #[Test]
    public function revoking_an_invite_frees_the_seat(): void
    {
        $this->seed(PlanSeeder::class);

        $trainer = $this->creaTrainer();
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_FREE)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        $invito = $this->inviti()->invita($trainer);
        $this->assertSame(2, $this->inviti()->postiRimasti($trainer->fresh()));

        $invito->forceFill(['revoked_at' => now()])->save();

        $this->assertSame(3, $this->inviti()->postiRimasti($trainer->fresh()));
    }

    /** Il piano pro non ha limite. */
    #[Test]
    public function the_pro_plan_has_no_limit(): void
    {
        $this->seed(PlanSeeder::class);

        $trainer = $this->creaTrainer();
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_PRO)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        $this->assertNull($this->inviti()->postiRimasti($trainer->fresh()));
    }

    // ─────────────── 🚨 F6.4 — disattivare chiude solo i messaggi ───────────────

    /**
     * **Il legame resta, la storia si conserva, il canale si chiude.**
     *
     * ⚠️ E l'utente **non** viene chiuso fuori dall'app: una persona che paga
     * anche un altro trainer si ritroverebbe l'account bloccato da un terzo.
     */
    #[Test]
    public function deactivating_closes_the_channel_and_nothing_else(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'nuovo@esempio.test')->firstOrFail();

        $this->actingAs($trainer->fresh(), 'sanctum')
            ->postJson("/api/v1/trainer/members/{$utente->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.attivo', false);

        // 🚨 Il legame c'è ancora.
        $this->assertSame(1, $trainer->fresh()->assignedMembers()->count());

        // 🚨 E l'utente entra ancora nell'app.
        $this->assertTrue($utente->fresh()->is_active);
        $this->actingAs($utente->fresh(), 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }

    /** ⚠️ Ed è reversibile: è tutto il senso di D5. */
    #[Test]
    public function it_can_be_undone(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);
        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'nuovo@esempio.test')->firstOrFail();

        $this->actingAs($trainer->fresh(), 'sanctum')->postJson("/api/v1/trainer/members/{$utente->id}/toggle");
        $this->actingAs($trainer->fresh(), 'sanctum')
            ->postJson("/api/v1/trainer/members/{$utente->id}/toggle")
            ->assertJsonPath('data.attivo', true);
    }

    /** ⚠️ Un trainer non può disattivare un utente che non è suo. */
    #[Test]
    public function a_trainer_cannot_touch_someone_elses_person(): void
    {
        $uno = $this->creaTrainer('uno@esempio.test');
        $due = $this->creaTrainer('due@esempio.test');

        $invito = $this->inviti()->invita($uno);
        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'nuovo@esempio.test')->firstOrFail();

        $this->actingAs($due->fresh(), 'sanctum')
            ->postJson("/api/v1/trainer/members/{$utente->id}/toggle")
            ->assertStatus(404);
    }

    /** 🚨 Gli inviti sono scopati: un trainer non vede quelli di un altro. */
    #[Test]
    public function invites_are_scoped_to_their_trainer(): void
    {
        $uno = $this->creaTrainer('uno@esempio.test');
        $due = $this->creaTrainer('due@esempio.test');

        $invito = $this->inviti()->invita($uno, 'destinatario@esempio.test');

        $this->actingAs($due->fresh(), 'sanctum')
            ->deleteJson("/api/v1/trainer/invites/{$invito->id}")
            ->assertStatus(404);

        $this->assertNull($invito->fresh()->revoked_at);
    }

    /** Il token è lungo: la difesa è quella, non l'oscurità. */
    #[Test]
    public function the_token_is_long_enough_to_be_a_secret(): void
    {
        $trainer = $this->creaTrainer();

        $this->assertSame(32, mb_strlen($this->inviti()->invita($trainer)->token));
        $this->assertNotSame(
            $this->inviti()->invita($trainer)->token,
            $this->inviti()->invita($trainer)->token,
        );
    }

    /** ⚠️ Un invito di un trainer disattivato non fa entrare nessuno. */
    #[Test]
    public function an_invite_from_a_disabled_trainer_is_dead(): void
    {
        $trainer = $this->creaTrainer();
        $invito = $this->inviti()->invita($trainer);

        $trainer->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/register-with-invite', $this->modulo($invito->token))
            ->assertStatus(422);

        $this->assertSame(0, TrainerInvite::withoutGlobalScopes()->whereNotNull('used_at')->count());
    }
}
