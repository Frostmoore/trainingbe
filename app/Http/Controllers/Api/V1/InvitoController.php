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
     * `POST /api/v1/inviti/{token}/riscatta`
     *
     * 🚨 **Non chiede l'abbonamento**, ed e' la decisione del committente:
     * *«il link d'invito assegna chi lo usa — anche se non abbonato — a chi
     * l'ha creato»*. Diventare allievo di un trainer apre la chat illimitata
     * **con lui**: e' un rapporto vero, non un contatto a freddo.
     */
    public function riscatta(Request $request, string $token): JsonResponse
    {
        $utente = $this->inviti->riscattaPerChiEsiste($token, $request->user());

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
