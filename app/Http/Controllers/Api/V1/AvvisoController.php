<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AzioneDellAvviso;
use App\Http\Controllers\Controller;
use App\Models\Avviso;
use App\Models\AvvisoDecisione;
use App\Services\Avvisi\QualeAvvisoMostrare;
use App\Services\Promozioni\ConsegnaDeiPremi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 📣 **Gli avvisi in-app, lato app** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«facendo apparire una modale in-app con un tasto
 * per approvare … e uno per rifiutare. Il tasto per approvare deve poter fare
 * una serie di cose: dismiss, link, promo, e logga. Rifiuta chiude sempre la
 * modale e logga il rifiuto»*.
 *
 * ══ 🚨 DUE ROTTE, E LA SECONDA FA IL LAVORO VERO ════════════════════════════
 *
 * | Rotta | Cosa fa |
 * |---|---|
 * | `GET avvisi/prossimo` | *«ce n'è uno?»* — **uno solo**, il più vecchio non deciso |
 * | `POST avvisi/{avviso}/decisione` | registra la risposta **ed esegue l'azione** |
 *
 * ⛔ **L'azione `promo` si esegue QUI, non nell'app**: farla eseguire al
 * telefono vorrebbe dire lasciare che sia il telefono a dire *«assegnami questa
 * promozione»*.
 */
class AvvisoController extends Controller
{
    public function __construct(
        private readonly QualeAvvisoMostrare $quale,
        private readonly ConsegnaDeiPremi $premi,
    ) {}

    /**
     * Ce n'è uno da mostrare?
     *
     * 💡 Risponde **200 con `data: null`** e non 404: «non ce n'è» è una
     * risposta, non un errore, e un 404 in coda a ogni avvio dell'app
     * sporcherebbe ogni registro di monitoraggio.
     */
    public function prossimo(Request $request): JsonResponse
    {
        $avviso = $this->quale->per($request->user());

        if ($avviso === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $avviso->getKey(),
            'titolo' => $avviso->titolo,
            'testo' => $avviso->testo,
            'tasto_approva' => $avviso->tasto_approva,
            'tasto_rifiuta' => $avviso->tasto_rifiuta,

            /*
             * ⚠️ L'app sa **cosa succederà**, perché per `link` deve aprire il
             * browser. ⛔ Ma per `promo` non le si dice quale promozione: non le
             * serve, e saperlo le darebbe da eseguire una cosa che esegue il
             * server.
             */
            'azione' => $avviso->azione->value,
            'url' => $avviso->azione === AzioneDellAvviso::Link ? $avviso->url : null,
        ]]);
    }

    /**
     * La risposta della persona.
     *
     * 🚨 **Si registra sempre**, in tutti e due i versi: *«il numero di chi dice
     * di no è metà del motivo per cui si manda un avviso»*. ⚠️ Ed è anche la
     * difesa il giorno in cui qualcuno dice *«non ho mai accettato»*.
     *
     * 💡 **Decisa una volta, quella modale non ricompare** — e la memoria sta
     * qui sul server, non nelle preferenze del telefono: chi entra dal secondo
     * dispositivo non se la deve ripigliare.
     */
    public function decisione(Request $request, int $avviso): JsonResponse
    {
        $dati = $request->validate([
            'decisione' => ['required', 'string', 'in:'.AvvisoDecisione::APPROVATO.','.AvvisoDecisione::RIFIUTATO],
        ]);

        $utente = $request->user();

        $ilAvviso = Avviso::query()->find($avviso);

        if ($ilAvviso === null) {
            return response()->json(['message' => __('Questo avviso non esiste.')], 404);
        }

        $riga = AvvisoDecisione::query()->firstOrCreate(
            ['avviso_id' => $ilAvviso->getKey(), 'user_id' => $utente->getKey()],
            ['mostrato_il' => Carbon::now()],
        );

        /*
         * ⛔ **Una decisione non si cambia.** 🚨 Senza questa riga, ripremere
         * «approva» su un avviso con azione `promo` regalerebbe la promozione
         * una seconda volta — e la terza, e la quarta.
         */
        if ($riga->deciso_il !== null) {
            return response()->json([
                'message' => __('Hai già risposto a questo avviso.'),
                'code' => 'gia_deciso',
            ], 409);
        }

        $riga->forceFill([
            'decisione' => $dati['decisione'],
            'deciso_il' => Carbon::now(),
        ])->save();

        $fatto = null;

        if ($dati['decisione'] === AvvisoDecisione::APPROVATO
            && $ilAvviso->azione === AzioneDellAvviso::Promo
            && $ilAvviso->promozione !== null) {
            /*
             * 🎁 **La promozione si attiva qui**, con la stessa strada del link
             * (`ConsegnaDeiPremi::attiva`): stesse regole su massimi, budget e
             * cumulabilità. ⛔ Una seconda strada avrebbe due contabilità.
             */
            $assegnazione = $this->premi->attiva($ilAvviso->promozione, $utente);

            $fatto = $assegnazione !== null ? 'promozione_attivata' : 'promozione_non_applicabile';
        }

        return response()->json(['data' => [
            'deciso' => $dati['decisione'],
            'esito' => $fatto,
        ]]);
    }
}
