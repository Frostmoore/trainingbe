<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🔑 **Il token OAuth per mandare notifiche con Firebase** — 24/09/2026.
 *
 * 📌 Il committente: *«mettimi nei da fare che bisogna impostare firebase sia per
 * android che per apple dopo che abbiamo il dominio. Per adesso predisponi tutto
 * il codice necessario»*.
 *
 * ══ 🚨 PERCHÉ NON BASTA UN TOKEN NEL `.env` ═════════════════════════════════
 *
 * FCM HTTP v1 vuole un token OAuth che **scade dopo un'ora**. ⛔ Quello scritto a
 * mano in `FCM_ACCESS_TOKEN` (com'era da B10.6) funziona per un'ora e poi tutte
 * le notifiche falliscono in silenzio — `PushSender` non lancia mai, di proposito.
 *
 * 💡 Qui lo si ricava dalla **chiave di servizio** di Firebase (il file JSON che
 * si scarica dalla console: *Impostazioni progetto → Account di servizio → Genera
 * nuova chiave privata*): un JWT firmato con la sua chiave privata si scambia con
 * un token vero, che si tiene in cache fino a cinque minuti prima della scadenza.
 *
 * ⚠️ **Il file è un segreto**: sta fuori dal repository (`FIREBASE_CREDENTIALS` è
 * un percorso sul server), e ⛔ non va caricato sul Projects Tracker né
 * incollato in un documento.
 */
class TokenDiFirebase
{
    private const CHIAVE_CACHE = 'push.fcm.access_token';

    private const AMBITO = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Il token da usare adesso, o `null` se Firebase non è configurato. */
    public function token(): ?string
    {
        $fisso = config('push.fcm.access_token');

        // 💡 Il token scritto a mano resta come ripiego per le prove brevi.
        if (is_string($fisso) && $fisso !== '') {
            return $fisso;
        }

        $credenziali = $this->credenziali();

        if ($credenziali === null) {
            return null;
        }

        $inCache = Cache::get(self::CHIAVE_CACHE);

        if (is_string($inCache) && $inCache !== '') {
            return $inCache;
        }

        try {
            $risposta = Http::asForm()->timeout(10)->post((string) $credenziali['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->jwt($credenziali),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $token = $risposta->json('access_token');

        if (! $risposta->successful() || ! is_string($token)) {
            Log::warning('push: Firebase non ha dato il token.', ['stato' => $risposta->status()]);

            return null;
        }

        Cache::put(self::CHIAVE_CACHE, $token, max(60, (int) $risposta->json('expires_in', 3600) - 300));

        return $token;
    }

    /** Il progetto Firebase: dal `.env`, o dalla chiave di servizio. */
    public function progetto(): ?string
    {
        $progetto = config('push.fcm.project_id');

        if (is_string($progetto) && $progetto !== '') {
            return $progetto;
        }

        $credenziali = $this->credenziali();

        return is_string($credenziali['project_id'] ?? null) ? $credenziali['project_id'] : null;
    }

    /**
     * La chiave di servizio, letta dal file di `FIREBASE_CREDENTIALS`.
     *
     * @return array<string, mixed>|null
     */
    private function credenziali(): ?array
    {
        $percorso = config('push.fcm.credentials');

        if (! is_string($percorso) || $percorso === '' || ! is_readable($percorso)) {
            return null;
        }

        $dati = json_decode((string) file_get_contents($percorso), true);

        if (! is_array($dati)
            || ! is_string($dati['client_email'] ?? null)
            || ! is_string($dati['private_key'] ?? null)) {
            Log::warning('push: la chiave di servizio di Firebase non si legge.');

            return null;
        }

        $dati['token_uri'] ??= 'https://oauth2.googleapis.com/token';

        return $dati;
    }

    /**
     * Il JWT firmato RS256 con la chiave privata del servizio.
     *
     * @param  array<string, mixed>  $credenziali
     */
    private function jwt(array $credenziali): string
    {
        $adesso = time();

        $pezzi = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $credenziali['client_email'],
                'scope' => self::AMBITO,
                'aud' => $credenziali['token_uri'],
                'iat' => $adesso,
                'exp' => $adesso + 3600,
            ])),
        ];

        $firma = '';
        openssl_sign(implode('.', $pezzi), $firma, (string) $credenziali['private_key'], OPENSSL_ALGO_SHA256);
        $pezzi[] = $this->base64Url($firma);

        return implode('.', $pezzi);
    }

    private function base64Url(string $dati): string
    {
        return rtrim(strtr(base64_encode($dati), '+/', '-_'), '=');
    }
}
