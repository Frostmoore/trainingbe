<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

use App\Enums\SocialProvider;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;

/**
 * Verifica un token d'identita' e dice chi c'e' dietro.
 *
 * 🚨 **Il contratto e' «o e' valido, o si lancia».** Nessun ritorno `null`,
 * nessun booleano da controllare: un valore di ritorno che si puo' ignorare,
 * su un percorso di autenticazione, prima o poi viene ignorato — e quel giorno
 * chiunque entra con un token inventato.
 */
interface SocialTokenVerifier
{
    /**
     * @throws InvalidSocialTokenException se firma, destinatario, scadenza o
     *                                     emittente non tornano
     */
    public function verify(SocialProvider $provider, string $idToken): VerifiedSocialUser;

    /**
     * Se questo fornitore e' configurato (client id presente).
     *
     * ⚠️ Serve a non mostrare nell'app un pulsante che non puo' funzionare: un
     * «Accedi con Apple» che risponde sempre errore fa sembrare rotta tutta
     * l'applicazione, non solo quel pulsante.
     */
    public function configured(SocialProvider $provider): bool;
}
