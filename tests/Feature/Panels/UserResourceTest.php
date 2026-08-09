<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\God\Resources\Users\Pages\EditUser;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Filament\God\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B2.3 — l'elenco globale degli utenti nel pannello di piattaforma.
 */
class UserResourceTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private User $god;

    private Tenant $alfa;

    private Tenant $beta;

    private User $adminAlfa;

    private User $iscrittoBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->adminAlfa = $this->creaUtente($this->alfa, UserRole::GymAdmin, 'admin@alfa.test');
        $this->iscrittoBeta = $this->creaUtente($this->beta, UserRole::Member, 'iscritto@beta.test');

        $this->god = $this->creaSuperAdmin();

        filament()->setCurrentPanel('god');
    }

    // ───────────────────── chi ci arriva ─────────────────────

    #[Test]
    public function the_super_admin_sees_users_of_every_gym(): void
    {
        $this->actingAs($this->god)
            ->get('/god/users')
            ->assertOk()
            ->assertSee('admin@alfa.test')
            ->assertSee('iscritto@beta.test');
    }

    /**
     * Il controllo si ripete nella risorsa, non solo nel pannello.
     *
     * Vale doppio qui: e' l'unica pagina in cui gli account di tutti i clienti
     * stanno nella stessa tabella.
     */
    #[Test]
    public function nobody_else_can_reach_the_resource(): void
    {
        $this->actingAs($this->adminAlfa)->get('/god/users')->assertForbidden();

        auth()->login($this->adminAlfa);
        $this->assertFalse(UserResource::canAccess());
    }

    // ───────────────────── cosa si vede ─────────────────────

    #[Test]
    public function it_filters_by_gym(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->filterTable('tenant_id', $this->alfa->id)
            ->assertCanSeeTableRecords([$this->adminAlfa])
            ->assertCanNotSeeTableRecords([$this->iscrittoBeta]);
    }

    /**
     * I ruoli sono per palestra: senza leggere la pivot direttamente, in un
     * pannello che gira **senza contesto** la colonna sarebbe vuota per tutti.
     */
    #[Test]
    public function it_shows_roles_even_without_tenant_context(): void
    {
        $this->actingAs($this->god);

        $this->assertNull($this->ctx()->id(), 'Il pannello di piattaforma deve girare senza contesto.');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('ruoli', ['gym_admin'], $this->adminAlfa);
    }

    #[Test]
    public function it_filters_by_role(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->filterTable('ruolo', 'member')
            ->assertCanSeeTableRecords([$this->iscrittoBeta])
            ->assertCanNotSeeTableRecords([$this->adminAlfa, $this->god]);
    }

    #[Test]
    public function it_finds_the_super_admin_by_the_column_not_by_a_role(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->filterTable('ruolo', 'super_admin')
            ->assertCanSeeTableRecords([$this->god])
            ->assertCanNotSeeTableRecords([$this->adminAlfa]);
    }

    // ───────────────────── cosa non si puo' fare ─────────────────────

    /** Un utente nasce dentro una palestra, mai dal pannello di piattaforma. */
    #[Test]
    public function it_does_not_allow_creating_users_from_the_platform_panel(): void
    {
        $this->assertFalse(UserResource::canCreate());
        $this->assertArrayNotHasKey('create', UserResource::getPages());
    }

    // ───────────────────── modificare ─────────────────────

    #[Test]
    public function it_updates_a_user(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditUser::class, ['record' => $this->adminAlfa->getKey()])
            ->fillForm(['name' => 'Nome Corretto'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nome Corretto', $this->adminAlfa->refresh()->name);
    }

    /**
     * Disattivare un account e' una delle azioni che devono lasciare traccia:
     * chiude fuori una persona, e il giorno dopo qualcuno chiedera' chi e' stato.
     */
    #[Test]
    public function deactivating_a_user_is_recorded(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditUser::class, ['record' => $this->adminAlfa->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse((bool) $this->adminAlfa->refresh()->is_active);

        $riga = AuditLog::withoutGlobalScopes()
            ->ofAction(AuditAction::UserDeactivated)
            ->latest('id')
            ->first();

        $this->assertNotNull($riga, 'La disattivazione non e\' finita nel registro.');
        $this->assertSame($this->god->id, $riga->actor_id);
        $this->assertSame($this->adminAlfa->id, (int) $riga->auditable_id);
        $this->assertSame(
            $this->alfa->id,
            $riga->tenant_id,
            'La riga e\' finita senza palestra: quella palestra non potrebbe piu\' vederla.',
        );
    }

    /** Il salvataggio che NON cambia lo stato non deve sporcare il registro. */
    #[Test]
    public function a_plain_save_does_not_write_to_the_log(): void
    {
        $this->actingAs($this->god);

        Livewire::test(EditUser::class, ['record' => $this->adminAlfa->getKey()])
            ->fillForm(['name' => 'Solo il nome'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            0,
            AuditLog::withoutGlobalScopes()->ofAction(AuditAction::UserDeactivated)->count(),
        );
    }
}
