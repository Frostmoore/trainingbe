<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Promozione;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Promozioni\LeOfferteDellaPersona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🎁 **Le offerte, per l'app** — 24/09/2026, P8 di `plan_promozioni.md`.
 *
 * | Rotta | Cosa fa |
 * |---|---|
 * | `GET promozioni` | le offerte di questa persona, visibili nell'app (e quelle già attivate) |
 * | `GET promozioni/{codice}` | l'offerta di un **link** aperto nell'app: vale per me? |
 * | `POST promozioni/{codice}/attiva` | la attiva: i regali arrivano, lo sconto resta pronto per il pagamento |
 *
 * ⛔ **Niente codici sconto** (24/09/2026): 📌 *«il codice sconto non deve
 * esistere, le promo si attivano solo in automatico o con il link di
 * attivazione»*. Il `{codice}` qui è quello **dentro il link**, non uno che si
 * scrive: l'app lo riceve aprendo `…/promo/{codice}`.
 */
class PromozioniController extends Controller
{
    public function __construct(private readonly LeOfferteDellaPersona $offerte) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();

        return response()->json([
            'data' => $this->offerte->per($utente, 'app')
                ->map(fn (Promozione $p): array => $this->offerte->perLApp($p, $utente))
                ->all(),
        ]);
    }

    public function mostra(Request $request, string $codice): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();
        $promozione = app(LePromozioni::class)->dalLink($codice, $utente);

        if ($promozione === null) {
            return response()->json([
                'message' => $this->offerte->perche($codice, $utente),
                'code' => 'link_non_valido',
            ], 422);
        }

        return response()->json(['data' => $this->offerte->perLApp($promozione, $utente)]);
    }

    public function attiva(Request $request, string $codice): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();
        $promozione = Promozione::query()->with(['premi', 'bersagli', 'condizioni'])->where('codice', mb_strtoupper(trim($codice)))->first();

        if ($promozione === null) {
            return response()->json(['message' => 'Questo link non porta a nessuna offerta.', 'code' => 'link_non_valido'], 422);
        }

        $consegna = app(ConsegnaDeiPremi::class);
        $fatta = $consegna->attiva($promozione, $utente);

        if ($fatta === null) {
            return response()->json([
                'message' => $consegna->percheNo($promozione->fresh(['premi', 'bersagli', 'condizioni']), $utente) ?? 'Non è stato possibile attivarla.',
                'code' => 'non_attivabile',
            ], 422);
        }

        return response()->json(['data' => [
            'gettoni' => $fatta->gettoni,
            'sconto_pronto' => $fatta->acquisto_in_sospeso,
            'saldo' => (int) User::withoutGlobalScopes()->whereKey($utente->getKey())->value('ai_credits'),
        ]], 201);
    }
}
