<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎁 **Cosa promuove una promozione** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * 📌 *«su promozioni->nuovo ci deve essere una scelta: a) Gettoni b) Abbonamenti
 * c) entrambe. Dopo di che, devo avere un form diverso per ciascuna scelta»*.
 *
 * 🚨 **Non è un filtro estetico**: decide quali premi si possono dare, ed è la
 * difesa contro «una promozione sui gettoni che sconta per sbaglio
 * l'abbonamento di una palestra» — la ragione per cui F2 aveva `su_cosa`.
 */
enum GenereDiPromozione: string
{
    case Gettoni = 'gettoni';
    case Abbonamenti = 'abbonamenti';
    case Entrambi = 'entrambi';

    public function label(): string
    {
        return match ($this) {
            self::Gettoni => 'Gettoni',
            self::Abbonamenti => 'Abbonamenti',
            self::Entrambi => 'Gettoni e abbonamenti',
        };
    }

    public function descrizione(): string
    {
        return match ($this) {
            self::Gettoni => 'Gettoni in regalo, bonus sull\'acquisto, sconti e prezzi speciali sui pacchetti.',
            self::Abbonamenti => 'Sconti, prove gratuite, mesi regalati, upgrade e posti extra.',
            self::Entrambi => 'Tutto quello sopra insieme, più i gettoni a ogni rinnovo e gli sconti che ne sbloccano altri.',
        };
    }

    /**
     * I premi che questo genere può dare — tabella §4.2 del piano.
     *
     * @return list<TipoDiPremio>
     */
    public function premiAmmessi(): array
    {
        return array_values(array_filter(
            TipoDiPremio::cases(),
            fn (TipoDiPremio $tipo): bool => in_array($this, $tipo->generi(), true),
        ));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            $out[$caso->value] = $caso->label();
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function descrizioni(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            $out[$caso->value] = $caso->descrizione();
        }

        return $out;
    }
}
