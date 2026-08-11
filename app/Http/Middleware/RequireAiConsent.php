<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Senza consenso, niente AI — S9.1.
 *
 * ── 🚨 Perché è un middleware e non un `if` nel controller ────────────────
 *
 * Perché deve valere **su ogni rotta AI, comprese quelle che non esistono
 * ancora**. Un controllo dentro `AiController::advice()` protegge quel metodo;
 * il prossimo endpoint AI che qualcuno aggiungerà partirà scoperto, e non se ne
 * accorgerà nessuno — la chiamata funzionerebbe benissimo.
 *
 * ⚠️ **Quello che parte verso Anthropic non torna indietro.** È un
 * trasferimento verso un terzo fuori dall'Unione, e da ciò che una persona
 * mangia si inferisce il suo stato di salute (CGUE C-184/20): è materiale
 * dell'art. 9, e senza consenso esplicito non ha nessuna base giuridica.
 *
 * 💡 **403 con un codice riconoscibile, non 401.** L'app deve poter distinguere
 * «devi rifare l'accesso» da «devi dare il consenso», e portare alla schermata
 * giusta: un 401 la manderebbe al login, dove la persona rifarebbe l'accesso e
 * ritroverebbe lo stesso errore.
 */
class RequireAiConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();

        if ($utente instanceof User && ! $utente->puoUsareAi()) {
            return response()->json([
                'message' => __('Per usare le funzioni con l\'AI serve il tuo consenso.'),
                'code' => 'ai_consent_required',
            ], 403);
        }

        return $next($request);
    }
}
