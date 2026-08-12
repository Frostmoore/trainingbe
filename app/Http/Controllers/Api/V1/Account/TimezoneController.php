<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Il fuso orario della persona — chiude il debito lasciato aperto da A3.
 *
 * ── 🚨 Perché serve un endpoint, e non si deduce dalla richiesta ───────────
 *
 * Il server **non può indovinare** il fuso di chi chiama. L'IP direbbe dov'è la
 * rete, non dov'è la persona, e sbaglia su ogni VPN e su metà delle connessioni
 * mobili. L'offset che si potrebbe leggere da un timestamp dice `+02:00`, che
 * d'estate è Roma **e anche** Helsinki d'inverno: da un offset non si risale a
 * una regola di ora legale, e senza quella il confine del giorno torna a
 * sbagliare due volte l'anno.
 *
 * L'unico che sa il fuso è il telefono, e quindi lo deve dire.
 *
 * ── ⚠️ È un dato che vale la pena guardare in faccia ───────────────────────
 *
 * Un identificativo IANA è un **segnale debole di posizione**: `Europe/Rome`
 * non dice l'indirizzo di nessuno, ma dice il paese. Il patto è dichiarato
 * nell'informativa, e la ragione per cui si accetta è che senza il prodotto
 * mostra il giorno sbagliato — che è un difetto, non una rifinitura.
 *
 * 💡 **Non serve un consenso separato**: la base giuridica è l'art. 6(1)(b),
 * esecuzione del contratto. Un diario alimentare che apre il giorno sbagliato
 * non esegue il servizio che è stato promesso.
 *
 * ── 💡 Perché `PUT` e non `PATCH` ─────────────────────────────────────────
 *
 * È **idempotente e completo**: l'app manda lo stato attuale del telefono, non
 * una modifica a qualcosa. Chiamarlo dieci volte di fila lascia il sistema come
 * una volta sola, ed è esattamente ciò che farà — a ogni avvio.
 */
class TimezoneController extends Controller
{
    /**
     * Scrive il fuso del dispositivo.
     *
     * 🚨 **La validazione è contro l'elenco IANA vero** (`DateTimeZone::
     * listIdentifiers()`), non contro una regex. Una stringa arbitraria in
     * questa colonna farebbe **lanciare `Carbon::setTimezone()`** dentro
     * `GiornoLocale`, cioè in mezzo a ogni lettura datata dell'applicazione:
     * un 500 sulla dashboard, sul diario e sul calendario insieme, causato da
     * un campo che nessuno ricollegherebbe al guasto.
     *
     * ⚠️ E l'elenco si chiede a PHP invece di scriverlo: le zone cambiano —
     * `Europe/Kyiv` è del 2022, `America/Nuuk` del 2020 — e un elenco copiato
     * qui dentro comincia a invecchiare il giorno dopo.
     */
    public function update(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'timezone' => [
                'required',
                'string',
                'max:64',
                Rule::in(DateTimeZone::listIdentifiers()),
            ],
        ]);

        $utente = $request->user();

        /*
         * ⚠️ Si scrive **solo se è cambiato**. Non è micro-ottimizzazione: senza,
         * ogni avvio dell'app fa un `UPDATE` e muove `updated_at`, e la colonna
         * che dovrebbe dire «quando è cambiato qualcosa di questa persona»
         * diventa «quando ha aperto l'app l'ultima volta» — cioè smette di
         * rispondere alla domanda per cui esiste.
         */
        if ($utente->timezone !== $dati['timezone']) {
            $utente->forceFill(['timezone' => $dati['timezone']])->save();
        }

        return response()->json(['data' => [
            'timezone' => $utente->timezone,

            // 💡 Il giorno che il server ha appena calcolato: l'app può
            // confrontarlo con il proprio e accorgersi subito se i due non
            // concordano, invece di scoprirlo da un totale che non torna.
            'today' => $utente->dataDiOggi(),
        ]]);
    }
}
