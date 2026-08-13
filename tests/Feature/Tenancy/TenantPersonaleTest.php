<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\TenantResource;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il tenant personale — F1 della Parte B, 13/08/2026.
 *
 * ── 🚨 Cosa sta difendendo questo file ──────────────────────────────────────
 *
 * `users.tenant_id = null` significa **super admin**: `ResolveTenant` non
 * imposta il contesto e `TenantScope` non filtra niente. Finché in quello stato
 * c'è solo il super admin è corretto. Il giorno in cui un utente gratuito
 * nascesse così, la stessa riga diventerebbe **un'elevazione di privilegi per
 * chiunque si registri gratis**.
 *
 * La via d'uscita (D1) è che ogni persona senza palestra abbia un tenant suo. Da
 * qui in poi, quindi, la domanda che questi test devono continuare a fare per
 * sempre è una sola: **un utente gratuito vede qualcosa che non è suo?**
 *
 * ⚠️ E la seconda, meno ovvia e altrettanto grave: **si riesce a entrare in un
 * tenant personale dall'esterno?** Ogni tenant ha un `join_code` perché la
 * colonna è `unique` e NOT NULL — anche quelli personali, che non hanno nessuno
 * da invitare. Un codice che funziona è una porta sull'account di una persona.
 */
class TenantPersonaleTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function crea(): CreaTenantPersonale
    {
        return app(CreaTenantPersonale::class);
    }

    // ───────────────────────── F1.3 — come nasce ─────────────────────────

    #[Test]
    public function it_creates_a_personal_tenant_with_its_user_inside(): void
    {
        $utente = ($this->crea())('Mario Rossi', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $tenant = $utente->tenant;

        $this->assertNotNull($tenant);
        $this->assertSame(TenantKind::Personal, $tenant->kind);
        $this->assertTrue($tenant->ePersonale());
        $this->assertSame(TenantStatus::Active, $tenant->status);

        // 🚨 La riga da cui dipende tutto: senza, questa persona sarebbe un
        // super admin di fatto.
        $this->assertNotNull($utente->tenant_id);
        $this->assertSame($tenant->id, $utente->tenant_id);
    }

    /**
     * ⚠️ I ruoli spatie devono nascere **dentro** il tenant, o `assignRole()`
     * non troverebbe niente: la modalità teams cerca il ruolo nel tenant
     * corrente.
     */
    #[Test]
    public function it_gives_the_new_user_a_role_that_exists_in_their_own_tenant(): void
    {
        $utente = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $ruoli = $this->ctx()->runAs($utente->tenant, function () use ($utente): array {
            $fresco = User::find($utente->id);
            $fresco->unsetRelation('roles');

            return $fresco->getRoleNames()->all();
        });

        $this->assertSame([UserRole::Member->value], $ruoli);
    }

    /**
     * 🚨 Lo `slug` **esce dal server**: `branding()` lo pubblica e
     * `/branding/lookup` è un endpoint pubblico. Derivarlo dal nome renderebbe
     * quell'endpoint un modo per verificare se una certa persona ha un account.
     */
    #[Test]
    public function it_does_not_derive_the_slug_from_the_person_name(): void
    {
        $utente = ($this->crea())('Mario Rossi', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $slug = $utente->tenant->slug;

        $this->assertStringNotContainsStringIgnoringCase('mario', $slug);
        $this->assertStringNotContainsStringIgnoringCase('rossi', $slug);
        $this->assertStringStartsWith('p-', $slug);
    }

    /** Due persone con lo **stesso nome** devono poter esistere entrambe. */
    #[Test]
    public function it_lets_two_people_with_the_same_name_coexist(): void
    {
        $a = ($this->crea())('Mario Rossi', 'mario1@esempio.test', ['password' => self::FAKE_PASSWORD]);
        $b = ($this->crea())('Mario Rossi', 'mario2@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->assertNotSame($a->tenant_id, $b->tenant_id);
        $this->assertNotSame($a->tenant->slug, $b->tenant->slug);
        $this->assertNotSame($a->tenant->join_code, $b->tenant->join_code);
    }

    // ───────────────────── 🚨 F1.6 — non vede niente ─────────────────────

    /**
     * **Il test che dà senso a tutta la fase.**
     *
     * Una palestra piena di roba, e accanto una persona che si è registrata da
     * sola. La persona non deve vedere **niente** della palestra: non un utente,
     * non un esercizio, non una riga.
     */
    #[Test]
    public function a_free_user_sees_nothing_of_any_gym(): void
    {
        $palestra = $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'admin@alfa.test');
        $this->creaUtente($palestra, UserRole::Trainer, 'trainer@alfa.test');
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $this->ctx()->runAs($palestra, fn () => Exercise::create([
            'name' => 'Panca della palestra', 'muscle_group' => 'chest', 'tenant_id' => $palestra->id,
        ]));

        $libero = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->ctx()->runAs($libero->tenant, function () use ($libero): void {
            // Vede sé stesso, e nessuno dei tre della palestra.
            $this->assertSame(1, User::count());
            $this->assertSame($libero->id, User::first()->id);

            // ⚠️ `exercises` usa `BelongsToTenantOrGlobal`: le righe globali si
            // vedono di proposito (la libreria di base è condivisa). Quello che
            // NON si deve vedere è l'esercizio **della palestra**.
            $this->assertSame(
                0,
                Exercise::whereNotNull('tenant_id')->count(),
                'Un utente gratuito sta vedendo un esercizio di una palestra.',
            );
        });
    }

    /**
     * 🚨 E nemmeno **gli altri utenti gratuiti**.
     *
     * È il motivo per cui l'alternativa del «tenant zero» (una riga di sistema
     * che raccoglie tutti i gratuiti) è stata scartata: `TenantScope` non li
     * separerebbe fra loro, e ogni gratuito vedrebbe i dati di ogni altro.
     */
    #[Test]
    public function two_free_users_do_not_see_each_other(): void
    {
        $anna = ($this->crea())('Anna', 'anna@esempio.test', ['password' => self::FAKE_PASSWORD]);
        $bruno = ($this->crea())('Bruno', 'bruno@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->assertNotSame($anna->tenant_id, $bruno->tenant_id);

        $this->ctx()->runAs($anna->tenant, function () use ($bruno): void {
            $this->assertSame(1, User::count());
            $this->assertNull(User::where('email', $bruno->email)->first());
        });
    }

    /** Il contesto si risolve davvero dall'utente, passando dall'HTTP. */
    #[Test]
    public function the_api_answers_a_free_user_with_their_own_data_only(): void
    {
        $palestra = $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $libero = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->actingAs($libero->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'mario@esempio.test');
    }

    // ────────────────── 🚨 La porta che non deve aprirsi ──────────────────

    /**
     * Il `join_code` di un tenant personale **non fa entrare nessuno**.
     *
     * ⚠️ Senza questo rifiuto, chi presentasse quel codice si registrerebbe
     * **dentro** lo spazio di un'altra persona, e da lì `TenantScope` gli
     * mostrerebbe diario, allenamenti e conversazioni di quella persona — non
     * per un difetto dello scoping, ma perché lo scoping avrebbe fatto
     * esattamente il suo lavoro su un tenant in cui non doveva entrare.
     */
    #[Test]
    public function the_join_code_of_a_personal_tenant_does_not_let_anyone_register(): void
    {
        $libero = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->postJson('/api/v1/auth/register', [
            'join_code' => $libero->tenant->join_code,
            'age_confirmed' => true, 'terms_accepted' => true,
            'name' => 'Intruso', 'email' => 'intruso@esempio.test', 'username' => 'intruso',
            'password' => self::FAKE_PASSWORD, 'password_confirmation' => self::FAKE_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_code');

        $this->assertSame(
            1,
            User::withoutGlobalScopes()->where('tenant_id', $libero->tenant_id)->count(),
            'Qualcuno è entrato in un tenant personale.',
        );
    }

    /** E nemmeno accedere: la stessa porta, l'altro verso. */
    #[Test]
    public function the_join_code_of_a_personal_tenant_does_not_let_anyone_log_in(): void
    {
        $libero = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->postJson('/api/v1/auth/login', [
            'join_code' => $libero->tenant->join_code,
            'login' => 'mario@esempio.test',
            'password' => self::FAKE_PASSWORD,
        ])->assertStatus(422);
    }

    /**
     * 🚨 E il branding pubblico non deve nemmeno ammettere che esista.
     *
     * ⚠️ Qui la posta è più alta che altrove: l'endpoint è **senza
     * autenticazione** e `branding()` restituisce `name`, che per un tenant
     * personale è **il nome e cognome della persona**.
     *
     * 💡 La risposta è identica a quella di un codice inesistente: dire «questo
     * è personale» confermerebbe che quel codice esiste.
     */
    #[Test]
    public function the_public_branding_lookup_ignores_personal_tenants(): void
    {
        $libero = ($this->crea())('Mario Rossi', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $risposta = $this->getJson('/api/v1/branding/lookup?code='.$libero->tenant->join_code);

        $risposta->assertNotFound()->assertJsonPath('code', 'gym_not_found');

        $this->assertStringNotContainsString('Mario', $risposta->getContent());
    }

    // ─────────────────── F1.4 — invisibili al pannello ───────────────────

    /**
     * I conteggi commerciali devono contare **le palestre**.
     *
     * ⚠️ Non è cosmetica: dopo la Parte B `tenants` cresce come `users`, e un
     * `Tenant::count()` nudo risponderebbe «abbiamo diecimila palestre» il
     * giorno in cui ne abbiamo dodici.
     */
    #[Test]
    public function personal_tenants_stay_out_of_the_gym_counts(): void
    {
        $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $this->creaPalestra('Palestra Beta', 'beta', 'BETA2345');

        ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);
        ($this->crea())('Anna', 'anna@esempio.test', ['password' => self::FAKE_PASSWORD]);
        ($this->crea())('Bruno', 'bruno@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $this->assertSame(5, Tenant::query()->count(), 'Il totale grezzo: cinque righe in tabella.');
        $this->assertSame(2, Tenant::query()->palestre()->count(), 'Le palestre vere sono due.');
        $this->assertSame(3, Tenant::query()->personali()->count());
    }

    /**
     * 🚨 L'elenco del pannello `/god` filtra da `getEloquentQuery()`, che è la
     * fonte di **tutte** le pagine della risorsa — anche di quella di modifica
     * raggiunta con un URL diretto.
     */
    #[Test]
    public function the_god_panel_lists_only_gyms(): void
    {
        $palestra = $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $libero = ($this->crea())('Mario', 'mario@esempio.test', ['password' => self::FAKE_PASSWORD]);

        $ids = TenantResource::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains($palestra->id, $ids);
        $this->assertNotContains($libero->tenant_id, $ids);
    }

    // ─────────────────────── Le righe già esistenti ───────────────────────

    /**
     * ⚠️ Il `default('gym')` della migrazione **è** il backfill: ogni riga già
     * presente in `tenants` è una palestra vera, e deve restare tale.
     */
    #[Test]
    public function existing_tenants_are_gyms(): void
    {
        $palestra = $this->creaPalestra();

        $this->assertSame(TenantKind::Gym, $palestra->kind);
        $this->assertFalse($palestra->ePersonale());
    }
}
