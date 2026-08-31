<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\Listino;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Billing\Stripe\Cassa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Il listino per l'app, e l'apertura del pagamento — 3b-H, 26/08/2026.
 *
 * ── 🚨 PERCHE' IL LISTINO LO MANDA IL SERVER ──────────────────────────────
 *
 * Perche' fino a oggi i prezzi e i tagli erano **scritti a mano dentro la
 * schermata dell'app** («400 richieste al mese»), mentre il server ne diceva
 * altri due (300 nel listino, 450 nel piano). ⛔ Tre numeri per la stessa cosa,
 * e quello che il cliente legge era l'unico che nessuno poteva correggere senza
 * pubblicare una versione sugli store.
 *
 * 💡 Adesso l'app **chiede**: cambiare un prezzo e' una riga di configurazione.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly Listino $listino,
        private readonly PianoAttivo $piano,
        private readonly PortafoglioGettoni $portafoglio,
        private readonly Cassa $cassa,
    ) {}

    /** Cosa si puo' comprare, e cosa si ha gia'. */
    public function listino(Request $request): JsonResponse
    {
        $utente = $request->user();

        return response()->json(['data' => [
            /*
             * 🚨 **`abbonato` decide cosa la schermata vende per primo.** Chi
             * non lo e' vede l'abbonamento; chi lo e' vede i gettoni — offrire
             * l'abbonamento a chi ce l'ha gia' e' il modo piu' rapido per far
             * credere che non sia attivo.
             */
            'abbonato' => $this->piano->eAbbonato($utente),
            'livello' => $this->piano->livello($utente),

            'abbonamento' => [
                'prezzo_cent' => $this->listino->singolo(),
                'chiamate_mensili' => $this->listino->chiamateMensili(),
            ],

            'pacchetti' => array_map(
                static fn (array $p): array => [
                    'gettoni' => $p['gettoni'],
                    'prezzo_cent' => $p['prezzo'],
                    'nota' => $p['nota'],
                ],
                $this->listino->pacchettiGettoni(),
            ),

            'gettoni_disponibili' => $this->portafoglio->saldo($utente),

            /*
             * ══ 🔁 QUANDO SCADE, E SE SI RINNOVA — 3b-H.9 ═════════════════
             *
             * 🚨 **Le due cose insieme, o la frase mente.** «Fino al 27
             * settembre» detto a chi si rinnova suona come una scadenza e fa
             * disdire per sbaglio; «si rinnova» detto a chi ha gia' disdetto e'
             * peggio ancora.
             *
             * ⚠️ `null` quando l'abbonamento non viene da Stripe — le palestre —
             * o quando non ce n'e' nessuno. 💡 L'app in quel caso non scrive
             * niente invece di inventare una data.
             */
            'abbonamento_attivo' => $this->abbonamentoAttivo($utente),
        ]]);
    }

    /**
     * @return array{fino_al: string|null, rinnova: bool, gestibile: bool}|null
     */
    private function abbonamentoAttivo(User $utente): ?array
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();

        if ($riga === null) {
            return null;
        }

        return [
            'fino_al' => $riga->ends_at?->toDateString(),
            'rinnova' => (bool) $riga->rinnova,

            // 💡 Solo chi viene da Stripe ha qualcosa da gestire: alle palestre
            // il pulsante non deve nemmeno comparire.
            'gestibile' => $riga->stripe_customer_id !== null,
        ];
    }

    /**
     * Apre il portale di Stripe: disdetta, carta, ricevute.
     *
     * ⛔ **Non e' il posto dove si disdice**: e' il posto dove si va a disdire.
     * La differenza conta, perche' tutto quello che succede la' dentro torna
     * indietro dai webhook — e non da questa risposta.
     */
    public function portale(Request $request): JsonResponse
    {
        try {
            $url = $this->cassa->portale(
                $request->user(),
                rtrim((string) config('app.url'), '/').'/pagamento/ok',
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Non sono riuscito ad aprire la gestione. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Apre una sessione di pagamento e dice dove andare.
     *
     * ⚠️ **Il client sceglie COSA, non QUANTO.** Il taglio arriva da qui, il
     * prezzo lo mette il server: vedi `Cassa::prezzoDelPacchetto`.
     */
    public function checkout(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'tipo' => ['required', Rule::in([Cassa::ABBONAMENTO, Cassa::GETTONI])],

            /*
             * 💡 `required_if` e non `nullable`: chiedere dei gettoni senza dire
             * quanti e' una richiesta incompleta, e farla passare vorrebbe dire
             * scoprirlo dentro la cassa — cioe' dopo aver gia' parlato con
             * Stripe.
             */
            'gettoni' => ['required_if:tipo,'.Cassa::GETTONI, 'integer'],
        ]);

        $tipo = $dati['tipo'];

        // ⛔ Non si vende due volte la stessa cosa.
        if ($tipo === Cassa::ABBONAMENTO && $this->piano->eAbbonato($request->user())) {
            return response()->json([
                'message' => 'Hai gia\' un abbonamento attivo.',
            ], 409);
        }

        try {
            $url = $this->cassa->apri(
                $request->user(),
                $tipo,
                isset($dati['gettoni']) ? (int) $dati['gettoni'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            /*
             * ⚠️ Il messaggio di Stripe non si rimanda al client: puo'
             * contenere id interni e nomi di chiavi. 💡 Nel log ci va per
             * intero, perche' e' l'unico posto dove serve.
             */
            report($e);

            return response()->json([
                'message' => 'Il pagamento non si e\' aperto. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }
}
