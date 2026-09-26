<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'contact_email' => 'a@a.test']);

        $this->sospesa = Tenant::create(['name' => 'Sospesa', 'slug' => 'sospesa',
            'contact_email' => 's@s.test',
            'status' => TenantStatus::Suspended]);

        foreach ([$this->alfa, $this->sospesa] as $t) {
            app(TenantContext::class)->runAs($t, fn () => Role::create([
                'name' => 'member', 'guard_name' => 'web', 'tenant_id' => $t->id,
            ]));
        }
    }

    // ───────────────────────── ⛔ il codice palestra (F11) ─────────────────────────

    /**
     * ⛔ **Il codice palestra non esiste più** — F11, 24/09/2026. La ricerca
     * della palestra dal codice (`branding/lookup`) non c'è più: il branding
     * arriva con l'accesso o con l'invito.
     */
    #[Test]
    public function the_gym_code_lookup_is_gone(): void
    {
        $this->getJson('/api/v1/branding/lookup?code=ABCD2345')->assertNotFound();
    }

    /**
     * 🚨 Chi si iscrive **con un codice** pensa di entrare in una palestra:
     * ignorarlo lo farebbe nascere fuori senza dirglielo. Si rifiuta, e il
     * messaggio dice dove si entra.
     */
    #[Test]
    public function a_registration_with_a_gym_code_is_refused_with_a_clear_message(): void
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'join_code' => 'ALFA2345', 'name' => 'Vecchia App', 'email' => 'vecchia@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'vecchia.app',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('join_code');

        $this->assertStringContainsString("link d'invito", $r->json('errors.join_code.0'));
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'vecchia@esempio.it')->first());
    }

    /** ⚠️ Il campo **vuoto** (le app vecchie lo mandano sempre) non è un codice. */
    #[Test]
    public function an_empty_gym_code_is_no_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'join_code' => '  ', 'name' => 'Vuoto', 'email' => 'vuoto@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'vuoto',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
        ])->assertCreated();
    }

    /**
     * ⚠️ All'**accesso**, invece, un codice mandato da un'app vecchia **non si
     * legge**: respingerlo non proteggerebbe niente, e chiuderebbe fuori chi ha
     * le credenziali giuste.
     */
    #[Test]
    public function a_gym_code_sent_at_login_is_ignored(): void
    {
        $this->iscritto('anna@alfa.test');

        $this->postJson('/api/v1/auth/login', [
            'join_code' => 'ZZZZ9999', 'login' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('branding.slug', 'alfa');
    }

    // ───────────────────────── registrazione ─────────────────────────

    #[Test]
    public function it_registers_a_member_and_returns_a_token(): void
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'age_confirmed' => true, 'terms_accepted' => true,
            'name' => 'Anna',
            'email' => ' Anna@Esempio.IT ',
            'username' => ' Anna.Nuova ',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
        ]);

        // ✅ F11: senza codice palestra si nasce **sempre** nel proprio spazio.
        $r->assertCreated()
            ->assertJsonPath('data.email', 'anna@esempio.it')
            ->assertJsonPath('data.roles', [UserRole::FreeUser->value]);

        $this->assertIsString($r->json('token'));

        // 🚨 La forma della risposta è `{token, data, branding}`, **non** un
        // inviluppo `{data: …}`. Il client dell'app srotolava `data` per ogni
        // risposta e qui perdeva il token: il login non funzionava per nessuno
        // e il messaggio dava la colpa all'utente. Questo controllo esiste
        // perché la forma non cambi senza che qualcuno se ne accorga.
        $this->assertArrayHasKey('token', $r->json());
        $this->assertArrayHasKey('data', $r->json());
        $this->assertArrayHasKey('branding', $r->json());

        // Il nome utente si normalizza: minuscolo e senza spazi.
        $this->assertSame(
            'anna.nuova',
            User::withoutGlobalScopes()->where('email', 'anna@esempio.it')->value('username'),
        );
    }

    /**
     * 🚨 Il nome utente è unico su **tutta la piattaforma**, non per palestra.
     *
     * È voluto: serve a essere un identificativo che non ha bisogno di niente
     * per essere disambiguato — è quello che permette di entrare anche quando
     * la stessa email è in due posti.
     */
    #[Test]
    public function it_refuses_a_username_already_taken_in_another_gym(): void
    {
        // Nella palestra sospesa, che è l'altra che questo test ha a
        // disposizione: quello che conta è che sia **un'altra**.
        app(TenantContext::class)->runAs($this->sospesa, fn () => User::create([
            'name' => 'Altro', 'email' => 'altro@sospesa.test',
            'username' => 'gia.preso', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Anna', 'email' => 'anna2@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'gia.preso',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /**
     * ⚠️ Senza `withoutMiddleware` il limitatore taglia il test al terzo giro:
     * `auth-register` consente 3 registrazioni al minuto, ed è giusto che sia
     * così. Qui si sta provando la **validazione**, non il limite — quello ha
     * il suo test altrove.
     */
    #[Test]
    public function it_refuses_a_malformed_username(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['ab', 'con spazi', 'con@chiocciola', '.punto', 'punto.'] as $sbagliato) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'X', 'email' => 'x'.md5($sbagliato).'@esempio.it',
                'age_confirmed' => true, 'terms_accepted' => true,
                'username' => $sbagliato,
                'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
            ])->assertStatus(422)->assertJsonValidationErrors('username');
        }
    }

    /** Il ruolo non è un dato in ingresso: altrimenti bastava chiederlo. */
    #[Test]
    public function it_ignores_a_role_sent_by_the_client(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Furbo', 'email' => 'furbo@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'furbo',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
            'role' => 'gym_admin', 'is_super_admin' => true, 'tenant_id' => 999,
        ])->assertCreated()->assertJsonPath('data.roles', [UserRole::FreeUser->value]);

        $u = User::withoutGlobalScopes()->where('email', 'furbo@esempio.it')->first();

        $this->assertFalse($u->is_super_admin);
        $this->assertNotSame(999, $u->tenant_id);
        $this->assertTrue($u->tenant->ePersonale());
    }

    // ───────────────────────── la password ─────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function passwordRifiutate(): array
    {
        return [
            'troppo corta' => ['Ab1cdef', 'meno di otto caratteri'],
            'senza numeri' => ['soltantolettere', 'lettere e basta'],
            'senza lettere' => ['12345678901', 'cifre e basta'],
        ];
    }

    /**
     * 🚨 Il floor lato server.
     *
     * L'indicatore dell'app e' una **guida**, non un controllo: si aggira
     * spegnendo il telefono. Quello che tiene e' questo.
     */
    #[Test]
    #[DataProvider('passwordRifiutate')]
    public function it_refuses_a_password_below_the_floor(string $password, string $perche): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Debole', 'email' => 'debole@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'debole',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(
            User::withoutGlobalScopes()->where('email', 'debole@esempio.it')->first(),
            "L'utente non deve esistere: {$perche}.",
        );
    }

    /**
     * 🚨 Il controllo che l'app rendeva inutile mandando due volte lo stesso
     * valore.
     *
     * `confirmed` esiste per intercettare un **errore di battitura**: se il
     * client ricopia il primo campo, la regola non puo' fallire mai e resta
     * una protezione solo sulla carta — finche' qualcuno si trova chiuso fuori
     * dal proprio account il giorno dopo l'iscrizione.
     */
    #[Test]
    public function it_refuses_a_mismatched_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Distratto', 'email' => 'distratto@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'distratto',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD.'-diversa',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(
            User::withoutGlobalScopes()->where('email', 'distratto@esempio.it')->first(),
        );
    }

    /** Una password lunga e semplice passa: e' esattamente cio' che si consiglia. */
    #[Test]
    public function it_accepts_a_long_simple_passphrase(): void
    {
        $frase = 'cavallo divano lampada 7';

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Saggia', 'email' => 'saggia@esempio.it',
            'age_confirmed' => true, 'terms_accepted' => true,
            'username' => 'saggia',
            'password' => $frase, 'password_confirmation' => $frase,
        ])->assertCreated();
    }

    /*
     * ⛔ Qui c'era `it_refuses_registration_on_a_suspended_gym`: senza codice
     * non ci si iscrive più «in» una palestra (F11).
     */

    // ───────────────────────── accesso ─────────────────────────

    #[Test]
    public function it_logs_in_with_valid_credentials(): void
    {
        $this->iscritto('anna@alfa.test');

        $this->postJson('/api/v1/auth/login', [
            'login' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('branding.slug', 'alfa');
    }

    /**
     * 🚨 **La forma della risposta è `{token, data, branding}`, non un
     * inviluppo `{data: …}`.**
     *
     * Questo test esiste per un difetto costato un pomeriggio: il client
     * dell'app srotolava `data` su **ogni** risposta, quindi qui riceveva il
     * solo utente e perdeva il token. Il login non funzionava per nessuno, e il
     * messaggio mostrato all'utente diceva «email o password non corretti» —
     * cioè dava la colpa a lui di un difetto nostro.
     *
     * Fissare la forma qui non impedisce a un client di sbagliare, ma se un
     * giorno qualcuno la cambia questo test lo dice subito invece di lasciarlo
     * scoprire dall'app.
     */
    #[Test]
    public function the_login_response_is_not_an_envelope(): void
    {
        $this->iscritto('anna@alfa.test');

        $r = $this->postJson('/api/v1/auth/login', [
            'login' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertOk();

        $corpo = $r->json();

        $this->assertSame(['token', 'data', 'branding'], array_keys($corpo));
        $this->assertIsString($corpo['token']);
        $this->assertArrayHasKey('email', $corpo['data']);
    }

    /**
     * Il campo si chiama `login`, ma `email` resta accettato.
     *
     * Le versioni dell'app già installate sui telefoni lo mandano ancora così:
     * un rilascio del backend non deve buttarle fuori.
     */
    #[Test]
    public function it_still_accepts_the_old_email_field(): void
    {
        $this->iscritto('anna@alfa.test');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function it_gives_the_same_error_for_wrong_password_and_unknown_email(): void
    {
        $this->iscritto('anna@alfa.test');

        $passwordErrata = $this->postJson('/api/v1/auth/login', [
            'email' => 'anna@alfa.test', 'password' => 'wrong-value',
        ]);
        $emailIgnota = $this->postJson('/api/v1/auth/login', [
            'email' => 'nessuno@alfa.test', 'password' => 'qualunque',
        ]);

        $passwordErrata->assertStatus(422);
        $emailIgnota->assertStatus(422);

        $this->assertSame(
            $passwordErrata->json('errors'),
            $emailIgnota->json('errors'),
            'I due errori sono distinguibili: si può scoprire chi è iscritto provando indirizzi.',
        );
    }

    #[Test]
    public function it_logs_in_with_a_username(): void
    {
        $u = $this->iscritto('anna@alfa.test');
        $u->forceFill(['username' => 'anna.alfa'])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => ' Anna.Alfa ', 'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('data.email', 'anna@alfa.test');
    }

    /*
     * ⛔ Qui c'era `a_username_from_another_gym_does_not_work`: la ricerca non
     * è più limitata alla palestra di un codice (F11), e il nome utente, unico
     * su tutta la piattaforma, fa entrare nel suo account ovunque sia.
     */

    #[Test]
    public function it_refuses_login_for_an_inactive_user(): void
    {
        $u = $this->iscritto('anna@alfa.test');
        $u->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
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
    public function una_palestra_sospesa_non_chiude_fuori_l_iscritto(): void
    {
        /*
         * 🆕 🔑 **Questo test diceva il contrario fino al 26/09/2026**, e si
         * chiamava `it_blocks_the_app_when_the_gym_is_suspended`.
         *
         * 📌 Il committente, provando l'app: *«non ha senso che mi dica che la
         * palestra è sospesa e non mi faccia accedere… il mio account è e sarà
         * sempre mio: se il mio abbonamento lo paga qualcun altro lo
         * ringrazio, ma non mi può togliere l'accesso»*.
         *
         * 🚨 Un test verde non vuol dire che la regola sia giusta: questo era
         * verde da sempre, e proteggeva un comportamento indifendibile.
         * ⚖️ Allenamenti e diario sono dati personali dell'iscritto, e accesso
         * e portabilità (artt. 15 e 20 GDPR) non dipendono da chi paga.
         *
         * 💡 Ora lo stato della palestra decide **cosa funziona**, non **se si
         * entra**: vedi `EnsureTenantActive`, rimasto solo sulle rotte che la
         * palestra finanzia.
         */
        $u = $this->iscritto('anna@alfa.test');

        $this->actingAs($u, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

        $this->alfa->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($u->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    /**
     * ⚠️ **Ma quello che paga la palestra resta chiuso.** 💡 Senza questo, il
     * rimedio sarebbe diventato «la sospensione non ha alcun effetto», e la
     * palestra continuerebbe a ricevere gratis quello per cui non paga.
     */
    #[Test]
    public function ma_le_funzioni_che_paga_la_palestra_si_fermano(): void
    {
        $u = $this->iscritto('anna@alfa.test');

        $this->alfa->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($u->fresh(), 'sanctum')
            ->getJson('/api/v1/trainer/members')
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

        $this->assertNotNull(PersonalAccessToken::find($tokenDiBruno->id));
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
