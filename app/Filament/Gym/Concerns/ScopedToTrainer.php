<?php

declare(strict_types=1);

namespace App\Filament\Gym\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo scoping del trainer, applicato dove conta davvero — B3.5.
 *
 * 🚨 **Va fatto in `getEloquentQuery()`, non nella vista.**
 * Una policy risponde su un record alla volta: non impedisce a un elenco di
 * mostrare tutti gli iscritti della palestra, ne' a un'esportazione, ne' a una
 * ricerca globale. Nascondere le righe in Blade sarebbe anche peggio — i dati
 * sarebbero comunque nella risposta.
 *
 * La regola: **il gym_admin vede tutta la palestra, il trainer solo i propri
 * assegnati.** E' il motivo per cui una palestra si fida di mettere i propri
 * clienti qui dentro.
 */
trait ScopedToTrainer
{
    /**
     * Limita una query agli iscritti visibili a chi sta guardando.
     *
     * @param  string  $colonna  la colonna che punta all'iscritto
     */
    protected static function limitaAgliAssegnati(Builder $query, string $colonna = 'id'): Builder
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            // Nessun utente: si restituisce il vuoto, non tutto. In un pannello
            // questo caso non capita, ma il valore prudente e' quello che non
            // fa danni se un giorno capitasse.
            return $query->whereRaw('1 = 0');
        }

        if ($utente->isSuperAdmin() || $utente->isGymAdmin()) {
            return $query;
        }

        if (! $utente->isTrainer()) {
            return $query->whereRaw('1 = 0');
        }

        $assegnati = $utente->assignedMembers()->pluck('users.id')->all();

        return $query->whereIn($colonna, $assegnati);
    }
}
