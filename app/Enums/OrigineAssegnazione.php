<?php

declare(strict_types=1);

namespace App\Enums;

/** 🧾 **Da dove è arrivata un'assegnazione** — 24/09/2026, fase P1. */
enum OrigineAssegnazione: string
{
    case Automatica = 'automatica';
    case Codice = 'codice';
    case Link = 'link';
    case Massa = 'massa';
    case Evento = 'evento';
    case Amico = 'amico';

    public function label(): string
    {
        return match ($this) {
            self::Automatica => 'Automatica',
            self::Codice => 'Codice',
            self::Link => 'Link',
            self::Massa => 'Accredito di massa',
            self::Evento => 'Evento',
            self::Amico => 'Porta un amico',
        };
    }
}
