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
 * | `GET promozioni` | le offerte di questa persona, visibili nell'app (e quelle già sue) |
 * | `POST promozioni/verifica` | «Hai un codice?»: vale per me? — prima di pagare |
 * | `POST promozioni/{codice}/riscatta` | prende i regali di un'offerta col link o col codice |
 *
 * 🚨 La verifica **non** è il pagamento: al checkout il codice si riverifica
 * (`BillingController::checkout()` → `ScontiSuStripe`), e un codice sbagliato lì
 * fa pagare a prezzo pieno invece di bloccare.
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

    public function verifica(Request $request): JsonResponse
    {
        $dati = $request->validate(['codice' => ['required', 'string', 'max:40']]);

        /** @var User $utente */
        $utente = $request->user();
        $promozione = app(LePromozioni::class)->conIlCodice($dati['codice'], $utente);

        if ($promozione === null) {
            return response()->json([
                'message' => $this->offerte->perche($dati['codice'], $utente),
                'code' => 'codice_non_valido',
            ], 422);
        }

        return response()->json(['data' => $this->offerte->perLApp($promozione, $utente)]);
    }

    public function riscatta(Request $request, string $codice): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();
        $promozione = Promozione::query()->with(['premi', 'bersagli', 'condizioni'])->where('codice', mb_strtoupper(trim($codice)))->first();

        if ($promozione === null) {
            return response()->json(['message' => 'Questo codice non esiste.', 'code' => 'codice_non_valido'], 422);
        }

        $consegna = app(ConsegnaDeiPremi::class);
        $fatta = $consegna->riscatta($promozione, $utente);

        if ($fatta === null) {
            return response()->json([
                'message' => $consegna->percheNo($promozione->fresh(['premi', 'bersagli', 'condizioni']), $utente) ?? 'Non è stato possibile riscattarla.',
                'code' => 'non_riscattabile',
            ], 422);
        }

        return response()->json(['data' => [
            'gettoni' => $fatta->gettoni,
            'saldo' => (int) User::withoutGlobalScopes()->whereKey($utente->getKey())->value('ai_credits'),
        ]], 201);
    }
}
