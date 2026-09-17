<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\InvitiDelTrainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il riscatto di un invito da parte di chi ha gia' un account — M6.2, 18/08/2026.
 *
 * ── 🚨 Perche' serve una rotta a parte da `register-with-invite` ───────────
 *
 * Quella **crea** una persona: e' la strada di chi arriva dal link senza avere
 * ancora niente. ⚠️ Il caso che la Parte M ha reso normale e' l'opposto —
 * qualcuno che usa gia' l'app, ha scritto a un trainer dal catalogo, ha finito i
 * tre messaggi e riceve da lui un invito **dentro la chat**. Mandarlo su un
 * modulo di registrazione gli direbbe di crearsi un secondo account.
 *
 * ── 💡 L'invito viaggia in chat, ma il server non lo scrive ────────────────
 *
 * La chat e' cifrata punto a punto: il messaggio con dentro il link lo compone e
 * lo cifra **l'app**, con l'indirizzo che `POST /trainer/invites` restituisce
 * gia' pronto. 🚨 Se il server potesse comporre un messaggio, potrebbe
 * comporne anche altri — e non e' la promessa che abbiamo fatto.
 */
class InvitoController extends Controller
{
    public function __construct(private readonly InvitiDelTrainer $inviti) {}

    /**
     * 🆕 `GET /api/v1/inviti/{token}` — l'anteprima, **pubblica**.
     *
     * ⛔ **Mancava, e con lei mancava tutta la strada**: la pagina web diceva
     * *«apri l'app e tocca "Ho un invito"»* e quel pulsante non esisteva. 🚨 I
     * due endpoint che accettano un invito da trainer non li chiamava nessuno:
     * l'intero giro viveva sul server senza un cliente.
     *
     * ⚠️ **Pubblica**, come quella della palestra: chi tocca il link spesso non
     * ha ancora l'app, e chiedergli di accedere per **guardare** un invito
     * sarebbe il muro che il link esiste per togliere.
     *
     * 💡 **404 per tutti i casi negativi** — scaduto, usato, revocato,
     * inesistente: distinguerli permetterebbe di provare token a tappeto.
     */
    public function anteprima(string $token): JsonResponse
    {
        $dati = $this->inviti->anteprima($token);

        if ($dati === null) {
            return response()->json([
                'message' => __('Questo invito non è più valido.'),
            ], 404);
        }

        return response()->json(['data' => $dati]);
    }

    /**
     * `POST /api/v1/inviti/{token}/riscatta`
     *
     * 🚨 **Non chiede l'abbonamento**, ed e' la decisione del committente:
     * *«il link d'invito assegna chi lo usa — anche se non abbonato — a chi
     * l'ha creato»*. Diventare allievo di un trainer apre la chat illimitata
     * **con lui**: e' un rapporto vero, non un contatto a freddo.
     */
    public function riscatta(Request $request, string $token): JsonResponse
    {
        /*
         * 🆕 👥 **`affianco`: «si', lo so che ne ho gia' uno»** — AH.1-ter.4.
         *
         * ⚠️ Di serie **falso**, ed e' il verso giusto: un client che non
         * conosce questa regola riceve un 409 e si ferma, invece di creare la
         * sovrapposizione senza dire niente a nessuno.
         */
        $dati = $request->validate(['affianco' => ['sometimes', 'boolean']]);

        $utente = $this->inviti->riscattaPerChiEsiste(
            $token,
            $request->user(),
            (bool) ($dati['affianco'] ?? false),
        );

        $trainer = $utente->assignedTrainers()->orderByDesc('trainer_member.assigned_at')->first();

        return response()->json([
            'message' => __('Ora sei seguito da :nome.', ['nome' => $trainer?->name ?? '']),
            'data' => [
                'trainer' => [
                    'id' => $trainer?->id,
                    'name' => $trainer?->name,
                ],
                /*
                 * 💡 `null` = nessun limite. L'app puo' togliere il contatore
                 * dalla schermata senza chiedere altro.
                 */
                'restanti' => null,
            ],
        ]);
    }
}
