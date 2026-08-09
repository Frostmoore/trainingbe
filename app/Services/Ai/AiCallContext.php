<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\User;

/**
 * Chi sta facendo questa chiamata AI, e per cosa.
 *
 * Viaggia insieme a ogni richiesta verso un provider perche' senza di lei il
 * consumo non e' attribuibile: i token si contano una volta sola, al momento
 * della chiamata, e se in quel momento non si sa a quale palestra addebitarli
 * non lo si scopre piu' dopo.
 *
 * `readonly`: una volta costruita non cambia. Un contesto modificabile mentre la
 * chiamata e' in corso significherebbe token attribuiti a chi capita.
 */
final readonly class AiCallContext
{
    public function __construct(
        public ?int $tenantId,
        public int $userId,
        public AiFeature $feature,
    ) {}

    public static function for(User $user, AiFeature $feature): self
    {
        return new self(
            tenantId: $user->tenant_id,
            userId: (int) $user->getKey(),
            feature: $feature,
        );
    }
}
