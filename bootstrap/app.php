<?php

use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RequireAiConsent;
use App\Http\Middleware\RequirePlanWithAi;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TettoAiGlobale;
use App\Http\Middleware\VersioneMinima;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        // I canali privati della chat (B8.2). L'autorizzazione e' in
        // routes/channels.php ed e' l'ultimo controllo che esiste: una volta
        // iscritto a un canale, il client riceve tutto cio' che ci passa.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * 🚨 Il webhook di Stripe non puo' avere un token CSRF.
         *
         * Arriva da un server, non da un browser: non ha sessione e non ha
         * compilato nessun modulo. ⚠️ L'esenzione non lo lascia scoperto —
         * quello che lo autentica e' la **firma**, verificata in
         * `StripeWebhookController`, che e' un controllo piu' forte di un
         * token di sessione.
         */
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);

        /*
         * 🆕 **Il cancello della versione su TUTTE le rotte API** — FASE 10.4.
         *
         * 🚨 Comprese quelle pubbliche. Se il login restasse aperto, una copia
         * vecchia entrerebbe e poi sbatterebbe **una schermata alla volta**: la
         * persona vedrebbe l'app rompersi a pezzi invece di leggere una frase
         * che le dice cosa fare.
         *
         * ⚠️ `prependToGroup` e non `appendToGroup`: deve rispondere **prima**
         * di `auth:sanctum` e di tutto il resto. Un'app da aggiornare non deve
         * prima sentirsi dire che il token è scaduto.
         *
         * 💡 Di serie non blocca nessuno (`minima = 0`), e chi non manda
         * l'intestazione passa: vedi `VersioneMinima`.
         */
        $middleware->prependToGroup('api', VersioneMinima::class);

        // L'ordine in cui si applicano nelle rotte conta:
        //   auth:sanctum → tenant → tenant.active
        // `tenant` ha bisogno dell'utente autenticato, e `tenant.active` del
        // contesto che `tenant` imposta. Invertirli non darebbe errore: darebbe
        // silenziosamente nessun filtro.
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.active' => EnsureTenantActive::class,

            // 🚨 S9.1 — senza consenso esplicito niente esce verso Anthropic.
            // Va **dopo** `auth:sanctum`: ha bisogno dell'utente per sapere
            // cosa ha acconsentito.
            'ai.consent' => RequireAiConsent::class,
            'ai.tetto' => TettoAiGlobale::class,

            // 🚨 F4.2 (D2) — «hai diritto all'AI?» è una domanda diversa da
            // «quanti token ti restano», e va risolta **prima**. Nella catena
            // della quota `0` significa *illimitato*: non esiste un numero che
            // voglia dire «niente AI», quindi serve un cancello a parte.
            'ai.plan' => RequirePlanWithAi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * 🈯 **«Too Many Attempts.» non lo diciamo in inglese** — 20/09/2026.
         *
         * ⛔ Visto sull'emulatore: chi riprova troppo in fretta si trovava a
         * schermo la frase di Laravel, in inglese e senza dire **cosa fare**.
         * ⚠️ Questo messaggio non sta in un file di lingua: è scritto dentro
         * l'eccezione, quindi si sostituisce qui.
         *
         * 💡 `Retry-After` arriva già dal middleware: lo si legge e si dice fra
         * quanto riprovare, invece di lasciare la persona a indovinare.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $secondi = (int) ($e->getHeaders()['Retry-After'] ?? 0);

            return response()->json([
                'message' => $secondi > 0
                    ? __('Hai riprovato troppe volte di seguito. Riprova fra :secondi secondi.', ['secondi' => $secondi])
                    : __('Hai riprovato troppe volte di seguito. Aspetta un momento e riprova.'),
            ], 429, $e->getHeaders());
        });
    })->create();
