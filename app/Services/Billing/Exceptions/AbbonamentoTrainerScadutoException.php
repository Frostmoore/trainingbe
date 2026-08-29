<?php

declare(strict_types=1);

namespace App\Services\Billing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le funzioni da trainer sono chiuse perche' l'abbonamento e' scaduto — U.3.1.
 *
 * ══ 🚨 PERCHE' UN CODICE E NON UN `abort(403, 'messaggio')` ═══════════════
 *
 * 📌 Il committente, il 29/08: *«E' una merda se esce solo un errore, si deve
 * capire che e' perche' non ha pagato»*.
 *
 * ⛔ Un 403 con dentro solo una frase costringe l'app a **riconoscere il testo**
 * per capire cos'e' successo. E il giorno che qualcuno corregge una virgola in
 * quella frase, l'app smette di riconoscerlo — e torna a mostrare l'errore
 * generico, senza che nessun test se ne accorga.
 *
 * 💡 Un codice stabile (`trainer_subscription_expired`) e' un contratto: la
 * frase si puo' riscrivere quando si vuole, il codice no.
 *
 * ── ⚠️ 403 e non 402, anche se si tratta di soldi ─────────────────────────
 *
 * Il `402` di `GettoniEsauritiException` dice *«compra e riprova subito»*: e' un
 * saldo da ricaricare, e l'azione e' immediata. Qui invece l'abbonamento e'
 * scaduto: la persona **non e' autorizzata** finche' non rinnova, che e' un
 * rapporto commerciale, non una ricarica.
 *
 * 🚨 **E il messaggio dice cosa NON si perde.** Un trainer che vede sparire «i
 * miei utenti» pensa di aver perso il lavoro di mesi: le sue schede restano sue
 * e continua a usarle da utente, e va detto nella stessa frase.
 */
class AbbonamentoTrainerScadutoException extends RuntimeException
{
    /** 🚨 Il contratto con l'app. Non cambia insieme alla frase. */
    public const CODICE = 'trainer_subscription_expired';

    public function __construct()
    {
        parent::__construct(
            'Il tuo abbonamento da trainer è scaduto. Le tue schede restano tue: '
            .'puoi continuare a usarle come chiunque altro.',
            Response::HTTP_FORBIDDEN,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => self::CODICE,

            /*
             * ⚠️ **`code` E `error`, con lo stesso valore.** Non e' una svista:
             * l'app legge `code` per i 403 (`tenant_inactive` arriva cosi') e
             * `error` per i 402 dei gettoni. Mandarne uno solo vorrebbe dire
             * scegliere quale dei due lettori far fallire.
             */
            'code' => self::CODICE,
            'message' => $this->getMessage(),
        ], Response::HTTP_FORBIDDEN);
    }
}
