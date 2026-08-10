<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

use App\Enums\SocialProvider;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica i token d'identita' di Google e Apple contro le loro chiavi pubbliche.
 *
 * 🚨 **Si verifica la firma, non ci si fida del contenuto.** Un token
 * d'identita' e' un JSON firmato che arriva **dal telefono dell'utente**: senza
 * controllo della firma, chiunque puo' scrivere `{"sub":"123","email":"tu@..."}`
 * e chiedere di entrare come chiunque. E' l'unico controllo che conta di tutto
 * questo file.
 *
 * 🚨 **E anche il destinatario (`aud`), che si dimentica sempre.** Un token
 * Google firmato benissimo ma emesso per **un'altra applicazione** e' valido:
 * chiunque gestisca un'app con Google Sign-In potrebbe raccogliere i token dei
 * propri utenti e usarli qui. Controllare `aud` e' cio' che impedisce a un
 * token legittimo altrove di valere qualcosa qui.
 *
 * ⚠️ **Nessuna libreria JWT**: il formato e' tre pezzi separati da punti e la
 * verifica e' una `openssl_verify`. Aggiungere una dipendenza a un percorso di
 * autenticazione — dove ogni CVE diventa nostra — per risparmiare quaranta
 * righe non conviene. La parte non ovvia, la ricostruzione della chiave RSA da
 * `n` ed `e`, e' isolata in `chiaveDaJwk()` e coperta dai test.
 *
 * Le chiavi si tengono in cache 6 ore: sono pubbliche e ruotano di rado, e una
 * chiamata di rete a ogni accesso sarebbe latenza gratuita piu' un punto di
 * rottura in piu'.
 */
class JwksSocialTokenVerifier implements SocialTokenVerifier
{
    private const CACHE_ORE = 6;

    /** L'emittente atteso, per fornitore. */
    private const ISSUER = [
        SocialProvider::Google->value => ['https://accounts.google.com', 'accounts.google.com'],
        SocialProvider::Apple->value => ['https://appleid.apple.com'],
    ];

    private const JWKS = [
        SocialProvider::Google->value => 'https://www.googleapis.com/oauth2/v3/certs',
        SocialProvider::Apple->value => 'https://appleid.apple.com/auth/keys',
    ];

    public function configured(SocialProvider $provider): bool
    {
        return $this->destinatariAmmessi($provider) !== [];
    }

    public function verify(SocialProvider $provider, string $idToken): VerifiedSocialUser
    {
        if (! $this->configured($provider)) {
            throw new InvalidSocialTokenException("Fornitore {$provider->value} non configurato.");
        }

        [$intestazione, $payload] = $this->pezziVerificati($provider, $idToken);

        unset($intestazione);

        $this->controllaEmittente($provider, $payload);
        $this->controllaDestinatario($provider, $payload);
        $this->controllaScadenza($payload);

        $sub = $payload['sub'] ?? null;

        if (! is_string($sub) || $sub === '') {
            throw new InvalidSocialTokenException('Token senza `sub`.');
        }

        $email = isset($payload['email']) && is_string($payload['email'])
            ? strtolower(trim($payload['email']))
            : null;

        return new VerifiedSocialUser(
            provider: $provider,
            providerUserId: $sub,
            email: $email,
            // Apple garantisce sempre; Google lo dichiara. La stringa `"true"`
            // esiste davvero nei token di Apple, che mandano booleani come
            // stringhe: confrontare con `=== true` li scarterebbe tutti.
            emailVerified: $provider->verificaSempreEmail()
                || ($payload['email_verified'] ?? false) === true
                || ($payload['email_verified'] ?? null) === 'true',
            name: isset($payload['name']) && is_string($payload['name'])
                ? trim($payload['name'])
                : null,
        );
    }

    // ───────────────────────── firma ─────────────────────────

    /**
     * Spezza il token, verifica la firma e restituisce intestazione e payload.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function pezziVerificati(SocialProvider $provider, string $idToken): array
    {
        $pezzi = explode('.', $idToken);

        if (count($pezzi) !== 3) {
            throw new InvalidSocialTokenException('Token malformato.');
        }

        [$b64Intestazione, $b64Payload, $b64Firma] = $pezzi;

        $intestazione = $this->jsonDaBase64Url($b64Intestazione);
        $payload = $this->jsonDaBase64Url($b64Payload);

        // ⚠️ **Solo RS256.** `alg` arriva dal token, cioe' dall'attaccante: se
        // lo si usasse per scegliere l'algoritmo, `alg: none` o `alg: HS256`
        // con la chiave pubblica come segreto sono i due modi classici di
        // firmare da soli un token valido. Qui l'algoritmo lo decidiamo noi e
        // il campo serve solo a rifiutare quello che non ci aspettiamo.
        if (($intestazione['alg'] ?? null) !== 'RS256') {
            throw new InvalidSocialTokenException('Algoritmo non ammesso.');
        }

        $kid = $intestazione['kid'] ?? null;

        if (! is_string($kid)) {
            throw new InvalidSocialTokenException('Token senza `kid`.');
        }

        $chiave = $this->chiavePubblica($provider, $kid);

        $valida = openssl_verify(
            "{$b64Intestazione}.{$b64Payload}",
            $this->base64UrlDecode($b64Firma),
            $chiave,
            OPENSSL_ALGO_SHA256,
        );

        if ($valida !== 1) {
            throw new InvalidSocialTokenException('Firma non valida.');
        }

        return [$intestazione, $payload];
    }

    /**
     * La chiave pubblica corrispondente al `kid`, in formato PEM.
     *
     * ⚠️ Se il `kid` non e' fra quelle in cache si **rilegge il JWKS una volta
     * sola**, poi si arrende: i fornitori ruotano le chiavi, e senza questo un
     * rinnovo li' bloccherebbe tutti gli accessi per sei ore. Con un ritentativo
     * illimitato, invece, un token con un `kid` inventato diventerebbe un modo
     * per farci martellare Google a ogni richiesta.
     */
    private function chiavePubblica(SocialProvider $provider, string $kid): string
    {
        $chiavi = $this->jwks($provider, forzaRilettura: false);

        if (! isset($chiavi[$kid])) {
            $chiavi = $this->jwks($provider, forzaRilettura: true);
        }

        if (! isset($chiavi[$kid])) {
            throw new InvalidSocialTokenException("Chiave {$kid} sconosciuta.");
        }

        return $chiavi[$kid];
    }

    /** @return array<string, string> kid => PEM */
    private function jwks(SocialProvider $provider, bool $forzaRilettura): array
    {
        $chiaveCache = "social.jwks.{$provider->value}";

        if ($forzaRilettura) {
            Cache::forget($chiaveCache);
        }

        return Cache::remember($chiaveCache, now()->addHours(self::CACHE_ORE), function () use ($provider): array {
            try {
                $risposta = Http::timeout(5)->get(self::JWKS[$provider->value]);
            } catch (Throwable $e) {
                throw new InvalidSocialTokenException('Chiavi non raggiungibili: '.$e->getMessage());
            }

            if (! $risposta->successful()) {
                throw new InvalidSocialTokenException('Chiavi non raggiungibili.');
            }

            $out = [];

            foreach ($risposta->json('keys') ?? [] as $jwk) {
                if (($jwk['kty'] ?? null) !== 'RSA' || ! isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                    continue;
                }

                $out[(string) $jwk['kid']] = $this->chiaveDaJwk((string) $jwk['n'], (string) $jwk['e']);
            }

            return $out;
        });
    }

    /**
     * Ricostruisce una chiave pubblica RSA in PEM da modulo ed esponente.
     *
     * Un JWK contiene i due numeri, `openssl` vuole un `SubjectPublicKeyInfo`
     * in DER: qui si costruisce a mano quella struttura ASN.1, che e'
     *
     *   SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }
     *
     * E' l'unico punto veramente ostico del file, ed e' il motivo per cui esiste
     * un test dedicato che parte da un JWK e arriva a verificare una firma vera.
     */
    private function chiaveDaJwk(string $nB64, string $eB64): string
    {
        $n = $this->base64UrlDecode($nB64);
        $e = $this->base64UrlDecode($eB64);

        $sequenzaNumeri = $this->asn1(0x30, $this->intero($n).$this->intero($e));

        // Lo zero davanti al BIT STRING e' il numero di bit inutilizzati
        // nell'ultimo byte: sempre zero qui, ma va scritto o la struttura non
        // e' valida.
        $bitString = $this->asn1(0x03, "\x00".$sequenzaNumeri);

        // 1.2.840.113549.1.1.1 — rsaEncryption.
        $oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algoritmo = $this->asn1(0x30, $oid."\x05\x00");

        $der = $this->asn1(0x30, $algoritmo.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    /** Un INTEGER ASN.1, con lo zero davanti se il primo bit e' acceso. */
    private function intero(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        // Senza questo, un modulo che comincia per 0x80 verrebbe letto come
        // numero negativo e la chiave risulterebbe diversa: capita su circa
        // meta' delle chiavi, quindi si vedrebbe subito — ma solo in
        // produzione.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return $this->asn1(0x02, $bytes);
    }

    /** Tag + lunghezza (forma corta o lunga) + contenuto. */
    private function asn1(int $tag, string $contenuto): string
    {
        $lunghezza = strlen($contenuto);

        if ($lunghezza < 0x80) {
            $prefisso = chr($lunghezza);
        } else {
            $bytes = ltrim(pack('N', $lunghezza), "\x00");
            $prefisso = chr(0x80 | strlen($bytes)).$bytes;
        }

        return chr($tag).$prefisso.$contenuto;
    }

    // ───────────────────────── rivendicazioni ─────────────────────────

    /** @param array<string, mixed> $payload */
    private function controllaEmittente(SocialProvider $provider, array $payload): void
    {
        $iss = $payload['iss'] ?? null;

        if (! is_string($iss) || ! in_array($iss, self::ISSUER[$provider->value], true)) {
            throw new InvalidSocialTokenException('Emittente inatteso.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function controllaDestinatario(SocialProvider $provider, array $payload): void
    {
        $aud = $payload['aud'] ?? null;

        // `aud` puo' essere una stringa o una lista: Google manda una stringa,
        // ma lo standard ammette entrambe.
        $destinatari = is_array($aud) ? $aud : [$aud];
        $ammessi = $this->destinatariAmmessi($provider);

        foreach ($destinatari as $d) {
            if (is_string($d) && in_array($d, $ammessi, true)) {
                return;
            }
        }

        throw new InvalidSocialTokenException('Token emesso per un\'altra applicazione.');
    }

    /** @param array<string, mixed> $payload */
    private function controllaScadenza(array $payload): void
    {
        $exp = $payload['exp'] ?? null;

        if (! is_numeric($exp)) {
            throw new InvalidSocialTokenException('Token senza scadenza.');
        }

        // 60 secondi di tolleranza sugli orologi: senza, un telefono avanti di
        // mezzo minuto vedrebbe rifiutati token appena emessi.
        if ((int) $exp + 60 < time()) {
            throw new InvalidSocialTokenException('Token scaduto.');
        }
    }

    /**
     * Gli `aud` accettati: un'app iOS e una Android hanno client id diversi, e
     * il token di Apple ha come `aud` il **bundle id**.
     *
     * @return list<string>
     */
    private function destinatariAmmessi(SocialProvider $provider): array
    {
        $grezzi = config("services.social.{$provider->value}.client_ids", []);

        if (is_string($grezzi)) {
            $grezzi = explode(',', $grezzi);
        }

        if (! is_array($grezzi)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $grezzi,
        )));
    }

    // ───────────────────────── base64url ─────────────────────────

    /** @return array<string, mixed> */
    private function jsonDaBase64Url(string $pezzo): array
    {
        $decodificato = json_decode($this->base64UrlDecode($pezzo), true);

        if (! is_array($decodificato)) {
            throw new InvalidSocialTokenException('Token malformato.');
        }

        return $decodificato;
    }

    private function base64UrlDecode(string $valore): string
    {
        $decodificato = base64_decode(strtr($valore, '-_', '+/'), true);

        if ($decodificato === false) {
            throw new InvalidSocialTokenException('Token malformato.');
        }

        return $decodificato;
    }
}
