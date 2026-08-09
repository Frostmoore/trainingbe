<?php

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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // L'ordine in cui si applicano nelle rotte conta:
        //   auth:sanctum → tenant → tenant.active
        // `tenant` ha bisogno dell'utente autenticato, e `tenant.active` del
        // contesto che `tenant` imposta. Invertirli non darebbe errore: darebbe
        // silenziosamente nessun filtro.
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
