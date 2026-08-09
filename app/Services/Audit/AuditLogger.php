<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * L'unico punto da cui si scrive in `audit_logs`.
 *
 * Averne uno solo serve a tre cose che sparse nei controller non verrebbero mai
 * fatte bene:
 *  - **denormalizzare l'attore** (nome ed email copiati sulla riga), cosi' il
 *    registro resta leggibile anche dopo la cancellazione dell'utente;
 *  - **derivare il tenant giusto** anche quando l'azione avviene senza contesto
 *    — il caso del super admin che agisce dal pannello di piattaforma;
 *  - **non far mai fallire l'azione a causa del log**. Se scrivere l'audit
 *    esplode, l'operazione dell'utente non deve rompersi: si scrive l'errore nel
 *    log applicativo e si va avanti. Il contrario significherebbe che un indice
 *    corrotto impedisce di sospendere una palestra.
 */
class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Registra un'azione.
     *
     * @param  Model|null  $subject  su cosa si e' agito (morph nullable)
     * @param  array<string, mixed>  $payload  il minimo indispensabile per capire, mai dati sensibili
     * @param  Tenant|int|null  $tenant  forzatura esplicita; se assente si deduce da subject e contesto
     */
    public function log(
        AuditAction $action,
        ?Model $subject = null,
        array $payload = [],
        ?User $actor = null,
        Tenant|int|null $tenant = null,
    ): ?AuditLog {
        try {
            $actor ??= $this->currentActor();

            return AuditLog::create([
                'tenant_id' => $this->resolveTenantId($tenant, $subject, $actor),
                'actor_id' => $actor?->getKey(),
                'actor_label' => $actor?->name,
                'actor_email' => $actor?->email,
                'action' => $action->value,
                'auditable_type' => $subject !== null ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'payload' => $payload === [] ? null : $payload,
                'ip' => $this->requestValue(fn (Request $r): ?string => $r->ip()),
                'user_agent' => mb_substr(
                    (string) $this->requestValue(fn (Request $r): ?string => $r->userAgent()),
                    0,
                    512,
                ) ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Il log non deve MAI far fallire l'azione che documenta.
            report($e);

            return null;
        }
    }

    private function currentActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Quale palestra riguarda l'azione.
     *
     * L'ordine non e' casuale: la forzatura esplicita vince, poi il soggetto —
     * perche' «il super admin ha impersonato Mario della palestra Demo» riguarda
     * Demo, non il nulla in cui gira il pannello di piattaforma — poi il
     * contesto, e solo per ultimo la palestra di chi agisce.
     */
    private function resolveTenantId(Tenant|int|null $tenant, ?Model $subject, ?User $actor): ?int
    {
        if ($tenant instanceof Tenant) {
            return $tenant->getKey();
        }

        if (is_int($tenant)) {
            return $tenant;
        }

        if ($subject instanceof Tenant) {
            return $subject->getKey();
        }

        $daSoggetto = $subject?->getAttribute('tenant_id');

        if (is_int($daSoggetto)) {
            return $daSoggetto;
        }

        return $this->tenants->id() ?? $actor?->tenant_id;
    }

    /**
     * Legge qualcosa dalla richiesta, se una richiesta c'e'.
     *
     * In coda o da console non c'e': l'audit deve funzionare lo stesso, e un
     * job che scrive un log non deve inventarsi un IP.
     *
     * @param  callable(Request): ?string  $reader
     */
    private function requestValue(callable $reader): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $reader($request) : null;
    }
}
