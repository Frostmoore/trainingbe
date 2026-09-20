<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoDiRichiesta;
use App\Models\RichiestaDiContatto;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ✉️ **Il modulo di contatto** — 21/09/2026.
 *
 * 📌 Il committente, sul piano su misura: *«tanto c'è scrivici. Mettici un
 * modulo di contatto così mi scrivono»*.
 *
 * ── ⛔ Cosa sostituisce ────────────────────────────────────────────────────
 *
 * Un `mailto:`. 🚨 Su un telefono senza app di posta configurata quel link
 * **non fa niente**: il cliente preme, non succede nulla, e se ne va pensando
 * che il sito sia rotto. Chi arrivava in fondo scriveva una mail a mano, cioè
 * senza dire quanti iscritti ha — che è l'unica cosa da cui dipende il prezzo.
 *
 * ── 🚨 Le tre difese, e perché queste ─────────────────────────────────────
 *
 * 1. **`throttle:5,10`** sulla rotta: cinque invii ogni dieci minuti per
 *    indirizzo IP. Un cliente ne manda uno; chi ne manda cinque non è un
 *    cliente.
 * 2. **Un campo trappola** (`sito_web`), nascosto via CSS e vuoto per un
 *    umano: i robot compilano tutto quello che trovano. ⚠️ Chi ci casca riceve
 *    **la pagina del «grazie»** e nessuna riga in tabella — dirgli «sei un
 *    robot» insegnerebbe soltanto a non compilarlo la prossima volta.
 * 3. **`accetto`**, che è `accepted`: senza consenso la richiesta non nasce.
 *
 * 💡 ⛔ Niente CAPTCHA: vorrebbe dire uno script di terze parti sul sito, e il
 * sito non ne carica **nessuno** (vedi `SitoPubblicoTest`).
 */
class ScriviciController extends Controller
{
    /**
     * Il modulo.
     *
     * 💡 `?tipo=palestra` arriva dal pulsante «Scrivici» della scheda su
     * misura: chi ci clicca ha già detto chi è, e riscriverlo è una domanda
     * in più fatta a chi ha già risposto.
     */
    public function modulo(Request $request): View
    {
        $tipo = TipoDiRichiesta::tryFrom((string) $request->query('tipo'))
            ?? TipoDiRichiesta::Altro;

        return view('sito.scrivici', [
            'tipo' => $tipo,
            // ⚠️ Il pannello lo legge dal DB; qui serve solo a ricordare a chi
            // scrive che gli risponderemo a mano, non con un automatismo.
            'fatto' => (bool) session('richiesta-inviata', false),
        ]);
    }

    /** L'invio vero. */
    public function invia(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'tipo' => ['required', Rule::enum(TipoDiRichiesta::class)],
            'nome' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'organizzazione' => ['nullable', 'string', 'max:255'],

            /*
             * ⚠️ Il tetto a 100.000 non serve a nessun cliente vero: serve a
             * non farsi scrivere in tabella un numero che poi rompe una
             * moltiplicazione nel preventivo.
             */
            'iscritti' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'trainer' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'messaggio' => ['required', 'string', 'min:10', 'max:4000'],
            'accetto' => ['accepted'],

            // Il campo trappola: se è pieno, è un robot.
            'sito_web' => ['nullable', 'string', 'max:255'],
        ], [], [
            'accetto' => 'consenso',
            'messaggio' => 'messaggio',
        ]);

        if (($dati['sito_web'] ?? '') !== '') {
            // 🚨 Stessa risposta di un invio riuscito, e nessuna riga scritta.
            return $this->grazie();
        }

        RichiestaDiContatto::create([
            'tipo' => $dati['tipo'],
            'nome' => $dati['nome'],
            'email' => $dati['email'],
            'telefono' => $dati['telefono'] ?? null,
            'organizzazione' => $dati['organizzazione'] ?? null,
            'iscritti' => $dati['iscritti'] ?? null,
            'trainer' => $dati['trainer'] ?? null,
            'messaggio' => $dati['messaggio'],
        ]);

        return $this->grazie();
    }

    /**
     * 🚨 **Redirect e non una vista renderizzata sul POST** (PRG): chi ricarica
     * la pagina del grazie non deve rimandare la richiesta una seconda volta —
     * e il browser glielo chiede, se la pagina è la risposta di un POST.
     */
    private function grazie(): RedirectResponse
    {
        return redirect('/scrivici')->with('richiesta-inviata', true);
    }
}
