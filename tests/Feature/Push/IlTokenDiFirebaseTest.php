<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Models\DeviceToken;
use App\Services\Push\PushSender;
use App\Services\Push\TokenDiFirebase;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔑 **Le notifiche push con la chiave di servizio di Firebase** — 24/09/2026.
 *
 * Firebase non è ancora configurato (DA-FARE: dopo il dominio). Questi test
 * provano che il codice è pronto: dalla chiave di servizio si ricava il token,
 * lo si tiene in cache, e il messaggio porta i `data` che l'app legge al tocco.
 * La rete è finta; la chiave è generata al volo.
 */
final class IlTokenDiFirebaseTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ Una chiave RSA **di prova**, generata una volta e senza nessun valore:
         * `openssl_pkey_new()` su Windows vuole un `openssl.cnf` che non c'è
         * ovunque, e un test che dipende dalla macchina non è un test.
         */
        $privata = (string) file_get_contents(base_path('tests/fixtures/firebase-chiave-di-prova.pem'));

        $this->file = tempnam(sys_get_temp_dir(), 'firebase');
        file_put_contents($this->file, json_encode([
            'type' => 'service_account',
            'project_id' => 'training-prova',
            'client_email' => 'push@training-prova.iam.gserviceaccount.com',
            'private_key' => $privata,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'push.fcm.credentials' => $this->file,
            'push.fcm.access_token' => null,
            'push.fcm.project_id' => null,
        ]);
        Cache::forget('push.fcm.access_token');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** 🔑 Dalla chiave di servizio: un JWT firmato si scambia col token, che resta in cache. */
    #[Test]
    public function il_token_si_ricava_dalla_chiave_e_resta_in_cache(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.finto', 'expires_in' => 3600])]);

        $firebase = app(TokenDiFirebase::class);

        $this->assertSame('ya29.finto', $firebase->token());
        $this->assertSame('ya29.finto', $firebase->token());
        $this->assertSame('training-prova', $firebase->progetto());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && count(explode('.', (string) $r['assertion'])) === 3);
    }

    /** ⛔ Senza chiave, niente token: le notifiche non partono e nessuno si rompe. */
    #[Test]
    public function senza_chiave_niente_token(): void
    {
        config(['push.fcm.credentials' => null]);

        $this->assertNull(app(TokenDiFirebase::class)->token());
    }

    /** 📲 Il messaggio a FCM porta i `data` che l'app legge al tocco. */
    #[Test]
    public function la_notifica_porta_i_dati_per_il_tocco(): void
    {
        config(['push.driver' => 'fcm']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.finto', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $utente = app(CreaTenantPersonale::class)('Tizio', 'tizio@esempio.test', ['password' => self::FAKE_PASSWORD])->fresh();
        DeviceToken::query()->create(['user_id' => $utente->getKey(), 'tenant_id' => $utente->tenant_id, 'token' => 'tok-1', 'platform' => 'android']);

        $inviati = app(PushSender::class)->toUser($utente, 'Un regalo', '10 gettoni', ['tipo' => 'promozione', 'codice' => 'AUTUNNO']);

        $this->assertSame(1, $inviati);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'projects/training-prova/messages:send')
            && $r['message']['data'] === ['tipo' => 'promozione', 'codice' => 'AUTUNNO']
            && $r->hasHeader('Authorization', 'Bearer ya29.finto'));
    }

    /**
     * 🆕 🚨 **La notifica arriva anche se il telefono è registrato altrove** —
     * 27/09/2026, voce 62.
     *
     * ══ ⛔ IL DIFETTO CHE QUESTO TEST CHIUDE ══════════════════════════════
     *
     * `DeviceToken` usa `BelongsToTenant`, e `PushSender::toUser()` interrogava
     * la tabella **con lo scope attivo**. 🚨 Ma `device_tokens.tenant_id` è quello
     * che la persona aveva quando ha registrato il telefono, e **nessuno lo
     * aggiorna** quando cambia palestra.
     *
     * ⛔ Quindi una notifica mandata da dentro un contesto — il pannello, una
     * rotta API, `runAs()` in un job — non trovava più i suoi dispositivi. 🚨 E
     * non si vedeva: dai webhook e dai comandi (contesto vuoto, `TenantScope`
     * non filtra) le stesse notifiche partivano benissimo. Lo stesso invio
     * funzionava o no **a seconda di chi lo faceva partire**.
     *
     * ══ 🔎 COME QUESTO TEST LO DIMOSTRA ═══════════════════════════════════
     *
     * 1. un telefono registrato con un `tenant_id` **diverso** da quello del
     *    contesto — cioè la situazione di chi ha cambiato palestra;
     * 2. il contesto messo su un'altra palestra, come in una richiesta vera;
     * 3. la notifica **deve** partire comunque.
     *
     * ⚠️ Rimettendo lo scope in `toUser()`, `$inviati` torna 0 e questo test
     * diventa rosso: verificato il 27/09.
     */
    #[Test]
    public function il_telefono_registrato_in_unaltra_palestra_riceve_comunque(): void
    {
        config(['push.driver' => 'fcm']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.finto', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $utente = app(CreaTenantPersonale::class)('Tizia', 'tizia@esempio.test', ['password' => self::FAKE_PASSWORD])->fresh();

        /*
         * 🔑 Il telefono risulta registrato in una palestra che **non** è quella
         * del contesto: è esattamente ciò che resta in tabella quando una persona
         * si sposta.
         */
        DeviceToken::query()->create([
            'user_id' => $utente->getKey(),
            'tenant_id' => $utente->tenant_id,
            'token' => 'tok-di-prima',
            'platform' => 'android',
        ]);

        $altrove = $this->creaPalestra('Palestra di adesso', 'adesso');

        $inviati = app(TenantContext::class)->runAs(
            $altrove,
            fn (): int => app(PushSender::class)->toUser($utente, 'Tutto a posto', 'Il servizio è attivo', ['tipo' => 'servizio_riattivato']),
        );

        $this->assertSame(
            1,
            $inviati,
            '🚨 La notifica non è partita: lo scope della palestra sta decidendo a quale telefono si può scrivere.',
        );
    }
}
