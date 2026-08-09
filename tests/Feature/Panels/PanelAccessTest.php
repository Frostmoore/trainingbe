<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Dev\QuickLogin;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chi entra dove, e cosa succede dopo il login.
 *
 * Il form di accesso è **uno solo**: all'utente non si chiede di sapere in
 * anticipo se è amministratore di piattaforma o di palestra. Questi test
 * verificano che lo smistamento funzioni e che le porte sbagliate restino
 * chiuse.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $palestra;

    private User $god;

    private User $gymAdmin;

    private User $trainer;

    private User $member;

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

        foreach ([
            'gymAdmin' => UserRole::GymAdmin,
            'trainer' => UserRole::Trainer,
            'member' => UserRole::Member,
        ] as $prop => $ruolo) {
            $this->{$prop} = $ctx->runAs($this->palestra, function () use ($ruolo): User {
                $u = User::create([
                    'name' => $ruolo->label(),
                    'email' => $ruolo->value.'@demo.test',
                    'password' => self::FAKE_PASSWORD,
                ]);
                $u->assignRole($ruolo->value);

                return $u;
            });
        }
    }

    // ───────────────────── un solo indirizzo di accesso ─────────────────────

    #[Test]
    public function it_has_one_canonical_login_url(): void
    {
        $this->get('/login')->assertRedirect('/admin/login');
    }

    #[Test]
    public function both_panels_show_the_same_login_page(): void
    {
        $this->assertSame(
            filament()->getPanel('god')->getLoginRouteAction(),
            filament()->getPanel('admin')->getLoginRouteAction(),
        );

        $this->get('/admin/login')->assertOk();
        $this->get('/god/login')->assertOk();
    }

    #[Test]
    public function it_sends_guests_to_the_login_page(): void
    {
        $this->get('/god')->assertRedirect('/god/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    // ───────────────────── chi entra dove ─────────────────────

    #[Test]
    public function the_super_admin_reaches_the_platform_panel(): void
    {
        $this->actingAs($this->god)->get('/god')->assertOk();
    }

    #[Test]
    public function the_super_admin_cannot_enter_the_gym_panel(): void
    {
        // Non ha una palestra: nel pannello /admin non avrebbe nulla da vedere,
        // e il contesto vuoto significherebbe NESSUN filtro.
        $this->actingAs($this->god)->get('/admin')->assertForbidden();
    }

    /**
     * ⚠️ `flushSession()` fra un utente e l'altro non e' cosmetica: il
     * middleware `AuthenticateSession` di Filament invalida la sessione quando
     * l'utente autenticato cambia — protegge dai cambi di identita' sotto banco.
     * Senza, il secondo `actingAs` nello stesso test riceve un 302 verso il
     * login, e sembra un difetto del pannello.
     */
    #[Test]
    public function gym_staff_reaches_the_gym_panel(): void
    {
        foreach ([$this->gymAdmin, $this->trainer] as $u) {
            $this->flushSession();
            $this->actingAs($u)->get('/admin')->assertOk();
        }
    }

    #[Test]
    public function gym_staff_cannot_enter_the_platform_panel(): void
    {
        foreach ([$this->gymAdmin, $this->trainer] as $u) {
            $this->flushSession();
            $this->actingAs($u)->get('/god')->assertForbidden();
        }
    }

    /** Gli iscritti usano l'app: nessun pannello, mai. */
    #[Test]
    public function members_are_locked_out_of_every_panel(): void
    {
        $this->actingAs($this->member)->get('/admin')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->member)->get('/god')->assertForbidden();
    }

    #[Test]
    public function a_deactivated_user_is_locked_out(): void
    {
        $this->gymAdmin->forceFill(['is_active' => false])->save();

        $this->actingAs($this->gymAdmin->fresh())->get('/admin')->assertForbidden();
    }

    #[Test]
    public function a_suspended_gym_closes_the_panel(): void
    {
        $this->actingAs($this->gymAdmin)->get('/admin')->assertOk();

        $this->palestra->update(['status' => TenantStatus::Suspended]);

        $this->flushSession();
        $this->actingAs($this->gymAdmin->fresh())->get('/admin')->assertForbidden();
    }

    // ───────────────────── lo smistamento dopo il login ─────────────────────

    #[Test]
    public function it_redirects_each_role_to_its_own_panel(): void
    {
        $risposta = new RoleAwareLoginResponse;

        // La risposta può invalidare la sessione (caso iscritto), quindi la
        // richiesta finta deve averne una: senza, fallirebbe con «Session store
        // not set on request» — un errore del test, non del codice.
        $richiesta = Request::create('/admin/login', 'POST');
        $richiesta->setLaravelSession(app('session.store'));

        Auth::login($this->god);
        $this->assertStringContainsString('/god', $risposta->toResponse($richiesta)->getTargetUrl());
        Auth::logout();

        foreach ([$this->gymAdmin, $this->trainer] as $u) {
            Auth::login($u);
            $this->assertStringContainsString('/admin', $risposta->toResponse($richiesta)->getTargetUrl());
            Auth::logout();
        }
    }

    /** Un iscritto che arrivasse qui va rimandato fuori, non lasciato dentro. */
    #[Test]
    public function it_logs_out_a_member_that_somehow_reaches_the_response(): void
    {
        $richiesta = Request::create('/admin/login', 'POST');
        $richiesta->setLaravelSession(app('session.store'));

        Auth::login($this->member);

        $destinazione = (new RoleAwareLoginResponse)->toResponse($richiesta)->getTargetUrl();

        $this->assertStringContainsString('/login', $destinazione);
        $this->assertFalse(Auth::check(), 'L\'iscritto è rimasto autenticato.');
    }

    /**
     * Il login vero, attraverso Livewire.
     *
     * ⚠️ Serve **oltre** al test sulla risposta chiamata a mano, e non è un
     * doppione: dentro Livewire `redirect()` restituisce un `Redirector` di
     * Livewire invece di un `RedirectResponse`. Un tipo di ritorno stretto su
     * `toResponse()` faceva esplodere il login con un `TypeError` **solo qui** —
     * chiamando il metodo direttamente funzionava benissimo.
     *
     * Morale: il percorso reale va provato per intero, non a pezzi.
     */
    #[Test]
    public function it_completes_a_real_login_through_livewire(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->god->email, 'password' => self::FAKE_PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(filament()->getPanel('god')->getUrl());

        $this->assertTrue(Auth::check());
    }

    #[Test]
    public function gym_staff_land_in_the_gym_panel_through_livewire(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->gymAdmin->email, 'password' => self::FAKE_PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(filament()->getPanel('admin')->getUrl());
    }

    #[Test]
    public function members_are_told_to_use_the_app(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->member->email, 'password' => self::FAKE_PASSWORD])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertFalse(Auth::check());
    }

    /**
     * L'indirizzo «richiesto prima» non deve scavalcare il pannello giusto.
     *
     * Il caso reale: uno arriva su `/admin`, viene rimbalzato al login, entra
     * come super admin — e `intended()` lo riporta su `/admin`, dove un super
     * admin non può entrare. Login riuscito e 403 in faccia.
     */
    #[Test]
    public function it_ignores_an_intended_url_from_another_panel(): void
    {
        session(['url.intended' => url('/admin/qualcosa')]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $this->god->email, 'password' => self::FAKE_PASSWORD])
            ->call('authenticate')
            ->assertRedirect(filament()->getPanel('god')->getUrl());
    }

    /** Ma dentro il pannello giusto va onorato: si torna dove si voleva andare. */
    #[Test]
    public function it_honours_an_intended_url_inside_the_right_panel(): void
    {
        $destinazione = filament()->getPanel('admin')->getUrl().'/qualcosa';
        session(['url.intended' => $destinazione]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $this->gymAdmin->email, 'password' => self::FAKE_PASSWORD])
            ->call('authenticate')
            ->assertRedirect($destinazione);
    }

    // ───────────────────── accesso rapido di sviluppo ─────────────────────

    #[Test]
    public function quick_login_is_off_by_default(): void
    {
        config(['dev.quick_login' => false]);

        $this->assertFalse(QuickLogin::enabled());
        $this->assertSame([], QuickLogin::candidates());
    }

    /**
     * La seconda serratura: in produzione è spento comunque.
     *
     * La prima (la configurazione) non basta, perché i `.env` si copiano fra
     * ambienti. Una funzione che entra senza password non deve dipendere
     * dall'attenzione di nessuno.
     */
    #[Test]
    public function quick_login_stays_off_in_production_even_if_configured(): void
    {
        config(['dev.quick_login' => true]);
        $originale = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->assertFalse(QuickLogin::enabled());
            $this->assertSame([], QuickLogin::candidates());
            $this->assertNull(QuickLogin::resolve($this->god->email));
        } finally {
            app()->detectEnvironment(fn () => $originale);
        }
    }

    #[Test]
    public function quick_login_only_accepts_the_accounts_it_offers(): void
    {
        config(['dev.quick_login' => true]);

        $proposti = array_column(QuickLogin::candidates(), 'email');

        $this->assertContains($this->god->email, $proposti);
        $this->assertNotNull(QuickLogin::resolve($this->god->email));

        // Un utente vero, ma non fra quelli proposti: senza questo controllo
        // sarebbe «entra come chiunque, basta il suo indirizzo».
        $altro = app(TenantContext::class)->runAs($this->palestra, fn () => User::create([
            'name' => 'Estraneo', 'email' => 'estraneo@demo.test', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->assertNotContains($altro->email, $proposti);
        $this->assertNull(QuickLogin::resolve($altro->email));
        $this->assertNull(QuickLogin::resolve('inesistente@nessuno.test'));
    }

    #[Test]
    public function the_login_page_shows_the_shortcuts_only_when_enabled(): void
    {
        config(['dev.quick_login' => true]);
        $this->get('/admin/login')->assertOk()->assertSee('Piattaforma');

        config(['dev.quick_login' => false]);
        $this->get('/admin/login')->assertOk()->assertDontSee('Ambiente di sviluppo');
    }
}
