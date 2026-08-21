<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Quante chiamate AI possono essere in corso **tutte insieme** — FASE 8.2.
 *
 * ══ 🚨 IL TETTO CHE C'ERA NON PROTEGGE DA QUESTO ═══════════════════════════
 *
 * `RateLimiter::for('ai')` e' **6 al minuto e 20 all'ora per utente**: ferma
 * *una persona* che insiste, e non fa **niente** contro mille persone che ne
 * fanno una a testa. ⚠️ Il limite era per-utente, il problema e' globale.
 *
 * ── 📏 I numeri veri, misurati il 21/08/2026 su staging ───────────────────
 *
 * | | mediana | p95 |
 * |---|---|---|
 * | `daily_advice` | 2.9s | 4.2s |
 * | `food_text` | 2.4s | 8.2s |
 * | `food_photo` | 4.0s | 8.0s |
 *
 * E il pool PHP-FPM di questo dominio ha **`pm.max_children = 6`**.
 *
 * 🚨 **Da li' esce tutto**: sei processi diviso tre secondi fa **circa due
 * richieste AI al secondo** prima che il dominio sia pieno. E quando i processi
 * finiscono **non si ferma l'AI: si ferma il sito** — chi guarda la scheda, chi
 * fa il login, chi apre il pannello, tutti in coda dietro a qualcuno che sta
 * scrivendo un piatto. ⚠️ Il sintomo non assomiglia alla causa: sembra «il
 * server e' lento», ed e' «qualcun altro sta aspettando un modello».
 *
 * ── ⚠️ Perche' un semaforo e NON un limite al minuto ──────────────────────
 *
 * Perche' quello che fa cadere il sito e' la **contemporaneita'**, non il ritmo.
 * «Sessanta al minuto» sta benissimo se arrivano una al secondo, e ammazza tutto
 * se arrivano tutte insieme nello stesso secondo — che e' precisamente il caso
 * da cui ci si vuole difendere. 💡 Un contatore al minuto misura la cosa
 * sbagliata; qui si contano le richieste **in corso adesso**.
 *
 * ── 💡 Perche' 3 su 6, e non 6 su 6 ───────────────────────────────────────
 *
 * Perche' il punto non e' far passare piu' AI possibile: e' che **il resto
 * dell'applicazione continui a rispondere** mentre l'AI lavora. Tre slot
 * lasciano tre processi a chi sta facendo il login o guardando la scheda.
 *
 * 🚨 Chi trova pieno riceve **429 con `Retry-After`**, non un'attesa: far
 * aspettare qui vorrebbe dire tenere occupato il processo che si sta cercando
 * di liberare — cioe' fare esattamente il danno da cui ci si difende.
 *
 * ── 🚨 E se la cache non risponde, SI PASSA ───────────────────────────────
 *
 * Questo e' un limitatore, non un cancello di sicurezza. ⚠️ Una difesa che
 * chiude tutto quando **lei** si rompe fa piu' danni di quelli che evita: se la
 * cache non risponde, il peggio che succede e' che il tetto non c'e' per quel
 * momento — mentre chiudendo, l'AI smetterebbe di funzionare per un guasto che
 * non la riguarda.
 */
class TettoAiGlobale
{
    public function handle(Request $request, Closure $next): Response
    {
        $slot = $this->prendiUnoSlot();

        if ($slot === false) {
            return response()->json([
                'message' => 'Troppe richieste all\'assistente in questo momento. Riprova fra qualche secondo.',
                'code' => 'ai_troppo_carico',
            ], 429)->header('Retry-After', (string) $this->attesaSuggerita());
        }

        try {
            return $next($request);
        } finally {
            // ⚠️ `finally`: un'eccezione dentro il controller non deve lasciare
            // uno slot occupato fino alla scadenza — con tre slot in tutto, due
            // incidenti basterebbero a chiudere l'AI a tutti.
            $slot?->release();
        }
    }

    /**
     * Uno slot libero, `null` se il tetto e' spento o la cache non risponde,
     * `false` se sono tutti occupati.
     *
     * 💡 I tre valori sono diversi apposta: `null` vuol dire «passa e non c'e'
     * niente da rilasciare», `false` vuol dire «non passare». Un booleano solo
     * avrebbe confuso «non serve» con «non si puo'».
     */
    private function prendiUnoSlot(): Lock|false|null
    {
        $quanti = (int) config('ai.concorrenza.slot', 3);

        if ($quanti <= 0) {
            return null;
        }

        try {
            for ($i = 1; $i <= $quanti; $i++) {
                $lucchetto = Cache::lock('ai:slot:'.$i, (int) config('ai.concorrenza.ttl', 30));

                if ($lucchetto->get()) {
                    return $lucchetto;
                }
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return false;
    }

    /**
     * 💡 Il `Retry-After` non e' un numero a caso: e' la durata tipica di una
     * chiamata, cioe' **quanto ci vuole perche' uno slot si liberi davvero**.
     * Un valore piu' basso farebbe tornare il client su un tetto ancora pieno.
     */
    private function attesaSuggerita(): int
    {
        return (int) config('ai.concorrenza.riprova_fra', 5);
    }
}
