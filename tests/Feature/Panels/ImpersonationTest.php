<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Impersonation\Impersonator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B2.3 — l'impersonazione, e la traccia che la rende accettabile.
 *
 * 🚨 I test attraversano l'azione vera della tabella Filament, non chiamano
 * `Impersonator::start()` a mano. E' la lezione piu' cara della sessione
 * precedente: tre errori di fila nel login erano passati inosservati perche' i
 * test chiamavano i metodi direttamente invece del percorso.
 */
class ImpersonationTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private User $god;

    private Tenant $palestra;

    private User $adminPalestra;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra();

        $this->adminPalestra = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'admin@demo.test');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'trainer@demo.test');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@demo.test');

        $this->god = $this->creaSuperAdmin();

        filament()->setCurrentPanel('god');
    }

    // ───────────────────── il percorso completo ─────────────────────

    #[Test]
    public function the_super_admin_enters_a_gym_admin_account(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->adminPalestra);

        $this->assertSame(
            $this->adminPalestra->id,
            auth()->id(),
            'Dopo l\'azione la sessione e\' ancora del super admin: l\'impersonazione non e\' avvenuta.',
        );

        $this->assertTrue(app(Impersonator::class)->isImpersonating());
        $this->assertSame($this->god->id, app(Impersonator::class)->originalUser()?->id);
    }

    /**
     * 🚨 Il test che vale piu' di tutti gli altri.
     *
     * `AuthenticateSession` confronta l'hash della password in sessione con
     * quello dell'utente autenticato. Se dopo il cambio di identita' l'hash non
     * viene riallineato, la **prima richiesta successiva** trova due valori
     * diversi, conclude che la sessione e' stata dirottata e la svuota. Il
     * risultato sarebbe un'impersonazione che «funziona» finche' non si clicca
     * niente.
     */
    #[Test]
    public function the_impersonated_session_survives_the_next_request(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->adminPalestra);

        $this->get('/admin')
            ->assertSuccessful();

        $this->assertSame($this->adminPalestra->id, auth()->id());
    }

    #[Test]
    public function it_goes_back_to_the_original_account(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->trainer);

        $this->get(route('impersonation.stop'))
            ->assertRedirect();

        $this->assertSame($this->god->id, auth()->id());
        $this->assertFalse(app(Impersonator::class)->isImpersonating());
    }

    /** La via d'uscita deve essere l'ultima cosa a rompersi. */
    #[Test]
    public function stopping_without_impersonating_is_harmless(): void
    {
        $this->actingAs($this->god)
            ->get(route('impersonation.stop'))
            ->assertRedirect();

        $this->assertSame($this->god->id, auth()->id());
    }

    // ───────────────────── la traccia ─────────────────────

    #[Test]
    public function it_records_who_impersonated_whom(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->adminPalestra);

        $riga = AuditLog::withoutGlobalScopes()
            ->ofAction(AuditAction::ImpersonationStarted)
            ->first();

        $this->assertNotNull($riga, 'Nessuna traccia: l\'impersonazione sarebbe indistinguibile da un accesso legittimo.');

        // Chi: il super admin, non l'impersonato. E' il motivo per cui il log
        // si scrive PRIMA del cambio di identita'.
        $this->assertSame($this->god->id, $riga->actor_id);
        $this->assertSame($this->god->name, $riga->actor_label);
        $this->assertSame($this->god->email, $riga->actor_email);

        // Su chi.
        $this->assertSame(User::class, $riga->auditable_type);
        $this->assertSame($this->adminPalestra->id, (int) $riga->auditable_id);

        // Quale palestra: quella del bersaglio, non il nulla in cui gira /god.
        $this->assertSame($this->palestra->id, $riga->tenant_id);

        $this->assertSame('admin@demo.test', $riga->payload['target_email']);
        $this->assertNotNull($riga->created_at);
    }

    #[Test]
    public function it_records_the_exit_too(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->trainer);

        $this->get(route('impersonation.stop'));

        $uscita = AuditLog::withoutGlobalScopes()
            ->ofAction(AuditAction::ImpersonationStopped)
            ->first();

        $this->assertNotNull($uscita, 'Senza l\'uscita non si sa per quanto tempo e\' durato l\'accesso.');
        $this->assertSame($this->god->id, $uscita->actor_id);
        $this->assertSame($this->trainer->id, (int) $uscita->auditable_id);
    }

    /** Una riga di registro modificabile non vale niente come prova. */
    #[Test]
    public function the_log_cannot_be_altered_or_erased(): void
    {
        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->adminPalestra);

        $riga = AuditLog::withoutGlobalScopes()->first();

        $riga->actor_label = 'Qualcun altro';
        $riga->save();
        $riga->delete();

        $rilettura = AuditLog::withoutGlobalScopes()->find($riga->id);

        $this->assertNotNull($rilettura, 'Una riga di audit e\' stata cancellata.');
        $this->assertSame($this->god->name, $rilettura->actor_label);
    }

    // ───────────────────── chi non si tocca ─────────────────────

    #[Test]
    public function a_member_cannot_be_impersonated(): void
    {
        // Non entra in nessun pannello: la sessione sarebbe valida e inutile.
        $this->assertFalse(app(Impersonator::class)->can($this->god, $this->iscritto));

        $this->actingAs($this->god);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('impersona', $this->iscritto);
    }

    #[Test]
    public function another_super_admin_cannot_be_impersonated(): void
    {
        $altroGod = $this->creaSuperAdmin('god2@piattaforma.test');

        $this->assertFalse(app(Impersonator::class)->can($this->god, $altroGod));
    }

    #[Test]
    public function a_deactivated_user_cannot_be_impersonated(): void
    {
        $this->adminPalestra->forceFill(['is_active' => false])->save();

        $this->assertFalse(
            app(Impersonator::class)->can($this->god, $this->adminPalestra->refresh()),
            'Impersonare un account chiuso sarebbe il modo per farlo tornare vivo.',
        );
    }

    #[Test]
    public function a_gym_admin_cannot_impersonate_anyone(): void
    {
        $this->assertFalse(app(Impersonator::class)->can($this->adminPalestra, $this->trainer));

        $this->actingAs($this->adminPalestra);

        $this->expectException(RuntimeException::class);

        app(Impersonator::class)->start($this->trainer);
    }

    /**
     * Il controllo vero non e' la visibilita' del pulsante.
     *
     * Nascondere un'azione e' presentazione: chi ci arriva per un'altra strada
     * — un'invocazione diretta, una futura API — deve trovare comunque il
     * rifiuto.
     */
    #[Test]
    public function the_check_lives_in_the_service_not_in_the_button(): void
    {
        $this->actingAs($this->god);

        $this->expectException(RuntimeException::class);

        app(Impersonator::class)->start($this->iscritto);
    }
}
