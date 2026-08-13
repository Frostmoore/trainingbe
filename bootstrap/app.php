<?php

use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\RequireAiConsent;
use App\Http\Middleware\RequirePlanWithAi;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    })->create();
