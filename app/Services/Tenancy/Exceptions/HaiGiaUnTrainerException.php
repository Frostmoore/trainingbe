<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 👥 **Questa persona ha già un trainer** — 3b-AH.1-ter.4, 17/09/2026.
 *
 * ══ 📌 COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * ⛔ **Il secondo rapporto nasceva in silenzio.** Una persona seguita da Anna
 * accettava l'invito di Marco e si ritrovava due trainer senza che nessuno
 * glielo avesse detto.
 *
 * 🚨 E non è una questione di cortesia: da quel momento **tutti e due** vedono i
 * suoi allenamenti, **tutti e due** le scrivono, e **tutti e due** le tengono
 * occupato uno slot del proprio abbonamento. ⚠️ Tre conseguenze che una persona
 * ha il diritto di conoscere **prima**, non di scoprire aprendo la chat.
 *
 * ══ 🚨 PERCHE' IL SERVER RIFIUTA INVECE DI FIDARSI DELL'APP ═══════════════
 *
 * 💡 La modale la disegna l'app, ma se il permesso di procedere lo desse l'app,
 * un client vecchio — o uno che non conosce questa regola — creerebbe la
 * sovrapposizione **senza chiedere niente a nessuno**. ⛔ E non darebbe errore:
 * funzionerebbe.
 *
 * 🎯 Quindi il server si ferma, dice **chi** è l'altro trainer, e riparte solo
 * con un sì esplicito (`affianco`).
 *
 * ══ ⚠️ 409 E NON 403 ══════════════════════════════════════════════════════
 *
 * Non è un permesso che manca: è uno **stato del mondo** che rende la richiesta
 * ambigua. 💡 `409 Conflict` è esattamente questo, e dice all'app *«rifai la
 * stessa richiesta dopo aver deciso»* invece di *«non puoi»*.
 */
class HaiGiaUnTrainerException extends RuntimeException
{
    /** 🚨 Il contratto con l'app. Non cambia insieme alla frase. */
    public const CODICE = 'hai_gia_un_trainer';

    public function __construct(private readonly string $chiTiSegue)
    {
        parent::__construct(
            "Ti segue già {$chiTiSegue}.",
            Response::HTTP_CONFLICT,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => self::CODICE,
            'code' => self::CODICE,
            'message' => $this->getMessage(),

            /*
             * 💡 **Il nome serve alla domanda**, e l'app da sola non ce l'ha:
             * sta guardando un invito, non l'elenco dei propri trainer.
             * ⚠️ Solo il nome — non l'id, non l'email: per scrivere una frase
             * basta quello, e il resto sarebbe roba di un'altra persona data a
             * chi non l'ha chiesta.
             */
            'chi_ti_segue' => $this->chiTiSegue,
        ], Response::HTTP_CONFLICT);
    }
}
