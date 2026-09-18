<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🏛️ **Questa persona è già iscritta a un'altra palestra** — regola 8.1.4,
 * 18/09/2026.
 *
 * ══ 📌 LA REGOLA ══════════════════════════════════════════════════════════
 *
 * > *«Assicurati che un allievo possa essere iscritto a una sola palestra. Nel
 * > caso di invito a una seconda palestra, la pagina dell'invito gli deve
 * > chiedere se desidera lasciare la palestra precedente.»*
 *
 * ══ 🚨 COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * ⛔ «Una palestra sola» reggeva già — ma col rifiuto secco *«Sei già iscritto a
 * una palestra. Per cambiarla, parlane con loro.»* Nessuna domanda, nessuna
 * strada: chi aveva un invito buono in mano restava fuori.
 *
 * ⚠️ E quel rifiuto era **deciso apposta**, scritto dentro `UnisciAUnaPalestra`:
 * *«spostare un iscritto da una palestra a un'altra è un'operazione commerciale,
 * non una scelta dell'utente»*. 💡 La regola nuova prevale, e ha senso: qui si
 * parla di un **invito**, che la palestra nuova ha emesso apposta — non del
 * codice digitato di nascosto. ⛔ Il `join_code` infatti **resta rifiutato**.
 *
 * ══ 🚨 PERCHE' IL SERVER SI FERMA INVECE DI FIDARSI DELL'APP ══════════════
 *
 * Stessa ragione di `HaiGiaUnTrainerException`: se il permesso di procedere lo
 * desse l'app, un client vecchio farebbe il cambio **senza chiedere niente a
 * nessuno** — e non darebbe errore. Quindi il server si ferma, dice **quale**
 * palestra, e riparte solo con un sì esplicito (`lascia_la_precedente`).
 *
 * ⚠️ **409 e non 403**: non manca un permesso, c'è uno stato del mondo che rende
 * la richiesta ambigua.
 */
class SeiGiaInUnaPalestraException extends RuntimeException
{
    /** 🚨 Il contratto con l'app. Non cambia insieme alla frase. */
    public const CODICE = 'sei_gia_in_una_palestra';

    public function __construct(private readonly string $palestraAttuale)
    {
        parent::__construct(
            "Sei già iscritto a {$palestraAttuale}.",
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
             * 💡 **Il nome serve alla domanda**: l'app sta guardando l'invito
             * della palestra nuova, e di quella vecchia non sa niente. ⚠️ Solo
             * il nome — è la palestra di questa stessa persona, e per scrivere
             * «vuoi lasciare X?» basta quello.
             */
            'palestra_attuale' => $this->palestraAttuale,
        ], Response::HTTP_CONFLICT);
    }
}
