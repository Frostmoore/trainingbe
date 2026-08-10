<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SocialProvider;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\SocialIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Services\Auth\Social\VerifiedSocialUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * C17 — accesso con Google e Apple.
 *
 * 🚨 Qui si prova la **logica di collegamento**, che e' dove stanno le
 * decisioni: chi entra in quale account, con quale palestra, e soprattutto chi
 * NON deve entrare. La verifica crittografica del token ha un test suo
 * (`JwksSocialTokenVerifierTest`): mescolarle vorrebbe dire, per ogni caso
 * d'uso, dover firmare un token vero — e finire a provare OpenSSL invece del
 * proprio codice.
 */
class SocialAuthApiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');

        config()->set('services.social.google.client_ids', 'app-android.apps.googleusercontent.com');
        config()->set('services.social.apple.client_ids', 'it.riccardoronconi.training');
    }

    /**
     * Sostituisce il verificatore con uno che restituisce quello che diciamo.
     *
     * ⚠️ **Indicizzato per fornitore, e non e' un dettaglio.** Un doppio che
     * ignora il `provider` e restituisce sempre l'ultima identita' impostata fa
     * passare i test sbagliati: nel caso «la stessa persona collega Google e
     * Apple» rispondeva con l'identita' Google anche alla richiesta Apple, e il
     * test falliva su un vincolo del database invece che sulla propria logica.
     *
     * Ogni chiamata **sostituisce** la mappa: per provare un utente con due
     * fornitori collegati si passano entrambe le identita' insieme, non una
     * chiamata dopo l'altra.
     */
    private function verificaRestituisce(VerifiedSocialUser ...$identita): void
    {
        $mappa = [];

        foreach ($identita as $i) {
            $mappa[$i->provider->value] = $i;
        }

        $this->app->instance(SocialTokenVerifier::class, new class($mappa) implements SocialTokenVerifier
        {
            /** @param array<string, VerifiedSocialUser> $mappa */
            public function __construct(private readonly array $mappa) {}

            public function verify(SocialProvider $provider, string $idToken): VerifiedSocialUser
            {
                return $this->mappa[$provider->value]
                    ?? throw new InvalidSocialTokenException("Nessuna identita' finta per {$provider->value}.");
            }

            public function configured(SocialProvider $provider): bool
            {
                return true;
            }
        });
    }


    private function verificaFallisce(): void
    {
        $this->app->instance(SocialTokenVerifier::class, new class implements SocialTokenVerifier
        {
            public function verify(SocialProvider $provider, string $idToken): VerifiedSocialUser
            {
                throw new InvalidSocialTokenException('Firma non valida.');
            }

            public function configured(SocialProvider $provider): bool
            {
                return true;
            }
        });
    }

    /** @param array<string, mixed> $extra */
    private function accedi(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/social', array_merge([
            'provider' => 'google',
            'id_token' => 'token-finto',
        ], $extra));
    }

    // ───────────────────────── primo accesso ─────────────────────────

    #[Test]
    public function il_primo_accesso_crea_l_iscritto_e_restituisce_un_token(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
            name: 'Nuovo Iscritto',
        ));

        $r = $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        $this->assertIsString($r->json('token'));
        $this->assertSame('nuovo@esempio.it', $r->json('data.email'));
        $this->assertSame(['member'], $r->json('data.roles'));

        $utente = User::withoutGlobalScopes()->where('email', 'nuovo@esempio.it')->first();

        $this->assertNotNull($utente);
        $this->assertSame($this->alfa->id, $utente->tenant_id);
        $this->assertNotNull($utente->username);
    }

    /**
     * 🚨 Senza codice palestra non si puo' sapere DOVE creare l'account.
     *
     * L'email e' unica per palestra, non sulla piattaforma: la stessa persona
     * puo' essere iscritta a due palestre, ed e' una situazione prevista.
     */
    #[Test]
    public function al_primo_accesso_senza_codice_palestra_lo_chiede_in_modo_riconoscibile(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()
            ->assertStatus(422)
            ->assertJsonPath('code', 'join_code_required')
            ->assertJsonValidationErrors('join_code');

        $this->assertSame(0, SocialIdentity::count());
    }

    #[Test]
    public function con_un_codice_palestra_inesistente_non_crea_niente(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'NONESIST'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_code');

        $this->assertSame(0, SocialIdentity::count());
    }

    // ───────────────────────── collegamento ─────────────────────────

    /**
     * 🚨 **La regola di sicurezza piu' importante del file.**
     *
     * Con un'email NON verificata non si entra in un account esistente: se
     * bastasse un'email dichiarata, chiunque riesca a farsi emettere un token
     * con l'indirizzo di un altro entrerebbe nel suo account.
     *
     * Si rifiuta e basta — niente account secondo, niente collegamento — e con
     * il messaggio generico di sempre, che non conferma nemmeno che
     * quell'indirizzo sia iscritto qui.
     */
    #[Test]
    public function un_email_non_verificata_non_apre_un_account_esistente(): void
    {
        $vittima = $this->creaUtente($this->alfa, UserRole::Member, 'vittima@alfa.test');

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'attaccante-sub',
            email: 'vittima@alfa.test',
            emailVerified: false,
        ));

        $r = $this->accedi(['join_code' => 'ALFA2345'])->assertStatus(422);

        $this->assertSame(
            0,
            SocialIdentity::count(),
            'Nessuna identita\' collegata: ne\' alla vittima, ne\' a un account nuovo.',
        );

        $this->assertSame(
            1,
            User::withoutGlobalScopes()->where('email', 'vittima@alfa.test')->count(),
        );

        // ⚠️ La risposta non conferma che quell'indirizzo esista qui: sarebbe
        // un modo per scoprire chi frequenta quale palestra.
        $this->assertStringNotContainsStringIgnoringCase('già', (string) $r->json('message'));

        $vittima->refresh();
        $this->assertTrue($vittima->is_active);
    }

    /**
     * Un'email non verificata che NON collide con nessuno crea un account
     * nuovo: non c'e' niente da rubare, e bloccare sarebbe gratuito.
     */
    #[Test]
    public function un_email_non_verificata_senza_collisioni_crea_un_account_nuovo(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'nessuna-collisione',
            email: 'libero@esempio.it',
            emailVerified: false,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        $this->assertSame(1, SocialIdentity::where('provider_user_id', 'nessuna-collisione')->count());
    }

    #[Test]
    public function un_email_verificata_collega_l_account_esistente_invece_di_duplicarlo(): void
    {
        $esistente = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-mario',
            email: 'mario@alfa.test',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertOk();

        $this->assertSame(
            $esistente->getKey(),
            SocialIdentity::where('provider_user_id', 'google-sub-mario')->value('user_id'),
        );

        $this->assertSame(
            1,
            User::withoutGlobalScopes()->where('email', 'mario@alfa.test')->count(),
        );
    }

    #[Test]
    public function la_stessa_persona_puo_collegare_sia_google_sia_apple(): void
    {
        $utente = $this->creaUtente($this->alfa, UserRole::Member, 'due@alfa.test');

        $this->verificaRestituisce(
            new VerifiedSocialUser(
                provider: SocialProvider::Google,
                providerUserId: 'g-1',
                email: 'due@alfa.test',
                emailVerified: true,
            ),
            new VerifiedSocialUser(
                provider: SocialProvider::Apple,
                providerUserId: 'a-1',
                email: 'due@alfa.test',
                emailVerified: true,
            ),
        );

        $this->accedi(['join_code' => 'ALFA2345'])->assertOk();
        $this->accedi(['provider' => 'apple', 'join_code' => 'ALFA2345'])->assertOk();

        $this->assertSame(2, SocialIdentity::where('user_id', $utente->getKey())->count());
    }

    // ───────────────────────── accessi successivi ─────────────────────────

    #[Test]
    public function dal_secondo_accesso_il_codice_palestra_non_serve_piu(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'tornante@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        // Senza `join_code`: l'identita' e' gia' nota.
        $this->accedi()->assertOk()->assertJsonPath('data.email', 'tornante@esempio.it');

        $this->assertSame(1, SocialIdentity::count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'tornante@esempio.it')->count());
    }

    /**
     * 🚨 Il codice di un'altra palestra NON sposta nessuno.
     *
     * La palestra si legge da `user->tenant_id` (ADR-04). Se il `join_code`
     * potesse cambiarla, presentare il codice altrui al secondo accesso
     * porterebbe dentro una palestra che non e' la propria.
     */
    #[Test]
    public function il_codice_di_un_altra_palestra_non_sposta_l_iscritto(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'fermo@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();
        $this->accedi(['join_code' => 'BETA2345'])->assertOk();

        $utente = User::withoutGlobalScopes()->where('email', 'fermo@esempio.it')->first();

        $this->assertSame($this->alfa->id, $utente->tenant_id);
        $this->assertNotSame($beta->id, $utente->tenant_id);
    }

    /**
     * La palestra sospesa da' `tenant_inactive`, non un errore di credenziali:
     * l'app deve portare alla schermata dedicata, non al login — dove si
     * riproverebbe all'infinito con credenziali giuste.
     */
    #[Test]
    public function una_palestra_sospesa_da_tenant_inactive(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'sospeso@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        $this->alfa->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->accedi()
            ->assertStatus(403)
            ->assertJsonPath('code', 'tenant_inactive');
    }

    #[Test]
    public function un_iscritto_disattivato_non_entra(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'spento@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        User::withoutGlobalScopes()
            ->where('email', 'spento@esempio.it')
            ->update(['is_active' => false]);

        $this->accedi()->assertStatus(422);
    }

    // ───────────────────────── rifiuti ─────────────────────────

    /**
     * 🚨 Il motivo vero resta nei log.
     *
     * «Firma non valida» e «token scaduto» sono informazioni utili a chi sta
     * provando ad entrare: all'app va la stessa frase generica di una password
     * sbagliata.
     */
    #[Test]
    public function un_token_non_valido_non_dice_perche(): void
    {
        $this->verificaFallisce();

        $r = $this->accedi(['join_code' => 'ALFA2345'])->assertStatus(422);

        $this->assertStringNotContainsStringIgnoringCase('firma', json_encode($r->json()));
        $this->assertSame(0, SocialIdentity::count());
    }

    #[Test]
    public function un_fornitore_sconosciuto_si_rifiuta(): void
    {
        $this->accedi(['provider' => 'facebook', 'join_code' => 'ALFA2345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');
    }

    /**
     * ⏸️ Finche' non ci sono le credenziali: 501, non un errore di validazione.
     *
     * Non e' l'utente ad aver sbagliato qualcosa, e' il server a non avere
     * ancora questa funzione. Un 422 manderebbe a cercare l'errore nei dati.
     */
    #[Test]
    public function senza_credenziali_configurate_risponde_non_implementato(): void
    {
        config()->set('services.social.google.client_ids', '');

        $this->accedi(['join_code' => 'ALFA2345'])
            ->assertStatus(501)
            ->assertJsonPath('code', 'social_not_configured');
    }

    /**
     * La forma della risposta e' `{token, data, branding}`, non un inviluppo:
     * e' lo stesso contratto di `/auth/login`, e il client conta su questo.
     */
    #[Test]
    public function la_risposta_ha_la_stessa_forma_del_login_normale(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'forma@esempio.it',
            emailVerified: true,
        ));

        $r = $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        $this->assertArrayHasKey('token', $r->json());
        $this->assertArrayHasKey('data', $r->json());
        $this->assertArrayHasKey('branding', $r->json());
        $this->assertSame('Alfa', $r->json('branding.name'));
    }

    /**
     * ⚠️ Apple con «Nascondi la mia email» non manda un indirizzo utilizzabile
     * e manda il nome **solo la prima volta**. L'account deve nascere comunque.
     */
    #[Test]
    public function apple_senza_email_crea_comunque_un_account_utilizzabile(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Apple,
            providerUserId: 'apple-sub-anonimo',
            email: null,
            emailVerified: true,
            name: 'Anonimo Mela',
        ));

        $this->accedi(['provider' => 'apple', 'join_code' => 'ALFA2345'])->assertCreated();

        $identita = SocialIdentity::where('provider_user_id', 'apple-sub-anonimo')->first();
        $utente = User::withoutGlobalScopes()->find($identita->user_id);

        $this->assertNotNull($utente);
        $this->assertSame('Anonimo Mela', $utente->name);
        $this->assertStringEndsWith('@social.invalid', $utente->email);
        $this->assertNotNull($utente->username);
    }

    /**
     * ⚠️ `username` e' unico su tutta la piattaforma, non per palestra: due
     * `mario` in due palestre diverse non possono coesistere, e qui non c'e'
     * nessuno a cui chiedere di sceglierne un altro.
     */
    #[Test]
    public function un_nome_utente_gia_preso_altrove_non_fa_esplodere_la_creazione(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $this->creaUtente($beta, UserRole::Member, 'mario@beta.test', ['username' => 'mario']);

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-mario2',
            email: 'mario@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['join_code' => 'ALFA2345'])->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'mario@esempio.it')->first();

        $this->assertNotNull($utente);
        $this->assertNotSame('mario', $utente->username);
    }
}
