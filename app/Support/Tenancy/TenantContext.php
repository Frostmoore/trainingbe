<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Il tenant della richiesta corrente.
 *
 * Registrato come singleton in AppServiceProvider. Lo imposta SOLO il middleware
 * ResolveTenant, derivandolo dall'utente autenticato — mai da un header o da un
 * parametro del client (ADR-04).
 *
 * `runWithoutTenant()` è l'unica via legittima per uscire dal global scope: non
 * esiste un flag silenzioso, così un bypass si vede sempre leggendo il codice.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Esegue il callback nel contesto di $tenant, poi ripristina il precedente.
     *
     * Il ripristino è in `finally`: se il callback lancia, il contesto non resta
     * sporco — altrimenti un'eccezione in un job lascerebbe il tenant sbagliato
     * al prossimo giro dello stesso worker.
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }

    /**
     * Esegue il callback senza alcun tenant: il TenantScope non filtra.
     *
     * Serve al pannello /god, ai seeder e ai comandi di manutenzione. Da usare
     * con parsimonia e sempre in modo esplicito.
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = null;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
