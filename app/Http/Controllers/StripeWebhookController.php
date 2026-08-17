<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StripeEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * L'orecchio di Stripe — 17/08/2026.
 *
 * ── 🚨 Perche' la conferma di un pagamento arriva DA QUI ───────────────────
 *
 * E non dal ritorno del browser. ⚠️ Chi torna sulla pagina di successo puo'
 * averci messo l'indirizzo a mano: accreditare gettoni su quella base vuol dire
 * regalarli a chiunque abbia letto quell'URL una volta. Il webhook e' l'unico
 * canale **firmato**, e la firma e' l'unica cosa che un estraneo non sa
 * produrre.
 *
 * ── 📌 Oggi questo controller non fa niente ────────────────────────────────
 *
 * Verifica, registra, risponde `200`. Non accredita, non abbona, non fattura:
 * i flussi non ci sono ancora, ed e' la scelta del committente («solo
 * l'impianto»). 💡 Quando arriveranno, il punto in cui attaccarli e' uno solo e
 * sta gia' qui — e nasce con la difesa dai doppioni gia' addosso, che e' la
 * cosa che non si riesce ad aggiungere dopo.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $segreto = config('services.stripe.webhook_secret');

        /*
         * 🚨 **Senza segreto si chiude, non si passa.**
         *
         * ⚠️ La tentazione, quando manca una configurazione, e' saltare il
         * controllo «tanto in sviluppo non serve». Qui vorrebbe dire lasciare
         * aperto un indirizzo pubblico che accredita denaro: chiunque potrebbe
         * inventarsi un pagamento riuscito mandandoci un JSON.
         */
        if (blank($segreto)) {
            Log::error('Stripe: webhook ricevuto ma STRIPE_WEBHOOK_SECRET non e\' configurato.');

            return response()->json(['message' => 'Webhook non configurato.'], 503);
        }

        try {
            $evento = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                $segreto,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            /*
             * ⚠️ `400` e non `403`: e' quello che Stripe si aspetta, e gli dice
             * di **non** riprovare. Un `500` lo farebbe ritentare per ore su
             * qualcosa che non migliorera' mai.
             *
             * 💡 E il messaggio non dice **perche'** ha fallito: a chi sta
             * provando a indovinare una firma non si regalano indizi.
             */
            Log::warning('Stripe: firma del webhook non valida.', ['errore' => $e->getMessage()]);

            return response()->json(['message' => 'Firma non valida.'], 400);
        }

        try {
            StripeEvent::create([
                'event_id' => $evento->id,
                'type' => $evento->type,
                'payload' => $evento->toArray(),
            ]);
        } catch (QueryException $e) {
            /*
             * 🚨 **Il doppione non e' un errore: e' il caso normale.**
             *
             * Stripe rimanda lo stesso evento quando la nostra risposta tarda o
             * si perde. L'unicita' di `event_id` fa fallire il secondo
             * inserimento, e questo `catch` e' il punto in cui quel fallimento
             * diventa la difesa.
             *
             * ⚠️ Si risponde `200` e non un errore: a Stripe va detto «ce
             * l'ho», altrimenti continua a riprovare.
             */
            if (! $this->eUnDoppione($e)) {
                throw $e;
            }

            return response()->json(['message' => 'Gia\' ricevuto.']);
        }

        // 📌 Qui, e in nessun altro punto, si attaccheranno i flussi.

        return response()->json(['message' => 'Ricevuto.']);
    }

    /**
     * 💡 Si guarda il codice dello stato SQL e non il testo del messaggio: il
     * testo cambia fra MySQL, MariaDB e SQLite, e un confronto su quello
     * funzionerebbe in sviluppo e fallirebbe in produzione.
     */
    private function eUnDoppione(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
