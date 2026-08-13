<?php

declare(strict_types=1);

namespace App\Services\Billing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La quota inclusa e' finita **e** il portafoglio gettoni e' vuoto — G2, D16.
 *
 * ── 🚨 Perche' un codice suo, e non `ai_quota_exceeded` ───────────────────
 *
 * Sono due situazioni diverse per chi le riceve:
 *
 * | Situazione | Codice | Cosa puo' fare |
 * |---|---|---|
 * | La quota del mese e' finita | `ai_quota_exceeded` | Aspettare il mese nuovo |
 * | Quota finita **e** gettoni finiti | `ai_credits_exhausted` | **Comprarne altri, adesso** |
 *
 * ⚠️ Dire «hai raggiunto il limite mensile» a chi ha **comprato** dei gettoni e
 * li ha finiti e' il modo piu' veloce per perderlo: quella persona ha pagato per
 * non avere quel messaggio, e riceverlo lo stesso sembra un imbroglio.
 *
 * 🚨 **402 e non 429**, ed e' l'unico posto del sistema in cui e' giusto. Il
 * `429` di `AiQuotaExceededException` e' motivato dal fatto che l'iscritto non
 * puo' fare niente per sbloccarsi. Qui chi riceve l'errore e' — o e' seguito da
 * — qualcuno che **ha gia' un rapporto commerciale con noi** e puo' ricaricare.
 */
class GettoniEsauritiException extends RuntimeException
{
    public function __construct(
        public readonly int $saldo,
        public readonly int $servivano,
    ) {
        parent::__construct(
            'I gettoni AI sono esauriti. Ricaricali per continuare a usare le funzioni intelligenti.',
            Response::HTTP_PAYMENT_REQUIRED,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'ai_credits_exhausted',
            'message' => $this->getMessage(),
            'saldo' => $this->saldo,
            // 💡 Serve all'interfaccia per dire «te ne servono 6 e ne hai 2»
            // invece del generico «non bastano», che non dice quanto ricaricare.
            'servivano' => $this->servivano,
        ], Response::HTTP_PAYMENT_REQUIRED);
    }
}
