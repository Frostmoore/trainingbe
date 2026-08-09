<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Chi puo' vedere e toccare le persone di una palestra — B3.6.
 *
 * 🚨 **La regola che tutte le altre applicano: il trainer vede solo i propri
 * iscritti.** Non e' una preferenza di interfaccia, e' il motivo per cui una
 * palestra si fida di mettere i propri dati qui dentro: il trainer che se ne va
 * e apre la palestra dall'altra parte della strada non deve poter portare via
 * l'elenco completo dei clienti.
 *
 * Lo scoping vero si applica in `getEloquentQuery()` delle resource, non qui: una
 * policy risponde su un record alla volta e non puo' impedire che un elenco li
 * mostri tutti. Questa classe copre l'altro caso — l'accesso diretto a un id —
 * ed e' l'unico controllo che ferma chi digita un URL a mano.
 */
class UserPolicy
{
    /**
     * Il super admin passa sempre.
     *
     * `before()` restituisce `null` per gli altri, che e' diverso da `false`:
     * `false` bloccherebbe tutti gli altri controlli invece di lasciarli
     * proseguire.
     */
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return $utente->isGymAdmin() || $utente->isTrainer();
    }

    public function view(User $utente, User $bersaglio): bool
    {
        if (! $this->stessaPalestra($utente, $bersaglio)) {
            return false;
        }

        if ($utente->isGymAdmin()) {
            return true;
        }

        if ($utente->isTrainer()) {
            // Se stesso sempre, piu' i propri assegnati.
            return $utente->getKey() === $bersaglio->getKey()
                || $this->segue($utente, $bersaglio);
        }

        return false;
    }

    /** Solo l'amministratore della palestra crea persone. */
    public function create(User $utente): bool
    {
        return $utente->isGymAdmin();
    }

    public function update(User $utente, User $bersaglio): bool
    {
        if (! $this->stessaPalestra($utente, $bersaglio)) {
            return false;
        }

        // Nessuno modifica un super admin dal pannello di una palestra.
        if ($bersaglio->isSuperAdmin()) {
            return false;
        }

        return $utente->isGymAdmin();
    }

    public function delete(User $utente, User $bersaglio): bool
    {
        // 🚨 Nemmeno l'amministratore cancella se stesso: si chiuderebbe fuori
        // dal proprio pannello e la palestra resterebbe senza nessuno che possa
        // rientrare.
        if ($utente->getKey() === $bersaglio->getKey()) {
            return false;
        }

        return $this->update($utente, $bersaglio);
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * 🚨 Il confronto e' su `tenant_id`, e serve anche dentro il pannello.
     *
     * Il global scope filtra le **query**; un record gia' in mano — arrivato da
     * un binding di rotta, da una relazione, da un id passato a mano — non e'
     * passato da nessun filtro.
     */
    private function stessaPalestra(User $a, User $b): bool
    {
        return $a->tenant_id !== null && $a->tenant_id === $b->tenant_id;
    }

    private function segue(User $trainer, User $membro): bool
    {
        return $trainer->assignedMembers()
            ->where('users.id', $membro->getKey())
            ->exists();
    }
}
