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
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');

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
    private function accedi(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/social', array_merge([
            'provider' => 'google',
            'id_token' => 'token-finto',

            // S9.2 — al primo accesso servono anche di qui (li controlla
            // `SocialAuthController::pretendiIConsensi()`); agli accessi
            // successivi non si leggono.
            'age_confirmed' => true,
            'terms_accepted' => true,
        ], $extra));
    }

    // ───────────────────────── primo accesso ─────────────────────────

    /*
     * ⛔ Qui c'era `il_primo_accesso_crea_l_iscritto_e_restituisce_un_token`: col codice palestra si nasceva dentro la palestra. Da F11 (24/09/2026)
     * il codice non esiste: si nasce nel proprio spazio (il test qui sotto).
     */

    /**
     * 🆕 **Senza codice palestra nasce un account personale** — F3.
     *
     * ⚠️ **Questo test diceva il contrario fino al 13/08/2026**, e il perché
     * merita di restare scritto. La vecchia regola era: *«senza codice non si
     * può sapere DOVE creare l'account, perché l'email è unica per palestra»* —
     * e rispondeva `422 join_code_required`.
     *
     * 🚨 Era giusta finché **ogni** utente doveva stare in una palestra. Da F3
     * «nessuna palestra» è una risposta valida alla domanda «dove?»: si crea un
     * tenant personale. Il presupposto è cambiato, non il ragionamento.
     */
    #[Test]
    public function al_primo_accesso_senza_codice_palestra_nasce_un_account_personale(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
            name: 'Nuovo Libero',
        ));

        $r = $this->accedi()->assertCreated();

        $this->assertIsString($r->json('token'));
        $this->assertSame([UserRole::FreeUser->value], $r->json('data.roles'));

        $utente = User::withoutGlobalScopes()->where('email', 'nuovo@esempio.it')->firstOrFail();

        $this->assertNotNull($utente->tenant_id, 'Senza tenant sarebbe un super admin di fatto.');
        $this->assertTrue($utente->tenant->ePersonale());
        $this->assertSame(1, SocialIdentity::count());
    }

    /**
     * 🚨 **Lo sbarramento 18+ vale anche di qui, e da F3 non lo fa più il modulo.**
     *
     * `SocialLoginRequest` aveva `exclude_without:join_code` sui consensi (tolta con F11), una
     * regola che si reggeva sull'equivalenza *«niente codice ⇒ non è un primo
     * accesso»*. Da F3 quell'equivalenza **è falsa**: senza codice si può nascere.
     *
     * ⚠️ Senza il controllo in `SocialAuthController::pretendiIConsensi()`,
     * tutte le iscrizioni con Google senza codice — che nella Parte B saranno la
     * maggioranza — avrebbero saltato la dichiarazione di maggiore età. Nessun
     * errore, nessun test rosso: solo una casella che smette di essere chiesta.
     */
    #[Test]
    public function senza_codice_i_consensi_restano_obbligatori(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
        ));

        $this->accedi(['age_confirmed' => false])
            ->assertStatus(422)
            ->assertJsonPath('code', 'consents_required');

        $this->assertSame(0, SocialIdentity::count());
        $this->assertSame(0, User::withoutGlobalScopes()->whereNotNull('tenant_id')
            ->whereHas('tenant', fn ($q) => $q->personali())->count());
    }

    /**
     * ⚠️ Senza un'email **verificata** non si crea un account personale.
     *
     * Nel ramo con la palestra un indirizzo segnaposto è tollerabile: c'è una
     * palestra che conosce quella persona e un amministratore che può
     * sistemare. Qui non c'è nessuno — resterebbe un account senza un indirizzo
     * vero, quindi senza recupero della password e senza modo di dimostrare che
     * è suo.
     */
    #[Test]
    public function senza_codice_serve_un_email_verificata(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: false,
        ));

        $this->accedi()->assertStatus(422);

        $this->assertSame(0, SocialIdentity::count());
    }

    /**
     * ⛔ **Il codice palestra non esiste più** — F11, 24/09/2026. Chi lo manda
     * pensa di entrare in una palestra: si rifiuta con un messaggio che dice
     * dove si entra, e non nasce niente.
     */
    #[Test]
    public function un_codice_palestra_si_rifiuta_con_un_messaggio_chiaro(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'nuovo@esempio.it',
            emailVerified: true,
        ));

        $r = $this->accedi(['join_code' => 'ALFA2345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_code');

        $this->assertStringContainsString("link d'invito", $r->json('errors.join_code.0'));
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

        $r = $this->accedi()->assertStatus(422);

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

    /*
     * ⛔ Qui c'era `un_email_non_verificata_senza_collisioni_crea_un_account_nuovo`: valeva solo dentro una palestra scelta col codice.
     * Da F11 senza email verificata non nasce niente
     * (`senza_codice_serve_un_email_verificata`).
     */

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

        $this->accedi()->assertOk();

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

        $this->accedi()->assertOk();
        $this->accedi(['provider' => 'apple'])->assertOk();

        $this->assertSame(2, SocialIdentity::where('user_id', $utente->getKey())->count());
    }

    // ───────────────────────── accessi successivi ─────────────────────────

    #[Test]
    public function il_secondo_accesso_ritrova_l_identita(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'tornante@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertCreated();
        $this->accedi()->assertOk()->assertJsonPath('data.email', 'tornante@esempio.it');

        $this->assertSame(1, SocialIdentity::count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'tornante@esempio.it')->count());
    }

    /**
     * 🆕 F11 — **al primo accesso ci si collega all'account che c'è già**, in
     * qualunque posto sia (palestra o spazio personale), se è **uno solo** e
     * l'email è verificata. ⛔ Prima di F11 un account personale con la stessa
     * email faceva respingere l'accesso.
     */
    #[Test]
    public function il_primo_accesso_si_collega_all_account_personale_che_c_e_gia(): void
    {
        $libero = app(CreaTenantPersonale::class)('Libero', 'libero@esempio.it', ['password' => self::FAKE_PASSWORD]);

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-libero',
            email: 'libero@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertOk()->assertJsonPath('data.email', 'libero@esempio.it');

        $this->assertSame($libero->getKey(), SocialIdentity::where('provider_user_id', 'google-sub-libero')->value('user_id'));
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'libero@esempio.it')->count());
    }

    /**
     * 🚨 Due account con la stessa email (in due palestre): **si rifiuta**,
     * invece di indovinare quale sia «il suo». La stessa regola dell'accesso
     * con la password.
     */
    #[Test]
    public function con_due_account_con_la_stessa_email_non_indovina(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta');
        $this->creaUtente($this->alfa, UserRole::Member, 'doppio@esempio.it');
        $this->creaUtente($beta, UserRole::Member, 'doppio@esempio.it');

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-doppio',
            email: 'doppio@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertStatus(422);

        $this->assertSame(0, SocialIdentity::count());
    }

    /**
     * La palestra sospesa da' `tenant_inactive`, non un errore di credenziali:
     * l'app deve portare alla schermata dedicata, non al login — dove si
     * riproverebbe all'infinito con credenziali giuste. ⚠️ Vale sia al primo
     * accesso (il collegamento non si fa) sia ai successivi.
     */
    #[Test]
    public function una_palestra_sospesa_da_tenant_inactive(): void
    {
        $this->creaUtente($this->alfa, UserRole::Member, 'sospeso@esempio.it');

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'sospeso@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertOk();

        $this->alfa->forceFill(['status' => TenantStatus::Suspended])->save();

        /*
         * 🆕 🔑 **Entra lo stesso** — decisione del committente, 26/09/2026.
         * 🚨 Qui si aspettava un 403: la stessa persona, la stessa identità
         * verificata da Google, e la porta chiusa perché un terzo non aveva
         * pagato. Lo stato della palestra decide cosa funziona, non chi entra.
         */
        $this->accedi()->assertOk();
    }

    #[Test]
    public function con_la_palestra_sospesa_il_primo_accesso_collega_lo_stesso(): void
    {
        /*
         * 🆕 🔑 **Ribaltato il 26/09/2026.** Si chiamava
         * `col_la_palestra_sospesa_il_primo_accesso_non_collega_niente`.
         *
         * 🚨 Impedire di **collegare** Google a un account che già esiste era
         * la forma più sottile dello stesso furto: non solo non entri, ma non
         * puoi nemmeno aggiungerti un modo in più per rientrare domani.
         *
         * 💡 Ora l'identità si collega, e la persona entra. Quello che la
         * palestra finanzia resta chiuso: lo prova
         * `ma_le_funzioni_che_paga_la_palestra_si_fermano` in `AuthApiTest`.
         */
        $this->creaUtente($this->alfa, UserRole::Member, 'sospeso@esempio.it');
        $this->alfa->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-1',
            email: 'sospeso@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertOk();

        $this->assertSame(1, SocialIdentity::count());
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

        $this->accedi()->assertCreated();

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

        $r = $this->accedi()->assertStatus(422);

        $this->assertStringNotContainsStringIgnoringCase('firma', json_encode($r->json()));
        $this->assertSame(0, SocialIdentity::count());
    }

    #[Test]
    public function un_fornitore_sconosciuto_si_rifiuta(): void
    {
        $this->accedi(['provider' => 'facebook'])
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

        $this->accedi()
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

        $r = $this->accedi()->assertCreated();

        $this->assertArrayHasKey('token', $r->json());
        $this->assertArrayHasKey('data', $r->json());
        $this->assertArrayHasKey('branding', $r->json());
    }

    /**
     * ⚠️ Apple può non mandare un indirizzo. Prima di F11, dentro una palestra
     * scelta col codice, nasceva un account con un indirizzo segnaposto; senza
     * palestra **non nasce niente**: resterebbe un account senza recupero della
     * password e senza modo di dimostrare che è suo.
     */
    #[Test]
    public function apple_senza_email_non_crea_un_account_senza_indirizzo(): void
    {
        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Apple,
            providerUserId: 'apple-sub-anonimo',
            email: null,
            emailVerified: true,
            name: 'Anonimo Mela',
        ));

        $this->accedi(['provider' => 'apple'])->assertStatus(422);

        $this->assertSame(0, SocialIdentity::count());
    }

    /**
     * ⚠️ `username` e' unico su tutta la piattaforma, non per palestra: due
     * `mario` in due palestre diverse non possono coesistere, e qui non c'e'
     * nessuno a cui chiedere di sceglierne un altro.
     */
    #[Test]
    public function un_nome_utente_gia_preso_altrove_non_fa_esplodere_la_creazione(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta');
        $this->creaUtente($beta, UserRole::Member, 'mario@beta.test', ['username' => 'mario']);

        $this->verificaRestituisce(new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'google-sub-mario2',
            email: 'mario@esempio.it',
            emailVerified: true,
        ));

        $this->accedi()->assertCreated();

        $utente = User::withoutGlobalScopes()->where('email', 'mario@esempio.it')->first();

        $this->assertNotNull($utente);
        $this->assertNotSame('mario', $utente->username);
    }
}
