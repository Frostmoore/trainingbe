<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Il fornitore non ha risposto, o ha risposto male.
 *
 * 502 e non 500: il guasto non e' nostro, e la distinzione conta per chi legge i
 * monitoraggi. Il messaggio verso il client e' generico di proposito — quello
 * del fornitore puo' contenere identificativi di richiesta e nomi di modello,
 * che non hanno motivo di uscire.
 */
class AiUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'ai_unavailable',
        string $message = 'Il servizio di intelligenza artificiale non e\' raggiungibile.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, Response::HTTP_BAD_GATEWAY, $previous);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], Response::HTTP_BAD_GATEWAY);
    }
}
