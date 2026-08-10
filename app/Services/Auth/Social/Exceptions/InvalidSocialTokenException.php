<?php

declare(strict_types=1);

namespace App\Services\Auth\Social\Exceptions;

use RuntimeException;

/**
 * Il token non e' valido, o non e' per noi.
 *
 * 🚨 **Il messaggio non arriva mai all'utente cosi' com'e'.** «Firma non
 * valida», «destinatario sbagliato» e «token scaduto» sono informazioni utili a
 * chi sta provando ad entrare: il controller le registra nei log e all'app
 * risponde con la stessa frase generica che da' per una password sbagliata.
 * E' lo stesso principio del resto di `AuthController`.
 */
class InvalidSocialTokenException extends RuntimeException {}
