<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Services\Auth\Social\SocialTokenVerifier;
use Illuminate\Http\JsonResponse;

/**
 * 🔑 **Quali accessi esterni esistono** — voce 25, 26/09/2026.
 *
 * ══ ⛔ IL DIFETTO CHE QUESTA ROTTA CHIUDE ═══════════════════════════════════
 *
 * 🚨 L'elenco dei fornitori viaggiava **solo** dentro `Tenant::branding()`, e
 * il branding arriva con l'accesso o con l'invito. ⛔ Cioe' **dopo**: chi apre
 * l'app per la prima volta ha `GymBranding.neutral`, il cui elenco e' vuoto, e
 * i pulsanti «Continua con Google» non comparivano **mai**.
 *
 * ⚠️ **Non e' sempre stato cosi'.** C'era `GET branding/lookup?code=`, pubblica,
 * e l'app la chiamava prima della schermata d'accesso. E' stata tolta con
 * **F11 il 24/09/2026** insieme al codice palestra — giustamente, perche' quel
 * codice non esiste piu' — ma con lei se n'e' andata l'unica strada per sapere
 * queste due cose senza essere nessuno.
 *
 * 🚨 **E nessuno se n'e' accorto per due giorni** perche' l'accesso social era
 * spento: l'elenco era vuoto comunque, quindi il sintomo del difetto era
 * identico al comportamento atteso. 💡 E' lo stesso motivo per cui i difetti
 * delle notifiche push sono rimasti nascosti fino al 26/09.
 *
 * ══ ⚖️ PERCHE' PUBBLICA, E PERCHE' NON DICE NIENT'ALTRO ════════════════════
 *
 * 🔑 La domanda «con cosa posso entrare?» **arriva per forza da chi non e'
 * ancora nessuno**: pretendere l'accesso per rispondere sarebbe un cerchio.
 *
 * ⚖️ E la risposta non contiene niente di personale ne' di segreto: dice solo
 * quali fornitori sono configurati. ⛔ **Non** restituisce gli identificativi
 * dei client: quelli servono all'app per costruire la richiesta, non per
 * sapere se il pulsante va disegnato, e darli qui vorrebbe dire pubblicare
 * configurazione senza motivo.
 *
 * 💡 Una rotta sua e non un pezzo di `versione`: sono due domande diverse, e
 * accorparle renderebbe impossibile mettere in cache l'una senza l'altra.
 */
class FornitoriSocialController extends Controller
{
    public function __construct(private readonly SocialTokenVerifier $verifier) {}

    /**
     * I fornitori accesi, come li conosce l'app (`App\Enums\SocialProvider`).
     *
     * ⚠️ **Un elenco, non una mappa di booleani**: l'app deve poter disegnare
     * quello che riceve senza conoscere in anticipo i nomi possibili. ⛔ Con i
     * booleani, aggiungere un fornitore un domani vorrebbe dire pubblicare una
     * versione nuova dell'app perche' sappia di doverlo cercare.
     */
    public function __invoke(): JsonResponse
    {
        $accesi = array_values(array_filter(
            array_map(
                fn (SocialProvider $p): ?string => $this->verifier->configured($p)
                    ? $p->value
                    : null,
                SocialProvider::cases(),
            ),
        ));

        return response()->json(['data' => $accesi]);
    }
}
