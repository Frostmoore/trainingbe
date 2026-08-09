<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Aggancia i ruoli di spatie al tenant della richiesta.
 *
 * Il resolver di serie (DefaultTeamResolver) tiene un id suo, che qualcuno deve
 * ricordarsi di impostare con setPermissionsTeamId() a ogni richiesta, job e
 * comando. Prima o poi qualcuno se ne dimentica, e in quel punto i ruoli
 * smettono di essere limitati alla palestra senza che nulla lo segnali: un
 * trainer diventerebbe trainer ovunque.
 *
 * Qui invece la fonte e' UNA sola — TenantContext — la stessa che alimenta il
 * global scope dei modelli. Impostare il tenant limita nello stesso momento le
 * query E i ruoli, e non esistono due verita' da tenere allineate.
 *
 * `setPermissionsTeamId()` resta implementato perche' l'interfaccia lo impone e
 * perche' spatie lo chiama internamente (es. in `Role::findByName`), ma NON
 * tocca il contesto: per cambiare tenant si usa `TenantContext::runAs()`, che e'
 * l'unica via, ed e' esplicita.
 */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    /**
     * ⚠️ Niente dipendenze nel costruttore.
     *
     * `PermissionRegistrar` istanzia il resolver con
     * `new (config('permission.team_resolver'))`: costruzione diretta, **senza
     * passare dal container**. Un costruttore con argomenti farebbe fallire
     * l'avvio con «Target is not instantiable», e non in un punto che suggerisca
     * la causa. TenantContext va quindi risolto qui dentro.
     */
    public function getPermissionsTeamId(): int|string|null
    {
        return app(TenantContext::class)->id();
    }

    /**
     * Volutamente inerte: il tenant si cambia SOLO da TenantContext.
     *
     * Se lasciasse scrivere qui, esisterebbero due modi di dire «adesso siamo
     * nella palestra X» che potrebbero divergere — ed e' esattamente il tipo di
     * divergenza che non da' errore, dà solo dati sbagliati.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        // no-op: vedi il commento di classe.
    }
}
