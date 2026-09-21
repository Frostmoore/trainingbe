<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 **A chi si applica una promozione** — 22/09/2026, fase F2.
 *
 * 📌 *«per tutti, per alcuni ruoli, per gli allievi di una palestra o di un
 * trainer specifico o per un allievo in particolare (multipli)»*.
 *
 * 💡 Cinque casi espliciti invece di una relazione polimorfica: *«gli allievi
 * di questo trainer»* non è un record, è una **domanda** — e un morph non
 * saprebbe farla.
 */
enum TipoBersaglio: string
{
    case Tutti = 'tutti';
    case Ruolo = 'ruolo';
    case Palestra = 'palestra';
    case AllieviDiTrainer = 'allievi_di_trainer';
    case Utente = 'utente';

    public function label(): string
    {
        return match ($this) {
            self::Tutti => 'Tutti',
            self::Ruolo => 'Un ruolo',
            self::Palestra => 'Una palestra',
            self::AllieviDiTrainer => 'Gli allievi di un trainer',
            self::Utente => 'Una persona',
        };
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
}
