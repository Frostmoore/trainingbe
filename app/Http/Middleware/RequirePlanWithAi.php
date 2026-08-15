<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Billing\PianoAttivo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il tuo piano comprende l'AI? — F4.2 della Parte B, decisione D2.
 *
 * ── 🚨 Perché è un middleware, e perché sta PRIMA della quota ─────────────
 *
 * **Perché deve valere su ogni rotta AI, comprese quelle che non esistono
 * ancora.** È lo stesso ragionamento di `RequireAiConsent`, che sta accanto:
 * un controllo dentro un controller protegge quel metodo, e il prossimo
 * endpoint AI partirebbe scoperto senza che nessuno se ne accorga — la chiamata
 * funzionerebbe benissimo.
 *
 * 🚨 **«Hai diritto» e «quanto te ne resta» sono due domande diverse, e vanno
 * tenute separate.** Mescolarle dentro `MemberAiQuota::capFor()` è il modo di
 * ritrovarsi con un utente gratuito che consuma token perché qualcuno ha
 * invertito due `if`. E c'è una ragione tecnica precisa per cui non si
 * *potrebbe* nemmeno mescolarle: in quella catena **`0` significa illimitato** e
 * `null` significa «scendi al livello successivo». Non esiste un numero che
 * voglia dire «niente AI» — mettere `0` a un utente gratuito gli darebbe l'AI
 * **senza limiti**, l'esatto contrario del requisito B2.
 *
 * ── 💡 403 con un codice suo, distinto da quello del consenso ─────────────
 *
 * L'app deve poter distinguere **tre** cose, e portare a tre schermate diverse:
 *
 * | Codice | Cosa manca | Dove porta |
 * |---|---|---|
 * | `ai_consent_required` | il consenso | ai consensi |
 * | `plan_without_ai` | il piano | al listino |
 * | `ai_quota_exceeded` | i token del mese | ad aspettare |
 *
 * ⚠️ Un unico errore per tutti e tre manderebbe chi ha finito i token a
 * riconcedere un consenso che ha già dato.
 */
class RequirePlanWithAi
{
    public function __construct(private readonly PianoAttivo $piano) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();

        /*
         * 🚨 `aiUtilizzabile()` e non `haLaAi()` — 15/08/2026.
         *
         * `haLaAi()` chiede «il piano la comprende»; qui serve «questa persona
         * puo' fare una chiamata», e dal momento in cui i gettoni si comprano
         * sono due domande diverse.
         *
         * ⚠️ **Era il difetto**: chi comprava cento gettoni su un piano senza AI
         * si vedeva rispondere `403` **prima** che il portafoglio fosse
         * interrogato. Il codice del portafoglio era corretto e non veniva mai
         * raggiunto — il tipo di guasto che non si trova leggendo la parte che
         * non funziona.
         */
        if ($utente instanceof User && ! $this->piano->aiUtilizzabile($utente)) {
            return response()->json([
                'message' => __('Le funzioni con l\'AI non sono comprese nel tuo piano.'),
                'code' => 'plan_without_ai',
            ], 403);
        }

        return $next($request);
    }
}
