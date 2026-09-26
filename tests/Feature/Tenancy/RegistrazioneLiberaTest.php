<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Iscriversi e rientrare **senza una palestra** — F3 della Parte B, 13/08/2026.
 *
 * 🚨 **È la fase che fa nascere davvero il primo utente gratuito.** Fino a F2
 * `CreaTenantPersonale` era provato ma non lo chiamava nessuno in produzione.
 *
 * ── ⚠️ Cosa è stato aggiunto al piano, e perché ─────────────────────────────
 *
 * `plan_parte_b.md` §5.3 descriveva solo la **registrazione**. Ma `LoginRequest`
 * pretendeva il `join_code`: fatta com'era scritta, F3 avrebbe creato persone in
 * grado di iscriversi e **non di rientrare**. Una porta d'ingresso senza
 * serratura di ritorno non è metà funzione — è una funzione che non c'è.
 */
class RegistrazioneLiberaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private const MODULO = [
        'name' => 'Mario Rossi',
        'email' => 'mario@esempio.test',
        'username' => 'mario.rossi',
        'age_confirmed' => true,
        'terms_accepted' => true,
    ];

    /** @param array<string, mixed> $extra */
    private function modulo(array $extra = []): array
    {
        return array_merge(self::MODULO, [
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
        ], $extra);
    }

    // ───────────────────── F3.1 / F3.2 — l'iscrizione ─────────────────────

    #[Test]
    public function someone_can_sign_up_without_a_gym_code(): void
    {
        $r = $this->postJson('/api/v1/auth/register', $this->modulo());

        $r->assertCreated()
            ->assertJsonPath('data.email', 'mario@esempio.test')
            ->assertJsonPath('data.roles', [UserRole::FreeUser->value])
            ->assertJsonStructure(['token', 'data', 'branding']);

        $utente = User::withoutGlobalScopes()->where('email', 'mario@esempio.test')->firstOrFail();

        // 🚨 La riga da cui dipende tutto: senza tenant sarebbe un super admin
        // di fatto, e vedrebbe i dati di ogni palestra.
        $this->assertNotNull($utente->tenant_id);
        $this->assertSame(TenantKind::Personal, $utente->tenant->kind);
        $this->assertTrue($utente->isFreeUser());
    }

    /** ⚠️ Il campo vuoto, non assente: è così che lo manda l'app già installata. */
    #[Test]
    public function an_empty_code_is_treated_as_no_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo(['join_code' => '   ']))
            ->assertCreated();

        $this->assertTrue(
            User::withoutGlobalScopes()->where('email', 'mario@esempio.test')->firstOrFail()->isFreeUser(),
        );
    }

    /**
     * ⛔ **Il codice palestra non esiste più** — F11, 24/09/2026.
     *
     * 🚨 Ma chi ne manda uno pensa di entrare in una palestra: ignorarlo lo
     * farebbe nascere **fuori** senza dirglielo. Si rifiuta, e nessun account
     * nasce. (Prima di F11 qui c'erano «il codice scritto male» e «col codice
     * si entra nella palestra».)
     */
    #[Test]
    public function a_gym_code_is_refused_not_turned_into_a_free_signup(): void
    {
        $this->creaPalestra('Alfa', 'alfa');

        $this->postJson('/api/v1/auth/register', $this->modulo(['join_code' => 'ALFA2345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_code');

        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    // ───────────────────── 🚨 F3.3 — lo sbarramento 18+ ─────────────────────

    /**
     * 🚨 **Il test che dà il nome a questa sezione.**
     *
     * Rendere facoltativo il `join_code` e portarsi via anche i consensi sarebbe
     * stato facilissimo: sono nello stesso array di regole, a tre righe di
     * distanza. Questo test esiste perché quella distanza è di tre righe.
     */
    #[Test]
    public function the_age_gate_survives_the_optional_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo(['age_confirmed' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('age_confirmed');

        $this->postJson('/api/v1/auth/register', $this->modulo(['terms_accepted' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');

        $this->postJson('/api/v1/auth/register', array_diff_key($this->modulo(), [
            'age_confirmed' => null, 'terms_accepted' => null,
        ]))->assertStatus(422);

        $this->assertSame(0, User::withoutGlobalScopes()->count(), 'Qualcuno è entrato senza dichiarare l\'età.');
    }

    /** E la dichiarazione si conserva con il suo istante — art. 7(1). */
    #[Test]
    public function the_declaration_is_stamped_with_its_moment(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'mario@esempio.test')->firstOrFail();

        $this->assertNotNull($utente->age_confirmed_at);
        $this->assertNotNull($utente->terms_accepted_at);
    }

    // ───────────────── 🚨 L'email, che qui vuol dire un'altra cosa ─────────────────

    /**
     * **Due account personali con lo stesso indirizzo: no.**
     *
     * ⚠️ Senza questo controllo il guasto sarebbe silenzioso nel modo peggiore:
     * alla seconda iscrizione la persona crederebbe di essere rientrata nel
     * proprio account e lo troverebbe **vuoto**, con dentro il nulla al posto
     * del suo diario. Il vincolo `UNIQUE(tenant_id, email)` non aiuta, perché
     * ogni account personale ha un tenant tutto suo.
     */
    #[Test]
    public function the_same_address_cannot_open_two_personal_accounts(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->modulo(['username' => 'mario.due']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, Tenant::query()->personali()->count());
    }

    /**
     * ⚠️ Ma un iscritto a una palestra **può** farsi anche un account personale.
     *
     * Sono due cose diverse, e il codice palestra dice quale si vuole. Vietarlo
     * significherebbe che iscriversi in palestra preclude per sempre l'uso
     * dell'app per conto proprio.
     */
    #[Test]
    public function a_gym_member_can_also_have_a_personal_account(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa');
        $this->creaUtente($palestra, UserRole::Member, 'mario@esempio.test');

        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $this->assertSame(
            2,
            User::withoutGlobalScopes()->where('email', 'mario@esempio.test')->count(),
        );
    }

    // ───────────────────── 🚨 E deve poter rientrare ─────────────────────

    #[Test]
    public function a_free_user_can_log_back_in_without_a_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'mario@esempio.test',
            'password' => self::FAKE_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'mario@esempio.test')
            ->assertJsonStructure(['token']);
    }

    /** Anche con il nome utente, che è unico su tutta la piattaforma. */
    #[Test]
    public function the_username_works_too(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'mario.rossi',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk();
    }

    /**
     * ══ 🆕 RISCRITTO IL 14/09/2026 — LA REGOLA È CAMBIATA, IL MOTIVO NO ═══════
     *
     * ⛔ **Prima questo test diceva: «l'accesso senza codice non deve trovare
     * gli utenti delle palestre», e pretendeva 422 anche a palestra attiva.**
     *
     * 🚨 Quella regola chiudeva fuori **chiunque fosse entrato in una palestra
     * dall'app**: `Entra in una palestra` sposta l'account nel tenant della
     * palestra, e da 3b-J.1 la schermata di accesso il codice non lo chiede più.
     * Password giusta, «Credenziali non valide», nessuna via d'uscita.
     *
     * 💡 **Il motivo per cui la regola esisteva è invece sacrosanto**, ed è
     * questo che il test difende adesso: senza codice non si deve saltare **il
     * controllo che la palestra sia attiva**. Quel controllo ora si fa dentro
     * `accediSenzaPalestra()`, dopo aver verificato la password.
     *
     * ⚖️ E la risposta è migliore di un 422: chi sta in una palestra sospesa
     * riceve `403 tenant_inactive`, cioè la schermata che glielo dice, invece di
     * riprovare all'infinito una password che è giusta.
     */
    #[Test]
    public function una_palestra_sospesa_non_impedisce_di_entrare(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa');
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test', [
            'username' => 'iscritto.alfa',
        ]);

        // ✅ Palestra attiva: si entra, che è la cosa che prima non si poteva.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'iscritto@alfa.test',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk();

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        /*
         * 🆕 🔑 **Sospesa: si entra lo stesso** — 26/09/2026.
         *
         * 🚨 Qui si pretendeva un 403. 📌 Il committente: *«il mio account
         * è e sarà sempre mio: se il mio abbonamento lo paga qualcun altro
         * lo ringrazio, ma non mi può togliere l'accesso»*.
         */
        $this->postJson('/api/v1/auth/login', [
            'login' => 'iscritto@alfa.test',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk();

        // ⚠️ E vale anche per il nome utente: è la stessa porta.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'iscritto.alfa',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk();
    }

    /** Una password sbagliata resta indistinguibile da un indirizzo inesistente. */
    #[Test]
    public function a_wrong_password_says_nothing_more_than_a_missing_account(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $sbagliata = $this->postJson('/api/v1/auth/login', [
            'login' => 'mario@esempio.test', 'password' => 'Qualcosa9999',
        ]);

        $inesistente = $this->postJson('/api/v1/auth/login', [
            'login' => 'nessuno@esempio.test', 'password' => 'Qualcosa9999',
        ]);

        $sbagliata->assertStatus(422);
        $inesistente->assertStatus(422);
        $this->assertSame($sbagliata->json('message'), $inesistente->json('message'));
    }

    #[Test]
    public function a_deactivated_free_user_cannot_get_back_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        User::withoutGlobalScopes()->where('email', 'mario@esempio.test')->firstOrFail()
            ->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'mario@esempio.test', 'password' => self::FAKE_PASSWORD,
        ])->assertStatus(422);
    }

    // ───────────────── Il branding di chi non ha una palestra ─────────────────

    /**
     * ⚠️ `branding.name` è `null`, non il nome della persona.
     *
     * Per una palestra `name` è l'insegna. Per un tenant personale è **il nome e
     * cognome di chi ci abita**: mostrarlo in cima alla schermata vorrebbe dire
     * scrivere «Mario Rossi» dove ci si aspetta la propria palestra, come se ci
     * si fosse iscritti a sé stessi.
     */
    #[Test]
    public function a_personal_tenant_has_no_name_to_show(): void
    {
        $r = $this->postJson('/api/v1/auth/register', $this->modulo())->assertCreated();

        $this->assertNull($r->json('branding.name'));

        /*
         * 🆕 ⛔ **Qui si pretendeva il contrario fino al 18/09**:
         * `assertNotNull(colors.primary)` — *«I colori servono comunque: l'app
         * si deve vestire»*.
         *
         * 📌 Il committente, regola 8.1.3: *«un free trainer non crea un
         * "vestito" per l'app dei suoi allievi»*. 🚨 E i colori di un tenant
         * personale erano **proprio** quello: da quando gli allievi di un trainer
         * vivono nel suo spazio (AH.1), passavano logo e colori del trainer.
         *
         * 💡 **L'app si veste lo stesso**, ed è per questo che il vecchio motivo
         * non regge più: ogni colore ha il suo `?? fallback…` in
         * `GymBranding.fromJson`, quindi un `null` diventa il marchio di serie.
         * ⚠️ E il colore d'accento che una persona sceglie per sé non passa di
         * qui: sta in `LocalCache`, sul telefono.
         */
        $this->assertNull($r->json('branding.colors.primary'), 'uno spazio personale veste lapp');
        $this->assertNull($r->json('branding.logo_url'));
    }
}
