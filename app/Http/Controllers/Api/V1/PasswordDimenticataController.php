<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🔑 **«Ho dimenticato la password», dall'app** — 22/09/2026, fase F4.0.
 *
 * 📌 Il committente, quattro volte su quattro pagine diverse: *«mi serve un
 * tasto per mandare il reset password»*. ⛔ Ma il tasto del pannello manda un
 * link, e prima di F4 **quel link non portava da nessuna parte**: la pagina di
 * reimpostazione non esisteva, e nemmeno il modo di chiederne una da soli.
 *
 * ══ 🚨 LA RISPOSTA È SEMPRE LA STESSA, E NON È PIGRIZIA ══════════════════
 *
 * ⛔ *«Non esiste nessun account con questa email»* è un modo cortese di
 * confermare a un estraneo **quali indirizzi sono iscritti**, uno alla volta,
 * con una richiesta HTTP che nessuno guarda. 💡 Qui si risponde sempre
 * `200` con la stessa frase: chi ha davvero perso la password riceve la mail,
 * chi sta provando indirizzi altrui non impara niente.
 *
 * ⚠️ **Anche il caso ambiguo risponde così.** L'email in questo progetto è
 * unica **per palestra**: lo stesso indirizzo può stare su due account (è il
 * caso di chi iscrive il figlio col proprio). Con due candidati non si tira a
 * sorte — si scrive nel registro e si risponde la solita frase, perché
 * mandare il link «a uno dei due» vuol dire una volta su due cambiare la
 * password alla persona sbagliata.
 *
 * ── 🔒 Il limite ───────────────────────────────────────────────────────────
 *
 * `throttle:auth-login` sulla rotta: provare indirizzi a raffica da qui
 * costerebbe quanto provare password, ed è giusto che costi uguale.
 */
class PasswordDimenticataController extends Controller
{
    /**
     * ⚠️ **Sempre 200.** Il corpo non cambia mai: né per un indirizzo
     * sconosciuto, né per un account disattivato, né per il caso ambiguo.
     */
    public function __invoke(
        Request $request,
        LaReimpostazioneDellaPassword $reimpostazione,
    ): JsonResponse {
        $dati = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $persona = $reimpostazione->unicaCon($dati['email']);

        if ($persona !== null) {
            $reimpostazione->apriPer($persona);
        }

        return response()->json([
            'message' => 'Se quell\'indirizzo è nostro, il link per rifare la password è partito. '
                .'Vale un\'ora e si usa una volta sola.',
        ]);
    }
}
