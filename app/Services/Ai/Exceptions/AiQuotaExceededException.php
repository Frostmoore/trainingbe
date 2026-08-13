<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
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
    /**
     * @param  ?int  $capCalls  il tetto raggiunto, in chiamate (G2, D6)
     * @param  bool  $soloFoto  se a finire e' stato il sotto-limite delle
     *                          chiamate con allegato e non quello generale
     */
    public function __construct(
        public readonly Carbon $resetsAt,
        public readonly ?int $capCalls = null,
        public readonly bool $soloFoto = false,
    ) {
        parent::__construct(
            /*
             * 💡 **Due messaggi, e la differenza conta.** Dire «hai finito le
             * chiamate» a chi ne ha ancora trecento ma ha esaurito le foto e'
             * un messaggio che sembra un guasto: quella persona **sa** di non
             * averle finite, e da li' in poi non crede piu' a nessun messaggio
             * di quota.
             */
            $soloFoto
                ? 'Hai esaurito le stime da foto di questo mese. Le altre funzioni restano disponibili.'
                : 'Il limite mensile di intelligenza artificiale e\' stato raggiunto.',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            /*
             * ⚠️ **Il codice `ai_quota_exceeded` resta quello di prima.** L'app
             * lo tratta gia', e cambiarlo vorrebbe dire che una versione
             * installata sui telefoni smette di riconoscere un errore che
             * riconosceva — cioe' lo mostra come guasto generico.
             */
            'error' => 'ai_quota_exceeded',
            'message' => $this->getMessage(),
            'resets_at' => $this->resetsAt->toIso8601String(),
            // 💡 Il dettaglio nuovo e' additivo: chi non lo legge sta come prima.
            'photo_only' => $this->soloFoto,
        ], Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) max(1, (int) now()->diffInSeconds($this->resetsAt)),
        ]);
    }
}
