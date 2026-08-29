<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'invito di una palestra, dal lato di chi lo riceve — 3b-V.2.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«a chi ci clicca si deve aprire l'app in una pagina con la descrizione della
 * palestra, il logo, i colori, un messaggio di congratulazioni, le cose a cui
 * avrà accesso e due tasti, uno per accettare e uno per rifiutare»*.
 *
 * ══ 🚨 DUE ROTTE SU TRE SONO PUBBLICHE, E VA MOTIVATO ═════════════════════
 *
 * | Rotta | Autenticata | Perche' |
 * |---|---|---|
 * | `GET /inviti-palestra/{token}` | ⛔ no | chi tocca il link **non ha ancora l'app**: se chiedesse l'accesso, la prima cosa che vedrebbe sarebbe un modulo invece dell'invito |
 * | `POST …/rifiuta` | ⛔ no | chi non vuole entrare **non deve crearsi un account per dire di no** |
 * | `POST …/accetta` | ✅ si | qui una persona ci deve essere: e' lei che entra |
 *
 * 🚨 **Il token e' la credenziale.** Trentadue caratteri casuali, monouso, a
 * scadenza: chi ce l'ha e' chi l'ha ricevuto. Aggiungere l'autenticazione
 * all'anteprima non proteggerebbe niente e romperebbe l'unico caso che conta.
 *
 * ══ ⛔ E TUTTI I RIFIUTI SONO 404, UGUALI ═════════════════════════════════
 *
 * Scaduto, revocato, gia' usato, rifiutato e mai esistito danno la **stessa**
 * risposta. ⚠️ Distinguerli darebbe a chiunque un modo di sapere quali token
 * sono validi e quali inviti sono stati usati — cioe' un oracolo su chi si e'
 * iscritto dove. E' lo stesso principio gia' scritto su
 * `BrandingController::notFound()`.
 */
class InvitoInPalestraController extends Controller
{
    public function __construct(private readonly InvitiInPalestra $inviti) {}

    /**
     * Cosa c'e' dietro il link — **senza consumarlo**.
     *
     * 🚨 Un'anteprima che brucia l'invito e' un invito che si perde la prima
     * volta che qualcuno lo apre senza decidere. E la gente i link li apre, li
     * chiude e li riapre.
     */
    public function anteprima(string $token): JsonResponse
    {
        $dati = $this->inviti->anteprima($token);

        if ($dati === null) {
            return $this->nonValido();
        }

        return response()->json(['data' => $dati]);
    }

    /** Entra nella palestra. */
    public function accetta(Request $request, string $token): JsonResponse
    {
        $utente = $request->user();

        if (! $utente instanceof User) {
            return $this->nonValido();
        }

        $palestra = $this->inviti->accetta($token, $utente);

        return response()->json(['data' => [
            /*
             * 💡 Si torna il **branding**, non un «ok»: l'app deve ridipingersi
             * dei colori della palestra nell'istante in cui si entra, e senza
             * questi dati dovrebbe fare una seconda richiesta per una cosa che
             * il server sa gia'.
             */
            'palestra' => $palestra->branding(),
        ]]);
    }

    /**
     * Dice di no, e l'invito si brucia.
     *
     * ⚠️ **Risponde sempre `204`**, anche per un token che non e' mai esistito.
     * 🚨 Rispondere `404` a quelli falsi e `204` a quelli veri trasformerebbe
     * questo endpoint in un modo per **provare token a tappeto** e sapere quali
     * sono buoni — con l'aggravante che ogni tentativo azzeccato brucerebbe
     * l'invito di qualcuno.
     */
    public function rifiuta(string $token): JsonResponse
    {
        $this->inviti->rifiuta($token);

        return response()->json(status: 204);
    }

    /**
     * L'unica risposta negativa possibile.
     *
     * Un solo metodo perche' non ci sia modo di introdurre per distrazione due
     * varianti leggermente diverse, che e' esattamente come nascono gli oracoli.
     */
    private function nonValido(): JsonResponse
    {
        return response()->json([
            'message' => __('Questo invito non è più valido.'),
            'code' => 'invite_not_found',
        ], 404);
    }
}
