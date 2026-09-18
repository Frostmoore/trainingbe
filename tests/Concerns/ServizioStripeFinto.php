<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Stripe\StripeObject;

/** Un ramo del finto: `checkout`, poi `sessions`, poi `create(...)`. */
final class ServizioStripeFinto
{
    public function __construct(
        private readonly StripeFinto $stripe,
        private readonly string $percorso,
    ) {}

    public function __get(string $name): self
    {
        return new self($this->stripe, $this->percorso.'.'.$name);
    }

    /** @param  array<int, mixed>  $argomenti */
    public function __call(string $metodo, array $argomenti): StripeObject
    {
        return $this->stripe->rispondi($this->percorso.'.'.$metodo, $argomenti);
    }
}
