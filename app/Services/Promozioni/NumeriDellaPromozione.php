<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Models\PagamentoIncassato;
use App\Models\Promozione;
use App\Support\Tenancy\TenantContext;

/**
 * 📊 **I numeri di una promozione** — 24/09/2026, P7 di `plan_promozioni.md`
 * (opzione 40).
 *
 * 📌 *«quante persone l'hanno usata, quanti gettoni regalati, quanti €
 * scontati, e quanto ha fruttato»*.
 *
 * ⚠️ «Quanto ha fruttato» = la somma degli **incassi** del registro legati ai
 * pagamenti in cui la promozione è stata usata (per riferimento Stripe). Non è
 * un guadagno netto: dice quanto è entrato **insieme** alla promozione, e il
 * registro parte dal 22/09/2026. Il consumo mensile non ha un id Stripe nel
 * riferimento, quindi lì si conta solo lo sconto.
 */
class NumeriDellaPromozione
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{persone: int, volte: int, revocate: int, gettoni: int, scontato_cent: int, incassato_cent: int}
     */
    public function di(Promozione $promozione): array
    {
        $contano = $promozione->assegnazioni()->cheContano();

        $riferimenti = (clone $contano)->whereNotNull('riferimento')->pluck('riferimento')
            ->map(fn (string $r): string => (string) last(explode(':', $r)))
            ->filter(fn (string $r): bool => str_starts_with($r, 'cs_') || str_starts_with($r, 'in_'))
            ->unique()
            ->values();

        $incassato = $riferimenti->isEmpty() ? 0 : (int) $this->context->runWithoutTenant(
            fn (): mixed => PagamentoIncassato::query()->whereIn('stripe_id', $riferimenti)->sum('importo_cent'),
        );

        return [
            'persone' => (clone $contano)->distinct()->count('user_id'),
            'volte' => (clone $contano)->count(),
            'revocate' => $promozione->assegnazioni()->where('stato', 'revocata')->count(),
            'gettoni' => (int) (clone $contano)->sum('gettoni'),
            'scontato_cent' => (int) (clone $contano)->sum('scontato_cent'),
            'incassato_cent' => $incassato,
        ];
    }
}
