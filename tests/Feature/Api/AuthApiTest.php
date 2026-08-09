<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gli endpoint di autenticazione e branding.
 *
 * Il filo conduttore è uno: **nessuna risposta deve permettere di capire cosa
 * esiste**. Buona parte di questi test verifica messaggi *identici* in casi
 * diversi, che è il contrario di quello che si fa di solito.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $sospesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = Tenant::create(['name' => 'Alfa', 'slug' => 'alfa',
            'join_code' => 'ALFA2345', 'contact_email' => 'a@a.test']);

        $this->sospesa = Tenant::create(['name' => 'Sospesa', 'slug' => 'sospesa',
            'join_code' => 'SOSP2345', 'contact_email' => 's@s.test',
            'status' => TenantStatus::Suspended]);

        foreach ([$this->alfa, $this->sospesa] as $t) {
            app(TenantContext::class)->runAs($t, fn () => Role::create([
                'name' => 'member', 'guard_name' => 'web', 'tenant_id' => $t->id,
            ]));
        }
    }

    // ───────────────────────── branding ─────────────────────────

    #[Test]
    public function it_returns_branding_for_a_valid_code(): void
    {
        $this->getJson('/api/v1/branding/lookup?code=ALFA2345')
            ->assertOk()
            ->assertJsonPath('data.name', 'Alfa')
            ->assertJsonPath('data.slug', 'alfa')
            ->assertJsonStructure(['data' => ['name', 'slug', 'logo_url', 'colors', 'locale']]);
    }

    /**
     * Il corpo non deve contenere altro che il branding.
     *
     * Un `id` o un conteggio iscritti qui sarebbe informazione commerciale
     * regalata a chiunque conosca il codice.
     */
    #[Test]
    public function it_exposes_only_branding_fields(): void
    {
        $dati = $this->getJson('/api/v1/branding/lookup?code=ALFA2345')->json('data');

        $this->assertSame(
            ['name', 'slug', 'logo_url', 'colors', 'locale'],
            array_keys($dati),
        );
    }

    #[Test]
    public function it_returns_the_same_404_for_unknown_and_suspended_gyms(): void
    {
        $inesistente = $this->getJson('/api/v1/branding/lookup?code=ZZZZ9999');
        $sospesa = $this->getJson('/api/v1/branding/lookup?code=SOSP2345');
        $malformato = $this->getJson('/api/v1/branding/lookup?code=x');

        foreach ([$inesistente, $sospesa, $malformato] as $r) {
            $r->assertNotFound();
        }

        $this->assertSame(
            $inesistente->json(),
            $sospesa->json(),
            'Codice inesistente e palestra sospesa danno risposte diverse: '
            .'basta provare codici a tappeto per sapere chi ha smesso di pagare.',
        );

        $this->assertSame($inesistente->json(), $malformato->json());
    }

    // ───────────────────────── registrazione ─────────────────────────

    #[Test]
    public function it_registers_a_member_and_returns_a_token(): void
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'join_code' => 'alfa2345',
            'name' => 'Anna',
            'email' => ' Anna@Esempio.IT ',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
        ]);

        $r->assertCreated()
            ->assertJsonPath('data.email', 'anna@esempio.it')
            ->assertJsonPath('data.roles', ['member'])
            ->assertJsonPath('branding.name', 'Alfa');

        $this->assertIsString($r->json('token'));
    }

    /** Il ruolo non è un dato in ingresso: altrimenti bastava chiederlo. */
    #[Test]
    public function it_ignores_a_role_sent_by_the_client(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'join_code' => 'ALFA2345', 'name' => 'Furbo', 'email' => 'furbo@esempio.it',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
            'role' => 'gym_admin', 'is_super_admin' => true, 'tenant_id' => 999,
        ])->assertCreated()->assertJsonPath('data.roles', ['member']);

        $u = User::withoutGlobalScopes()->where('email', 'furbo@esempio.it')->first();

        $this->assertFalse($u->is_super_admin);
        $this->assertSame($this->alfa->id, $u->tenant_id);
    }

    #[Test]
    public function it_refuses_registration_on_a_suspended_gym(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'join_code' => 'SOSP2345', 'name' => 'X', 'email' => 'x@esempio.it',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('join_code');
    }

    // ───────────────────────── accesso ─────────────────────────

    #[Test]
    public function it_logs_in_with_valid_credentials(): void
    {
        $this->iscritto('anna@alfa.test');

        $this->postJson('/api/v1/auth/login', [
            'join_code' => 'ALFA2345', 'email' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('branding.slug', 'alfa');
    }

    #[Test]
    public function it_gives_the_same_error_for_wrong_password_and_unknown_email(): void
    {
        $this->iscritto('anna@alfa.test');

        $passwordErrata = $this->postJson('/api/v1/auth/login', [
            'join_code' => 'ALFA2345', 'email' => 'anna@alfa.test', 'password' => 'wrong-value',
        ]);
        $emailIgnota = $this->postJson('/api/v1/auth/login', [
            'join_code' => 'ALFA2345', 'email' => 'nessuno@alfa.test', 'password' => 'qualunque',
        ]);

        $passwordErrata->assertStatus(422);
        $emailIgnota->assertStatus(422);

        $this->assertSame(
            $passwordErrata->json('errors'),
            $emailIgnota->json('errors'),
            "I due errori sono distinguibili: si può scoprire chi è iscritto provando indirizzi.",
        );
    }

    #[Test]
    public function it_refuses_login_for_an_inactive_user(): void
    {
        $u = $this->iscritto('anna@alfa.test');
        $u->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'join_code' => 'ALFA2345', 'email' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertStatus(422);
    }

    // ───────────────────────── sessione ─────────────────────────

    #[Test]
    public function it_protects_authenticated_endpoints(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/auth/devices')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_the_current_user_and_branding(): void
    {
        $u = $this->iscritto('anna@alfa.test');

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'anna@alfa.test')
            ->assertJsonPath('branding.slug', 'alfa');
    }

    /** Il payload dell'utente non deve rivelare la struttura della piattaforma. */
    #[Test]
    public function it_never_exposes_tenant_id_or_super_admin_flag(): void
    {
        $u = $this->iscritto('anna@alfa.test');

        $dati = $this->actingAs($u, 'sanctum')->getJson('/api/v1/auth/me')->json('data');

        $this->assertArrayNotHasKey('tenant_id', $dati);
        $this->assertArrayNotHasKey('is_super_admin', $dati);
        $this->assertArrayNotHasKey('password', $dati);
    }

    #[Test]
    public function it_blocks_the_app_when_the_gym_is_suspended(): void
    {
        $u = $this->iscritto('anna@alfa.test');

        $this->actingAs($u, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

        $this->alfa->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($u->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_inactive');
    }

    /**
     * A palestra sospesa si deve comunque poter uscire.
     *
     * Chiudere anche questo intrappolerebbe una persona in una sessione che non
     * può né usare né terminare, per una decisione commerciale che non la
     * riguarda.
     */
    #[Test]
    public function it_still_allows_logout_and_device_management_when_suspended(): void
    {
        $u = $this->iscritto('anna@alfa.test');
        $this->alfa->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/v1/auth/devices')->assertOk();
        $this->actingAs($u->fresh(), 'sanctum')->postJson('/api/v1/auth/logout')->assertOk();
    }

    #[Test]
    public function it_cannot_revoke_another_users_device(): void
    {
        $anna = $this->iscritto('anna@alfa.test');
        $bruno = $this->iscritto('bruno@alfa.test');

        $tokenDiBruno = $bruno->createToken('telefono')->accessToken;

        $this->actingAs($anna, 'sanctum')
            ->deleteJson('/api/v1/auth/devices/'.$tokenDiBruno->id)
            ->assertNotFound();

        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::find($tokenDiBruno->id));
    }

    // ─────────────────────────────────────────────────────────────

    private function iscritto(string $email): User
    {
        return app(TenantContext::class)->runAs($this->alfa, function () use ($email): User {
            $u = User::create([
                'name' => 'Utente', 'email' => $email, 'password' => self::FAKE_PASSWORD,
            ]);
            $u->assignRole('member');

            return $u;
        });
    }
}
