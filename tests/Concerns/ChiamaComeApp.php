<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;

/**
 * Autentica una richiesta API come farebbe l'app.
 *
 * 🚨 **`forgetGuards()` non e' un dettaglio.** In un test le richieste HTTP
 * girano tutte nello **stesso processo**, e il guard di Sanctum tiene in cache
 * l'utente che ha risolto la prima volta. Senza ripulirlo, la seconda richiesta
 * di un test — anche con un token diverso, anche di un'altra palestra — resta
 * autenticata come il primo utente.
 *
 * Il guasto e' particolarmente insidioso perche' i test **passano lo stesso**
 * finche' ne fanno una sola: si manifesta solo quando si comincia a verificare
 * l'isolamento fra due utenti, cioe' esattamente dove serve che sia vero. E'
 * gia' costato una diagnosi sbagliata in questa codebase.
 */
trait ChiamaComeApp
{
    protected function comeApp(User $utente, array $abilities = ['member']): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($utente->createToken('test', $abilities)->plainTextToken);
    }
}
