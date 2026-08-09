<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\Pages\CreateTenant;
use App\Filament\God\Resources\Tenants\Pages\EditTenant;
use App\Filament\God\Resources\Tenants\Pages\ListTenants;
use App\Filament\God\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La gestione delle palestre dal pannello della piattaforma.
 */
class TenantResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $god;

    private Tenant $palestra;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = app(TenantContext::class);

        $this->palestra = Tenant::create(['name' => 'Demo', 'slug' => 'demo',
            'join_code' => 'DEMO2345', 'contact_email' => 'd@d.test',
            'status' => TenantStatus::Active]);

        $ctx->runAs($this->palestra, function (): void {
            foreach (UserRole::tenantScoped() as $r) {
                Role::create(['name' => $r->value, 'guard_name' => 'web', 'tenant_id' => $this->palestra->id]);
            }
        });

        $this->god = $ctx->runWithoutTenant(fn () => User::create([
            'name' => 'God', 'email' => 'god@piattaforma.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $this->god->forceFill(['is_super_admin' => true])->save();

        filament()->setCurrentPanel('god');
    }

    // ───────────────────── chi ci arriva ─────────────────────

    #[Test]
    public function the_super_admin_sees_the_gyms(): void
    {
        $this->actingAs($this->god)
            ->get('/god/tenants')
            ->assertOk()
            ->assertSee('Demo')
            ->assertSee('DEMO2345');
    }

    /**
     * Il controllo si ripete anche se il pannello è già chiuso agli altri.
     *
     * Un solo controllo in un posto solo è un controllo che prima o poi
     * qualcuno aggira senza volerlo — un link diretto, una riorganizzazione
     * dei pannelli.
     */
    #[Test]
    public function nobody_else_can_reach_the_resource(): void
    {
        $ctx = app(TenantContext::class);

        $gymAdmin = $ctx->runAs($this->palestra, function (): User {
            $u = User::create(['name' => 'A', 'email' => 'a@demo.test', 'password' => self::FAKE_PASSWORD]);
            $u->assignRole(UserRole::GymAdmin->value);

            return $u;
        });

        $this->actingAs($gymAdmin)->get('/god/tenants')->assertForbidden();

        $this->assertFalse($ctx->runAs($this->palestra, fn () => $this->actingAsUser($gymAdmin)));
    }

    private function actingAsUser(User $u): bool
    {
        auth()->login($u);

        return TenantResource::canAccess();
    }

    // ───────────────────── creare ─────────────────────

    #[Test]
    public function it_creates_a_gym(): void
    {
        $this->actingAs($this->god);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Nuova Palestra',
                'slug' => 'nuova-palestra',
                'join_code' => 'NUOVA234',
                'contact_email' => 'info@nuova.test',
                'status' => TenantStatus::Trial->value,
                'plan' => 'starter',
                'color_primary' => '#111827',
                'color_secondary' => '#6B7280',
                'color_accent' => '#F59E0B',
                'locale' => 'it',
                'timezone' => 'Europe/Rome',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'nuova-palestra',
            'join_code' => 'NUOVA234',
        ]);
    }

    #[Test]
    public function it_refuses_a_duplicate_join_code(): void
    {
        $this->actingAs($this->god);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Copiona', 'slug' => 'copiona',
                'join_code' => 'DEMO2345',   // già di «Demo»
                'status' => TenantStatus::Trial->value, 'plan' => 'starter',
                'color_primary' => '#111827', 'color_secondary' => '#6B7280',
                'color_accent' => '#F59E0B', 'locale' => 'it', 'timezone' => 'Europe/Rome',
            ])
            ->call('create')
            ->assertHasFormErrors(['join_code']);
    }

    // ───────────────────── modificare ─────────────────────

    #[Test]
    public function it_updates_branding(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditTenant::class, ['record' => $this->palestra->getKey()])
            ->fillForm(['color_primary' => '#FF0000', 'name' => 'Demo Rinominata'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->palestra->refresh();

        $this->assertSame('#FF0000', $this->palestra->color_primary);
        $this->assertSame('Demo Rinominata', $this->palestra->name);
    }

    /** Lo slug finisce negli indirizzi: non si tocca dopo la creazione. */
    #[Test]
    public function the_slug_cannot_be_changed_after_creation(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditTenant::class, ['record' => $this->palestra->getKey()])
            ->assertFormFieldDisabled('slug');
    }

    /** Il branding modificato dal pannello arriva subito all'app. */
    #[Test]
    public function the_new_branding_reaches_the_public_endpoint(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditTenant::class, ['record' => $this->palestra->getKey()])
            ->fillForm(['color_accent' => '#00FF00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->getJson('/api/v1/branding/lookup?code=DEMO2345')
            ->assertOk()
            ->assertJsonPath('data.colors.accent', '#00FF00');
    }

    // ───────────────────── sospendere ─────────────────────

    #[Test]
    public function it_suspends_and_reactivates_a_gym(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListTenants::class)
            ->callTableAction('sospendi', $this->palestra);

        $this->assertSame(TenantStatus::Suspended, $this->palestra->refresh()->status);

        // Effetto immediato e verificabile da fuori: l'endpoint pubblico
        // smette di riconoscere il codice.
        $this->getJson('/api/v1/branding/lookup?code=DEMO2345')->assertNotFound();

        Livewire::test(ListTenants::class)
            ->callTableAction('sospendi', $this->palestra->refresh());

        $this->assertSame(TenantStatus::Active, $this->palestra->refresh()->status);
        $this->getJson('/api/v1/branding/lookup?code=DEMO2345')->assertOk();
    }

    /**
     * Sospendere chiude fuori tutti gli utenti di una palestra in un colpo solo:
     * e' fra le azioni per cui, il giorno dopo, qualcuno chiede chi e' stato.
     */
    #[Test]
    public function suspending_is_recorded_in_the_audit_log(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListTenants::class)
            ->callTableAction('sospendi', $this->palestra);

        $riga = \App\Models\AuditLog::withoutGlobalScopes()
            ->ofAction(\App\Enums\AuditAction::TenantSuspended)
            ->first();

        $this->assertNotNull($riga);
        $this->assertSame($this->god->id, $riga->actor_id);
        $this->assertSame($this->palestra->id, $riga->tenant_id);
    }

    // ───────────────────── elenco ─────────────────────

    #[Test]
    public function it_counts_the_users_of_each_gym(): void
    {
        app(TenantContext::class)->runAs($this->palestra, function (): void {
            User::create(['name' => 'U1', 'email' => 'u1@demo.test', 'password' => self::FAKE_PASSWORD]);
            User::create(['name' => 'U2', 'email' => 'u2@demo.test', 'password' => self::FAKE_PASSWORD]);
        });

        $this->actingAs($this->god);

        Livewire::test(ListTenants::class)
            ->assertCanSeeTableRecords([$this->palestra])
            ->assertTableColumnStateSet('users_count', 2, $this->palestra);
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        $sospesa = Tenant::create(['name' => 'Sospesa', 'slug' => 'sospesa',
            'join_code' => 'SOSP2345', 'contact_email' => 's@s.test',
            'status' => TenantStatus::Suspended]);

        $this->actingAs($this->god);

        Livewire::test(ListTenants::class)
            ->filterTable('status', TenantStatus::Suspended->value)
            ->assertCanSeeTableRecords([$sospesa])
            ->assertCanNotSeeTableRecords([$this->palestra]);
    }
}
