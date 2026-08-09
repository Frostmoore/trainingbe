<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutPlan;

/**
 * Chi tocca le schede — B3.6.
 *
 * Il trainer lavora sui **propri** iscritti e sui modelli della palestra;
 * l'amministratore su tutto quello della sua palestra. L'iscritto non arriva
 * qui: usa l'app, dove il controllo e' nel controller API.
 */
class WorkoutPlanPolicy
{
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return $utente->isGymAdmin() || $utente->isTrainer();
    }

    public function view(User $utente, WorkoutPlan $piano): bool
    {
        if ($utente->tenant_id !== $piano->tenant_id) {
            return false;
        }

        if ($utente->isGymAdmin()) {
            return true;
        }

        if (! $utente->isTrainer()) {
            return false;
        }

        // I modelli della palestra sono di tutti i trainer: e' il loro scopo.
        if ($piano->isTemplate()) {
            return true;
        }

        return $utente->assignedMembers()
            ->where('users.id', $piano->member_id)
            ->exists();
    }

    public function create(User $utente): bool
    {
        return $utente->isGymAdmin() || $utente->isTrainer();
    }

    public function update(User $utente, WorkoutPlan $piano): bool
    {
        return $this->view($utente, $piano);
    }

    public function delete(User $utente, WorkoutPlan $piano): bool
    {
        // 🚨 Una scheda con allenamenti gia' fatti non si cancella: si archivia.
        // Le sessioni ci puntano, e cancellarla lascerebbe uno storico senza
        // origine. Il vincolo e' anche a database (`restrictOnDelete`), ma un
        // errore SQL in faccia a un trainer non e' una risposta.
        if ($piano->sessions()->exists()) {
            return false;
        }

        return $this->update($utente, $piano);
    }
}
