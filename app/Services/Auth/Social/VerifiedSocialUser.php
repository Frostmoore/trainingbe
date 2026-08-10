<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

use App\Enums\SocialProvider;

/**
 * Chi c'e' dietro un token, dopo che il token e' stato verificato.
 *
 * 🚨 **Costruire questo oggetto e' una dichiarazione**: «la firma e' valida,
 * il destinatario siamo noi, non e' scaduto». Nessun consumatore ricontrolla
 * niente — e' esattamente il punto di avere un tipo dedicato invece di passare
 * in giro l'array grezzo del token, dove ogni chiamante dovrebbe ricordarsi
 * quali campi ha gia' guardato qualcun altro.
 */
final readonly class VerifiedSocialUser
{
    public function __construct(
        public SocialProvider $provider,

        /**
         * Il `sub` del token: l'unico identificativo stabile.
         *
         * ⚠️ **Non e' l'email.** Le email cambiano, e con «Nascondi la mia
         * email» di Apple cambiano anche senza che l'utente faccia niente.
         */
        public string $providerUserId,

        public ?string $email = null,

        /**
         * Se il fornitore garantisce che quell'email e' davvero della persona.
         *
         * 🚨 E' la condizione che permette di **collegare** l'identita' a un
         * account gia' esistente. Senza, si crea un account nuovo e basta.
         */
        public bool $emailVerified = false,

        /** Apple lo manda **solo alla primissima autorizzazione**. */
        public ?string $name = null,
    ) {}
}
