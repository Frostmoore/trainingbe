<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Auth\LaReimpostazioneDellaPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * 🔑 **La pagina dove si sceglie la password nuova** — 22/09/2026, fase F4.
 *
 * 📌 Il committente: *«Eh sì va creata la pagina di reimpostazione password»*.
 *
 * ══ ⚠️ PERCHÉ STA SUL SITO E NON IN UN PANNELLO ══════════════════════════
 *
 * Perché ci arriva **chi non riesce a entrare**: un iscritto dall'app, un
 * trainer dal pannello, chiunque. ⛔ Una pagina dietro l'autenticazione
 * chiederebbe di accedere per poter rifare la password di chi non riesce ad
 * accedere.
 *
 * 🚨 **Non dice mai di chi è il link**: nome ed email non compaiono. Chi apre
 * un link trovato in giro non deve scoprire **a chi appartiene**.
 */
class ReimpostaPasswordController extends Controller
{
    public function modulo(string $token, LaReimpostazioneDellaPassword $reimpostazione): View
    {
        return view('sito.reimposta-password', [
            'token' => $token,

            /*
             * 💡 Qui serve solo **sì o no**: la persona non si mostra. Se il
             * link non vale, la pagina lo dice e offre di richiederne un altro.
             */
            'valido' => $reimpostazione->personaDi($token) !== null,
            'fatto' => (bool) session('password-cambiata', false),
        ]);
    }

    public function salva(
        Request $request,
        string $token,
        LaReimpostazioneDellaPassword $reimpostazione,
    ): RedirectResponse {
        $dati = $request->validate([
            /*
             * ⚠️ **Lettere e numeri, e basta** — la stessa regola di tutto il
             * progetto. La composizione forzata produce «Password1!» in tutto
             * il mondo: la lunghezza è la variabile che conta (NIST 800-63B).
             */
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! $reimpostazione->completa($token, $dati['password'])) {
            return back()->with(
                'avviso',
                'Questo link non vale più: è scaduto, o è già stato usato. Chiedine un altro.',
            );
        }

        /*
         * 🚨 **Non si entra da qui.** Cambiata la password, si va al modulo
         * d'accesso: fare entrare chi ha aperto un link vorrebbe dire che il
         * link **è** l'accesso, e un link gira più facilmente di una password.
         */
        return redirect('/admin/login')->with(
            'password-cambiata',
            'Fatto: adesso entra con la password nuova. ⚠️ Se usi l\'app, rientra anche da lì.',
        );
    }
}
