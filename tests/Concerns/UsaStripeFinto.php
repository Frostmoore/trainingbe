<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Stripe\StripeClient;

/**
 * 💳 **Un finto Stripe, che registra le chiamate** — §9.1, 18/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * ⛔ Fino al 18/09 il lato **in uscita** verso Stripe non era mai stato provato
 * in automatico: ogni servizio si faceva il suo `new StripeClient(...)` dentro un
 * metodo privato, e i test si fermavano ai rifiuti. 🚨 La strada buona — aprire
 * un pagamento, creare un cliente, emettere una fattura — l'aveva percorsa solo
 * la produzione.
 *
 * 💡 Adesso `StripeClient` sta nel container: questo lo sostituisce con un finto
 * che **si segna ogni chiamata** e risponde con oggetti plausibili.
 *
 * ⚠️ **Estende `StripeClient`**, e non per comodità: i servizi dichiarano quel
 * tipo di ritorno, e un oggetto qualunque farebbe esplodere il test prima ancora
 * di arrivare alla cosa da provare.
 */
trait UsaStripeFinto
{
    protected function stripeFinto(): StripeFinto
    {
        $finto = new StripeFinto;

        $this->app->instance(StripeClient::class, $finto);

        return $finto;
    }
}
