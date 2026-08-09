<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocca le richieste delle palestre non attive.
 *
 * Serve **anche a sessione già aperta**: il login controlla lo stato della
 * palestra, ma un abbonamento può scadere o essere sospeso mentre l'iscritto ha
 * già un token valido in tasca. Senza questo controllo continuerebbe a usare
 * l'app finché non fa logout — cioè, in pratica, per sempre.
 *
 * Va **dopo** `ResolveTenant`.
 *
 * ### Le tre uscite
 *  - **super admin**: passa. Non appartiene a nessuna palestra, quindi non c'è
 *    nessuno stato da verificare.
 *  - **contesto vuoto e non super admin**: 403. Non dovrebbe accadere — vorrebbe
 *    dire un utente con `tenant_id` valorizzato ma palestra sparita (cancellata
 *    a soft-delete). Meglio negare che proseguire con un contesto assente, dove
 *    il global scope **non filtrerebbe** e l'utente vedrebbe i dati di tutti.
 *  - **palestra non attiva**: 403.
 *
 * Il corpo dell'errore porta un `code` stabile perché l'app possa distinguerlo
 * da un 403 di permessi e mostrare il messaggio giusto («la tua palestra ha
 * sospeso il servizio») invece di un generico «non autorizzato».
 */
class EnsureTenantActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuperAdmin() === true) {
            return $next($request);
        }

        $tenant = $this->context->get();

        if ($tenant === null) {
            return response()->json([
                'message' => __('Palestra non disponibile.'),
                'code' => 'tenant_missing',
            ], 403);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'message' => __('La tua palestra non ha un abbonamento attivo.'),
                'code' => 'tenant_inactive',
            ], 403);
        }

        return $next($request);
    }
}
