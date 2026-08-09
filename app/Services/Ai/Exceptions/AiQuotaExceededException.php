<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La palestra ha esaurito i token AI del mese.
 *
 * 🚨 **429 e non 402, ed e' una decisione di prodotto.**
 * Per l'app e' un limite di frequenza sulla risorsa AI, non un problema di
 * pagamento dell'utente: l'iscritto non ha una carta nel nostro sistema e non
 * puo' fare niente per sbloccarsi. Il 402 sarebbe corretto **verso la palestra**,
 * ma quel canale e' il pannello, non l'API.
 *
 * `resets_at` c'e' perche' un limite senza una data di sblocco costringe l'app a
 * indovinare quando riprovare.
 */
class AiQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly Carbon $resetsAt,
        public readonly ?int $capTokens = null,
    ) {
        parent::__construct(
            'Il limite mensile di intelligenza artificiale di questa palestra e\' stato raggiunto.',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'ai_quota_exceeded',
            'message' => $this->getMessage(),
            'resets_at' => $this->resetsAt->toIso8601String(),
        ], Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) max(1, (int) now()->diffInSeconds($this->resetsAt)),
        ]);
    }
}
