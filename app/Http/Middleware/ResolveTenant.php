<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Imposta la palestra della richiesta a partire dall'utente autenticato.
 *
 * 🚨 **L'unica fonte è `$user->tenant`.** Mai un header, mai un parametro, mai
 * un campo del corpo (ADR-04). Se il tenant arrivasse dal client, chiunque
 * potrebbe cambiarlo e leggere i dati di un'altra palestra: sarebbe una riga
 * innocua che annulla l'intero impianto multi-tenant.
 *
 * Va **dopo** `auth:sanctum` nella catena: senza utente autenticato non c'è
 * niente da risolvere.
 *
 * ### Il super admin passa senza contesto, di proposito
 * Ha `tenant_id = null`, quindi il contesto resta vuoto e il global scope non
 * filtra: vede tutte le palestre. È lo stesso motivo per cui `TenantScope` è
 * permissivo a contesto vuoto — la sicurezza sta nelle policy di `/god`, non qui.
 *
 * ### Perché `terminate()`
 * `TenantContext` è un singleton. Sotto PHP-FPM il processo muore a fine
 * richiesta e non c'è nulla da pulire, ma sotto Octane o in un worker di coda il
 * container sopravvive: senza azzeramento, la richiesta successiva
 * erediterebbe la palestra della precedente. È il tipo di guasto che non si
 * manifesta in sviluppo e appare in produzione sotto carico.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            $this->context->set($user->tenant);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }
}
