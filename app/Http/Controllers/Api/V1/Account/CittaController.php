<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\Comune;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La citta' della persona — 18/08/2026. **M1.2.**
 *
 * ── 🚨 Perche' sta su `users` e non su `profiles` ──────────────────────────
 *
 * `profiles` contiene sesso, data di nascita, altezza, obiettivo: i dati con cui
 * si calcola il fabbisogno. ⚠️ Sono **dati sanitari** — art. 9 — e stanno sotto
 * un consenso dedicato. La citta' non lo e', e mettercela dentro la
 * trascinerebbe sotto lo stesso regime: chi revoca il consenso ai dati di salute
 * si troverebbe a perdere anche il campo con cui trova una palestra.
 *
 * 💡 Su `users` sta insieme al fuso orario, che e' il caso analogo gia' risolto:
 * un segnale debole di posizione, dichiarato nell'informativa, con base
 * giuridica l'esecuzione del contratto.
 *
 * ── 🚨 Non e' obbligatoria, e non lo diventera' ────────────────────────────
 *
 * Si puo' azzerare mandando `null`. ⚠️ Chi non vuole dire dove sta continua a
 * usare l'applicazione intera: perde l'ordinamento per vicinanza nel catalogo,
 * che e' un servizio che gli si offre — **non un pedaggio per entrare**.
 *
 * 💡 Ed e' per questo che qui non c'e' nessun `required`: un campo che si puo'
 * solo riempire e mai svuotare e' un campo obbligatorio scritto male.
 */
class CittaController extends Controller
{
    /**
     * `PUT /api/v1/account/citta`
     *
     * 🚨 La validazione e' `Rule::exists` sui **comuni attivi**: un `comune_id`
     * arbitrario passerebbe la chiave esterna solo per caso, e un comune spento
     * (fuso in un altro) non deve poter essere **scelto** — anche se chi ce
     * l'aveva gia' se lo tiene. Sono due cose diverse, e questa e' la scelta.
     *
     * ⚠️ `PUT` e non `PATCH`, come per il fuso orario: l'app manda lo stato
     * completo del campo, `null` compreso. Con `PATCH` «assente» e «vuoto»
     * sarebbero indistinguibili, e non ci sarebbe modo di cancellare la citta'.
     */
    public function update(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'comune_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('comuni', 'id')->where('attivo', true),
            ],
        ]);

        $utente = $request->user();
        $nuovo = $dati['comune_id'] !== null ? (int) $dati['comune_id'] : null;

        /*
         * ⚠️ Si scrive **solo se e' cambiato**, per la stessa ragione del fuso
         * orario: senza, ogni salvataggio del profilo muove `updated_at` e la
         * colonna smette di rispondere alla domanda per cui esiste.
         */
        if ($utente->comune_id !== $nuovo) {
            $utente->forceFill(['comune_id' => $nuovo])->save();
        }

        return response()->json(['data' => $this->rappresenta($utente->comune)]);
    }

    /** `GET /api/v1/account/citta` — quella scelta adesso, o `null`. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rappresenta($request->user()->comune)]);
    }

    /** @return array<string, mixed>|null */
    private function rappresenta(?Comune $c): ?array
    {
        if ($c === null) {
            return null;
        }

        return [
            'id' => $c->id,
            'nome' => $c->nome,
            'provincia' => $c->provincia,
            'regione' => $c->regione,
            'esteso' => $c->esteso(),

            /*
             * 💡 Se il comune scelto e' stato spento da un import successivo,
             * l'app deve poterlo dire — «il tuo comune e' stato accorpato,
             * scegline un altro» — invece di mostrarlo come se fosse normale e
             * lasciare la persona fuori da ogni ricerca senza spiegazione.
             */
            'attivo' => $c->attivo,
        ];
    }
}
