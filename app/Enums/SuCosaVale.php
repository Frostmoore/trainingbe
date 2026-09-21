<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 **Su cosa vale una promozione** — 22/09/2026, fase F2.
 *
 * 📌 *«Valgono su tutto»*, ma si sceglie voce per voce: una promozione sui
 * gettoni non deve scontare per sbaglio l'abbonamento di una palestra, e i due
 * pagamenti passano per strade diverse (`Cassa` e `PianiInVendita`).
 */
enum SuCosaVale: string
{
    /** L'abbonamento a canone: il piano personale, trainer o palestra. */
    case Canone = 'canone';

    /** I pacchetti di gettoni. */
    case Gettoni = 'gettoni';

    /** ⚠️ Il piano a consumo, che fatturiamo noi a mese chiuso. */
    case Consumo = 'consumo';

    public function label(): string
    {
        return match ($this) {
            self::Canone => 'Abbonamenti a canone',
            self::Gettoni => 'Pacchetti di gettoni',
            self::Consumo => 'Piano a consumo',
        };
    }

    /** @return array<string, string> per le tendine del pannello */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            $out[$caso->value] = $caso->label();
        }

        return $out;
    }
}
