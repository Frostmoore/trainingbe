<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🧾 **A che punto è un'assegnazione** — 24/09/2026, fase P1.
 *
 * ⚠️ `assegnata` = data ma non ancora usata (uno sconto sbloccato, un regalo
 * ricorrente in corso); `usata` = spesa. Contano tutte e due per il massimo
 * per persona: ⛔ altrimenti chi non ha ancora usato la prima ne prenderebbe
 * un'altra.
 */
enum StatoAssegnazione: string
{
    case Assegnata = 'assegnata';
    case Usata = 'usata';
    case Revocata = 'revocata';
    case Scaduta = 'scaduta';

    public function label(): string
    {
        return match ($this) {
            self::Assegnata => 'Assegnata',
            self::Usata => 'Usata',
            self::Revocata => 'Revocata',
            self::Scaduta => 'Scaduta',
        };
    }

    /** Conta per i limiti e per i numeri. */
    public function conta(): bool
    {
        return $this === self::Assegnata || $this === self::Usata;
    }

    /** @return list<string> */
    public static function cheContano(): array
    {
        return [self::Assegnata->value, self::Usata->value];
    }
}
