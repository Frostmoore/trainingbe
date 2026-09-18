<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Stripe\Exception\CardException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * Il finto: ogni `->servizio->metodo(...)` finisce in [chiamate].
 */
final class StripeFinto extends StripeClient
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $chiamate = [];

    /**
     * Se valorizzato, la prossima chiamata a questo percorso lancia. Serve a
     * provare **cosa succede quando Stripe dice di no** — il caso che in
     * produzione capita e nei test non capitava mai.
     */
    public ?string $falliscaSu = null;

    public function __construct()
    {
        parent::__construct('sk_test_finto_per_i_test');
    }

    public function __get($name): ServizioStripeFinto
    {
        return new ServizioStripeFinto($this, (string) $name);
    }

    /** Le chiamate fatte a questo percorso, es. `checkout.sessions.create`. */
    public function chiamateA(string $percorso): array
    {
        return array_values(array_filter(
            $this->chiamate,
            fn (array $c): bool => $c[0] === $percorso,
        ));
    }

    /** Gli argomenti dell'ultima chiamata a questo percorso. */
    public function ultima(string $percorso): ?array
    {
        $tutte = $this->chiamateA($percorso);

        return $tutte === [] ? null : end($tutte)[1];
    }

    /** @param  array<int, mixed>  $argomenti */
    public function rispondi(string $percorso, array $argomenti): StripeObject
    {
        $this->chiamate[] = [$percorso, $argomenti];

        if ($this->falliscaSu === $percorso) {
            throw new CardException("Stripe finto: {$percorso} rifiutato.");
        }

        $n = count($this->chiamate);

        return StripeObject::constructFrom(match ($percorso) {
            'checkout.sessions.create' => [
                'id' => "cs_test_finto_{$n}",
                'url' => "https://checkout.stripe.test/finto/{$n}",
            ],
            'customers.create' => ['id' => "cus_finto_{$n}"],
            'setupIntents.retrieve' => ['id' => 'seti_finto', 'payment_method' => 'pm_finto'],
            'subscriptions.retrieve' => [
                'id' => $argomenti[0] ?? 'sub_finto',
                'items' => ['data' => [['id' => 'si_finto']]],
            ],
            'invoices.create' => ['id' => "in_finto_{$n}"],
            'billingPortal.sessions.create' => ['url' => "https://billing.stripe.test/finto/{$n}"],
            default => ['id' => "finto_{$n}"],
        });
    }
}
