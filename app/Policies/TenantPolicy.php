<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * La palestra come oggetto amministrabile — B3.6.
 *
 * 🚨 **Solo il super admin crea, sospende e cancella una palestra.** Il
 * gym_admin puo' modificare la **propria**, e solo negli aspetti che lo
 * riguardano (nome, colori, codice d'invito): stato dell'abbonamento, piano e
 * tetto AI restano fuori dalla sua portata, perche' sono i termini del contratto
 * che ha con noi. Un cliente che si toglie da solo la sospensione o si alza il
 * tetto di consumo non e' un cliente, e' un problema di fatturazione.
 */
class TenantPolicy
{
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return false;
    }

    public function view(User $utente, Tenant $palestra): bool
    {
        return $utente->tenant_id === $palestra->getKey()
            && ($utente->isGymAdmin() || $utente->isTrainer());
    }

    public function create(User $utente): bool
    {
        return false;
    }

    /** La propria, e solo il gym_admin. Quali campi, lo decide il modulo. */
    public function update(User $utente, Tenant $palestra): bool
    {
        return $utente->tenant_id === $palestra->getKey() && $utente->isGymAdmin();
    }

    public function delete(User $utente, Tenant $palestra): bool
    {
        return false;
    }
}
