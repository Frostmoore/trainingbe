<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\RecoveryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il pacchetto incartato della chiave maestra — S6.2.
 *
 * ── Cosa fa questo controller, detto con precisione ────────────────────────
 *
 * **Conserva byte e li ridà.** Non sa cosa contengono, non puo' saperlo, e non
 * deve esistere nessun percorso di codice che provi a scoprirlo.
 *
 * 🚨 **La password di recupero non arriva mai qui.** La derivazione avviene sul
 * telefono: se la password toccasse il server anche per un istante — anche solo
 * per «validarla» — l'intero schema non servirebbe a niente, perche' un server
 * compromesso in quel momento leggerebbe tutto.
 *
 * ⚠️ **`show()` restituisce materiale attaccabile offline.** Chi ha un token di
 * sessione valido puo' scaricarsi il proprio pacchetto e provare le password
 * con calma sul proprio computer. E' inevitabile — senza, non si recupera un
 * account da un telefono nuovo — ed e' esattamente il motivo per cui il costo di
 * Argon2id e' scelto e non lasciato al default.
 */
class RecoveryKeyController extends Controller
{
    /**
     * Il pacchetto, se c'e'.
     *
     * 🚨 **204 e non 404, e la differenza conta.** «Non hai ancora creato una
     * password di recupero» non e' un errore: e' lo stato normale di chi si e'
     * appena registrato. Un 404 costringerebbe l'app a trattare il caso comune
     * dentro un ramo di gestione errori, che e' il posto in cui le cose si
     * dimenticano.
     */
    public function show(Request $request): JsonResponse
    {
        $pacchetto = RecoveryKey::query()
            ->where('user_id', $request->user()->getKey())
            ->first();

        if ($pacchetto === null) {
            return response()->json(null, 204);
        }

        return response()->json(['data' => $pacchetto->toApiArray()]);
    }

    /**
     * Crea il pacchetto o lo sostituisce.
     *
     * 🚨 **Sostituire e' anche il cambio password**, e per il server sono la
     * stessa identica operazione: l'app riapre il pacchetto con la vecchia
     * password, lo richiude con la nuova, e manda qui il risultato. Il server non
     * vede la differenza — ed e' giusto cosi', perche' la verifica della vecchia
     * password **e' crittografica, non di autorizzazione**: senza quella, il
     * pacchetto non si sarebbe potuto riaprire.
     *
     * ⚠️ **Non c'e' nessun controllo che il pacchetto nuovo contenga la stessa
     * chiave maestra di prima**, e non ce ne puo' essere: sono byte cifrati.
     * Chi manda qui un pacchetto diverso perde l'accesso ai propri messaggi
     * vecchi — ed e' la ragione per cui l'app deve **sempre** passare dal
     * riaprire quello vecchio, mai generare una chiave maestra nuova su un
     * account che ne ha gia' una.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:255'],
            'kdf' => ['required', 'string', 'max:32'],

            // I limiti non sono estetici: un `ops_limit` a zero o un
            // `mem_limit` ridicolo renderebbero il pacchetto forzabile in
            // minuti. Il server non puo' verificare che il KDF sia stato
            // *eseguito*, ma puo' rifiutarsi di conservare parametri che
            // dichiarano di non averlo fatto sul serio.
            'ops_limit' => ['required', 'integer', 'min:2'],
            'mem_limit' => ['required', 'integer', 'min:33554432'],

            'salt' => ['required', 'string', 'max:64'],
            'nonce' => ['required', 'string', 'max:64'],
            'wrapped_key' => ['required', 'string', 'max:255'],
        ]);

        $pacchetto = RecoveryKey::updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            $dati,
        );

        return response()->json(['data' => $pacchetto->toApiArray()], 201);
    }
}
