<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎁 **Lo stato di una promozione** — 22/09/2026, fase F2.
 *
 * ⚠️ **«Scaduta» non è qui**, ed è una scelta: si ricava dalle date. Tenerla in
 * colonna vorrebbe dire un lavoro notturno che la aggiorna — e una promozione
 * che resta «attiva» finché quel lavoro non gira.
 */
enum StatoPromozione: string
{
    case Attiva = 'attiva';
    case Sospesa = 'sospesa';
    case Disattivata = 'disattivata';

    public function label(): string
    {
        return match ($this) {
            self::Attiva => 'Attiva',
            self::Sospesa => 'Sospesa',
            self::Disattivata => 'Disattivata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Attiva => 'success',
            self::Sospesa => 'warning',
            self::Disattivata => 'gray',
        };
    }
}
