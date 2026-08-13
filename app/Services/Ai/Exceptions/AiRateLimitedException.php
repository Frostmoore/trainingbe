<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Il fornitore ci ha rallentati.
 *
 * 503 con `Retry-After`: e' un limite **loro**, temporaneo, e l'app deve
 * riprovare da sola invece di mostrare un errore. Confonderlo con il 429 delle
 * quote (`AiQuotaExceededException`) farebbe riprovare all'infinito una cosa che
 * non si sblocca fino al mese prossimo.
 */
class AiRateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds = 30,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Il servizio di intelligenza artificiale e\' momentaneamente occupato.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            $previous,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'ai_rate_limited',
            'message' => $this->getMessage(),
            'retry_after' => $this->retryAfterSeconds,
        ], Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => (string) $this->retryAfterSeconds,
        ]);
    }
}
