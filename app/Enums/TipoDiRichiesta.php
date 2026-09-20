<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ✉️ **Chi ci sta scrivendo dal modulo di contatto** — 21/09/2026.
 *
 * 🚨 È il campo che decide **quanto in fretta si risponde**: una palestra che
 * chiede un piano su misura vale trecento euro al mese, un curioso che chiede
 * se l'app è gratis vale una riga di risposta. ⛔ Senza questo campo l'elenco
 * del pannello sarebbe una casella di posta indifferenziata, e le due cose si
 * leggerebbero con la stessa fretta — cioè con quella sbagliata per una delle
 * due.
 */
enum TipoDiRichiesta: string
{
    case Palestra = 'palestra';
    case Trainer = 'trainer';
    case Altro = 'altro';

    public function label(): string
    {
        return match ($this) {
            self::Palestra => 'Palestra',
            self::Trainer => 'Trainer',
            self::Altro => 'Altro',
        };
    }

    /** L'etichetta che legge chi compila il modulo, in prima persona. */
    public function comeSiPresenta(): string
    {
        return match ($this) {
            self::Palestra => 'Sono una palestra o uno studio',
            self::Trainer => 'Sono un trainer o un professionista',
            self::Altro => 'Altro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Palestra => 'success',
            self::Trainer => 'info',
            self::Altro => 'gray',
        };
    }
}
