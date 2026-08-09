<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NutritionPlan;
use App\Models\User;

/**
 * Chi tocca i piani alimentari — B3.6.
 *
 * Stesse regole delle schede: il trainer sui propri iscritti e sui modelli,
 * l'amministratore su tutta la sua palestra.
 */
class NutritionPlanPolicy
{
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return $utente->isGymAdmin() || $utente->isTrainer();
    }

    public function view(User $utente, NutritionPlan $piano): bool
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

    public function update(User $utente, NutritionPlan $piano): bool
    {
        return $this->view($utente, $piano);
    }

    public function delete(User $utente, NutritionPlan $piano): bool
    {
        return $this->update($utente, $piano);
    }
}
