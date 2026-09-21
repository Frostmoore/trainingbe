<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 💸 **Percentuale o importo** — 22/09/2026, fase F2.
 *
 * 🚨 **Non si deduce dal valore.** `20` in una promozione percentuale è un
 * quinto del prezzo; in una a importo sono venti centesimi. ⛔ Indovinare
 * vorrebbe dire sbagliare su un prezzo, che è l'unico posto dove un errore si
 * paga in soldi veri.
 */
enum TipoDiSconto: string
{
    case Percentuale = 'percentuale';
    case Importo = 'importo';

    public function label(): string
    {
        return match ($this) {
            self::Percentuale => 'Percentuale',
            self::Importo => 'Importo fisso',
        };
    }

    /** Come si scrive il valore di questa promozione. */
    public function scrivi(int $valore): string
    {
        return match ($this) {
            self::Percentuale => $valore.'%',
            self::Importo => number_format($valore / 100, 2, ',', '.').' €',
        };
    }
}
