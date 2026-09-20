<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ✉️ **A che punto è una richiesta di contatto** — 21/09/2026.
 *
 * 💡 Tre stati e non di più: `nuova` (nessuno l'ha ancora guardata), `presa`
 * (qualcuno ci sta parlando), `chiusa` (finita, bene o male). ⛔ Un quarto
 * stato — «in attesa di risposta del cliente» — sembra utile e non lo è: chi
 * lavora una richiesta lo scrive in `note_interne`, e uno stato in più è uno
 * stato che nessuno aggiorna.
 */
enum StatoDellaRichiesta: string
{
    case Nuova = 'nuova';
    case Presa = 'presa';
    case Chiusa = 'chiusa';

    public function label(): string
    {
        return match ($this) {
            self::Nuova => 'Nuova',
            self::Presa => 'Presa in carico',
            self::Chiusa => 'Chiusa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Nuova => 'warning',
            self::Presa => 'info',
            self::Chiusa => 'gray',
        };
    }
}
