<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'app troppo vecchia si ferma, e sa perche' — FASE 10.4.
 *
 * ══ 🚨 A COSA SERVE DAVVERO ═══════════════════════════════════════════════
 *
 * 📌 Il committente: *«se mai in futuro dovessimo cambiare server, cosa
 * probabile, la gente non continua a vedere le api vecchie»*.
 *
 * ⚠️ **Ma da solo non basta a quello scopo, e va detto qui perche' e' il primo
 * posto in cui qualcuno verra' a cercare.** Se il server vecchio e' **spento**,
 * l'app chiede a un indirizzo che non risponde — e la regola giusta la' e'
 * **passare** (vedi sotto). 🚨 Il cancello funziona solo dentro la procedura in
 * cinque passi scritta nel piano: si pubblica un'app che sa fermarsi, si
 * aspetta, **il server vecchio resta acceso** e comincia a dire «aggiornati»,
 * le copie vecchie si fermano, e **solo allora** si spegne.
 *
 * ── 🚨 Chi non manda l'intestazione PASSA ─────────────────────────────────
 *
 * Le uniche cose che chiamano l'API senza `X-App-Build` sono il pannello
 * Filament e le nostre verifiche con `curl`. ⚠️ Un cancello che blocca cio' che
 * non sa riconoscere blocca **prima di tutto noi**, e lo fa nel momento peggiore
 * — mentre si sta cercando di capire perche' qualcosa non va.
 *
 * 💡 E non e' un buco: chi vuole aggirare il blocco puo' gia' ricompilare l'app.
 * Questo non e' un controllo di sicurezza, e' un modo di **dire a una persona
 * onesta** che deve aggiornare.
 *
 * ── ⚠️ Il minimo di serie e' `0` ──────────────────────────────────────────
 *
 * Cioe' «non blocco nessuno». Un valore sbagliato in `config/app_versione.php`
 * ferma **tutti gli utenti insieme**, e l'unico rimedio e' un altro intervento
 * sul server: il default deve essere quello che non fa danni.
 */
class VersioneMinima
{
    /**
     * 🚨 Le rotte che rispondono **anche** a un'app da aggiornare.
     *
     * ⚠️ Senza questa lista, `GET /versione` sarebbe dietro il cancello che
     * lui stesso descrive: l'app bloccata non potrebbe nemmeno chiedere «sono
     * ancora vecchia?», e il pulsante «Riprova» della schermata di blocco non
     * avrebbe niente da interrogare. 💡 Serve quando il blocco e' stato un
     * errore nostro: senza, per toglierlo servirebbe un'altra pubblicazione.
     */
    private const SEMPRE_APERTE = ['api/v1/versione'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(self::SEMPRE_APERTE)) {
            return $next($request);
        }

        $build = $request->header('X-App-Build');

        // 💡 Chi non si presenta passa: pannello, `curl`, integrazioni future.
        if ($build === null || ! ctype_digit($build)) {
            return $next($request);
        }

        $piattaforma = strtolower((string) $request->header('X-App-Platform', 'android'));
        $minima = (int) config("app_versione.minima.$piattaforma", 0);

        if ($minima <= 0 || (int) $build >= $minima) {
            return $next($request);
        }

        /*
         * `426 Upgrade Required` e non `403`: dice **cosa fare**, non «non puoi».
         * ⚠️ Un 403 l'app lo confonderebbe con il cancello del consenso, che e'
         * un'altra cosa e ha un'altra schermata.
         */
        return response()->json([
            'message' => __('Questa versione dell\'app non è più supportata. Aggiornala per continuare.'),
            'code' => 'app_da_aggiornare',
            'minima' => $minima,
            'store' => config("app_versione.store.$piattaforma") ?: null,
        ], 426);
    }
}
