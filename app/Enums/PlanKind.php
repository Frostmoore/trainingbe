<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A chi si rivolge un piano — F4.1 della Parte B.
 *
 * 💡 Serve a due cose concrete, non e' una categoria decorativa:
 *
 * 1. il **listino** del sito (F9) mostra tre colonne diverse a tre pubblici
 *    diversi, e senza questo campo dovrebbe indovinarle dal nome;
 * 2. un piano da palestra non deve poter finire addosso a una persona, ne'
 *    viceversa: `PianoAttivo::assegnabileA()` lo verifica.
 */
enum PlanKind: string
{
    /** Una persona, con o senza palestra. */
    case Person = 'person';

    /** Un trainer indipendente: paga per se' e per i propri utenti. */
    case Trainer = 'trainer';

    /** Una palestra: paga a scaglioni di iscritti. */
    case Gym = 'gym';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Persona',
            self::Trainer => 'Trainer indipendente',
            self::Gym => 'Palestra',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $o = [];

        foreach (self::cases() as $c) {
            $o[$c->value] = $c->label();
        }

        return $o;
    }
}
