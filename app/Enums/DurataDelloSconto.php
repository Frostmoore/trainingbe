<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ⏳ **Per quanto vale uno sconto sull'abbonamento** — 24/09/2026, fase P1.
 *
 * Diventa la `duration` del coupon di Stripe (§4.6 del piano): `once`,
 * `repeating` con `duration_in_months`, `forever`.
 */
enum DurataDelloSconto: string
{
    case UnaVolta = 'una_volta';
    case NVolte = 'n_volte';
    case PerSempre = 'per_sempre';

    public function label(): string
    {
        return match ($this) {
            self::UnaVolta => 'Solo il primo pagamento',
            self::NVolte => 'Per N rinnovi',
            self::PerSempre => 'Per sempre',
        };
    }

    /** 🆕 P3 — tutte e tre: diventano la `duration` del coupon di Stripe. */
    public function costruito(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            if ($caso->costruito()) {
                $out[$caso->value] = $caso->label();
            }
        }

        return $out;
    }
}
