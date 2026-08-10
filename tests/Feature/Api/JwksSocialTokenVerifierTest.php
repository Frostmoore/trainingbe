<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SocialProvider;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;
use App\Services\Auth\Social\JwksSocialTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * C17 — la verifica crittografica dei token d'identita'.
 *
 * 🚨 **Qui si prova l'unica cosa che impedisce a chiunque di entrare come
 * chiunque.** Un token d'identita' arriva **dal telefono dell'utente**: senza
 * controllo della firma, `{"sub":"1","email":"tu@..."}` scritto a mano vale
 * quanto uno vero.
 *
 * Le chiavi si generano al volo nel test e il JWKS si serve da `Http::fake()`:
 * nessuna rete, e si possono firmare token **validi** e **falsi** a piacere —
 * che e' l'unico modo di provare davvero un verificatore.
 */
class JwksSocialTokenVerifierTest extends TestCase
{
    private const KID = 'chiave-di-prova';

    /** @var array{privata: \OpenSSLAsymmetricKey, n: string, e: string} */
    private array $chiave;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('services.social.google.client_ids', 'la-nostra-app.apps.googleusercontent.com');
        config()->set('services.social.apple.client_ids', 'it.riccardoronconi.training');

        $this->chiave = $this->generaChiave();

        $this->serviJwks($this->chiave['n'], $this->chiave['e']);

        // Registrata **una volta sola**: la closure legge `$this->jwkCorrente`
        // a ogni richiesta, quindi cambiare chiave a metà test funziona.
        Http::fake(fn () => Http::response(['keys' => [$this->jwkCorrente]]));
    }

    /**
     * Dove sta `openssl.cnf`, se serve dirlo.
     *
     * ⚠️ **Su Windows la generazione di una chiave fallisce senza questo.**
     * `openssl_pkey_new()` restituisce `false` — non lancia — e il test muore
     * più avanti con «argument must be OpenSSLAsymmetricKey, false given», che
     * non dice niente di utile. Su Linux (staging, CI) la configurazione è nei
     * percorsi di sistema e questo metodo restituisce `null`, cioè «arrangiati
     * da solo», che è il comportamento giusto lì.
     *
     * Il percorso si ricava da `PHP_BINARY`, non è scritto a mano: cambiando
     * interprete continua a funzionare.
     */
    private function configOpenssl(): ?string
    {
        $daAmbiente = getenv('OPENSSL_CONF');

        if (is_string($daAmbiente) && $daAmbiente !== '' && is_file($daAmbiente)) {
            return $daAmbiente;
        }

        $accantoAPhp = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
            .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        return is_file($accantoAPhp) ? $accantoAPhp : null;
    }

    /** @return array{privata: \OpenSSLAsymmetricKey, n: string, e: string} */
    private function generaChiave(): array
    {
        $opzioni = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $cnf = $this->configOpenssl();

        if ($cnf !== null) {
            $opzioni['config'] = $cnf;
        }

        $risorsa = openssl_pkey_new($opzioni);

        // 🚨 Si fallisce **qui**, con un messaggio che dice cosa fare. Lasciar
        // proseguire un `false` porta a un errore dieci righe più in là che
        // parla di tipi e non di OpenSSL, e ci si perde mezz'ora.
        $this->assertNotFalse(
            $risorsa,
            'Generazione della chiave fallita: OpenSSL non trova openssl.cnf. '
            .'Su Windows serve la variabile OPENSSL_CONF, oppure il file in '
            .dirname(PHP_BINARY).'\\extras\\ssl\\openssl.cnf',
        );

        $dettagli = openssl_pkey_get_details($risorsa);

        return [
            'privata' => $risorsa,
            'n' => $this->base64UrlEncode($dettagli['rsa']['n']),
            'e' => $this->base64UrlEncode($dettagli['rsa']['e']),
        ];
    }

    /**
     * Il JWK servito in questo momento.
     *
     * @var array<string, string>
     */
    private array $jwkCorrente = [];

    /**
     * Serve il JWKS, e lo cambia quando serve.
     *
     * 🚨 **Lo stub e' una closure letta a ogni richiesta, non una risposta
     * fissa.** `Http::fake()` **fonde** gli stub invece di sostituirli: chiamarla
     * una seconda volta lascia vincere quella registrata per prima, e il test si
     * ritrova a firmare con una chiave nuova mentre il finto JWKS continua a
     * consegnare la vecchia. Il sintomo — «firma non valida» — sembra un difetto
     * del verificatore, ed e' un difetto del test. E' costato mezz'ora.
     */
    private function serviJwks(string $n, string $e, string $kid = self::KID): void
    {
        $this->jwkCorrente = [
            'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $n, 'e' => $e,
        ];
    }

    private function base64UrlEncode(string $dati): string
    {
        return rtrim(strtr(base64_encode($dati), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $intestazioneExtra
     */
    private function token(array $payload, array $intestazioneExtra = [], bool $firmaValida = true): string
    {
        $intestazione = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID], $intestazioneExtra);

        $b64Intestazione = $this->base64UrlEncode((string) json_encode($intestazione));
        $b64Payload = $this->base64UrlEncode((string) json_encode($payload));

        openssl_sign(
            "{$b64Intestazione}.{$b64Payload}",
            $firma,
            $this->chiave['privata'],
            OPENSSL_ALGO_SHA256,
        );

        if (! $firmaValida) {
            // Un bit girato: la firma resta della lunghezza giusta e sembra
            // valida a occhio, che e' esattamente il caso da intercettare.
            $firma[10] = $firma[10] === "\x00" ? "\x01" : "\x00";
        }

        return $b64Intestazione.'.'.$b64Payload.'.'.$this->base64UrlEncode($firma);
    }

    /** @param array<string, mixed> $extra */
    private function payloadGoogle(array $extra = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'la-nostra-app.apps.googleusercontent.com',
            'sub' => '1234567890',
            'email' => 'mario@gmail.com',
            'email_verified' => true,
            'name' => 'Mario Rossi',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $extra);
    }

    private function verifier(): JwksSocialTokenVerifier
    {
        return new JwksSocialTokenVerifier;
    }

    // ───────────────────────── il caso buono ─────────────────────────

    #[Test]
    public function un_token_valido_restituisce_chi_c_e_dietro(): void
    {
        $identita = $this->verifier()->verify(SocialProvider::Google, $this->token($this->payloadGoogle()));

        $this->assertSame('1234567890', $identita->providerUserId);
        $this->assertSame('mario@gmail.com', $identita->email);
        $this->assertTrue($identita->emailVerified);
        $this->assertSame('Mario Rossi', $identita->name);
    }

    /**
     * 🚨 Il test che giustifica tutta la ricostruzione ASN.1 della chiave.
     *
     * Se `chiaveDaJwk()` sbagliasse anche di un byte, nessuna firma tornerebbe
     * mai — oppure, peggio, tornerebbe solo per metà delle chiavi (quelle il cui
     * modulo non comincia con il bit alto acceso).
     */
    #[Test]
    public function le_chiavi_si_ricostruiscono_dal_jwks_qualunque_sia_il_modulo(): void
    {
        // Dieci chiavi diverse: statisticamente metà hanno il primo bit del
        // modulo acceso, che è il caso in cui serve lo zero davanti all'INTEGER.
        for ($i = 0; $i < 10; $i++) {
            $this->chiave = $this->generaChiave();
            $this->serviJwks($this->chiave['n'], $this->chiave['e']);
            Cache::flush();

            $identita = $this->verifier()->verify(
                SocialProvider::Google,
                $this->token($this->payloadGoogle(['sub' => "sub-{$i}"])),
            );

            $this->assertSame("sub-{$i}", $identita->providerUserId);
        }
    }

    // ───────────────────────── i rifiuti ─────────────────────────

    #[Test]
    public function una_firma_manomessa_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(), firmaValida: false),
        );
    }

    /**
     * 🚨 **Il controllo che si dimentica sempre.**
     *
     * Un token firmato benissimo da Google, ma emesso per **un'altra
     * applicazione**, e' crittograficamente valido: chiunque gestisca un'app con
     * Google Sign-In potrebbe raccogliere i token dei propri utenti e usarli qui.
     */
    #[Test]
    public function un_token_emesso_per_un_altra_app_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['aud' => 'app-di-qualcun-altro.apps.googleusercontent.com'])),
        );
    }

    /**
     * 🚨 `alg` arriva dall'attaccante: se lo si usasse per scegliere
     * l'algoritmo, `alg: none` sarebbe un modo per firmarsi i token da soli.
     */
    #[Test]
    public function un_algoritmo_diverso_da_rs256_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(), ['alg' => 'none']),
        );
    }

    #[Test]
    public function un_token_scaduto_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['exp' => time() - 7200])),
        );
    }

    /** ⚠️ Un telefono avanti di mezzo minuto non deve restare fuori. */
    #[Test]
    public function un_token_scaduto_da_pochi_secondi_passa_ancora(): void
    {
        $identita = $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['exp' => time() - 30])),
        );

        $this->assertSame('1234567890', $identita->providerUserId);
    }

    #[Test]
    public function un_emittente_inatteso_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['iss' => 'https://accounts.evil.test'])),
        );
    }

    #[Test]
    public function un_token_malformato_si_rifiuta(): void
    {
        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(SocialProvider::Google, 'non-sono-un-jwt');
    }

    #[Test]
    public function una_chiave_sconosciuta_si_rifiuta(): void
    {
        $this->serviJwks($this->chiave['n'], $this->chiave['e'], kid: 'un-altra-chiave');
        Cache::flush();

        $this->expectException(InvalidSocialTokenException::class);

        $this->verifier()->verify(SocialProvider::Google, $this->token($this->payloadGoogle()));
    }

    // ───────────────────────── rivendicazioni ─────────────────────────

    /**
     * ⚠️ Apple manda i booleani **come stringhe**: `"true"`, non `true`.
     * Confrontare con `=== true` scarterebbe tutte le sue email verificate.
     */
    #[Test]
    public function email_verified_come_stringa_conta_come_verificata(): void
    {
        $identita = $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['email_verified' => 'true'])),
        );

        $this->assertTrue($identita->emailVerified);
    }

    #[Test]
    public function per_google_un_email_non_verificata_resta_non_verificata(): void
    {
        $identita = $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle(['email_verified' => false])),
        );

        $this->assertFalse($identita->emailVerified);
    }

    /** Apple verifica sempre l'email, anche senza dichiararlo nel token. */
    #[Test]
    public function per_apple_l_email_e_sempre_verificata(): void
    {
        $identita = $this->verifier()->verify(SocialProvider::Apple, $this->token([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'it.riccardoronconi.training',
            'sub' => 'apple-sub',
            'email' => 'mario@privaterelay.appleid.com',
            'exp' => time() + 3600,
        ]));

        $this->assertTrue($identita->emailVerified);
        $this->assertSame('mario@privaterelay.appleid.com', $identita->email);
    }

    // ───────────────────────── configurazione ─────────────────────────

    #[Test]
    public function senza_client_id_il_fornitore_non_risulta_configurato(): void
    {
        config()->set('services.social.google.client_ids', '');

        $this->assertFalse($this->verifier()->configured(SocialProvider::Google));
        $this->assertTrue($this->verifier()->configured(SocialProvider::Apple));
    }

    /**
     * ⚠️ Google emette un client id **per piattaforma**: Android e iOS sono
     * diversi. Accettarne uno solo farebbe funzionare l'accesso su metà dei
     * telefoni — un guasto che in prova non si vede, perché si prova su uno.
     */
    #[Test]
    public function accetta_piu_client_id_separati_da_virgola(): void
    {
        config()->set(
            'services.social.google.client_ids',
            'android.apps.googleusercontent.com, ios.apps.googleusercontent.com',
        );

        foreach (['android.apps.googleusercontent.com', 'ios.apps.googleusercontent.com'] as $aud) {
            $identita = $this->verifier()->verify(
                SocialProvider::Google,
                $this->token($this->payloadGoogle(['aud' => $aud])),
            );

            $this->assertSame('1234567890', $identita->providerUserId);
        }
    }

    /** `aud` può essere una lista: lo standard lo ammette. */
    #[Test]
    public function un_aud_come_lista_funziona(): void
    {
        $identita = $this->verifier()->verify(
            SocialProvider::Google,
            $this->token($this->payloadGoogle([
                'aud' => ['altro', 'la-nostra-app.apps.googleusercontent.com'],
            ])),
        );

        $this->assertSame('1234567890', $identita->providerUserId);
    }
}
