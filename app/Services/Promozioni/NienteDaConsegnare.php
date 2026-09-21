<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use RuntimeException;

/**
 * Interna a `ConsegnaDeiPremi`: annulla la transazione di una consegna che non
 * ha dato niente (budget finito), così non resta un'assegnazione vuota.
 */
final class NienteDaConsegnare extends RuntimeException {}
