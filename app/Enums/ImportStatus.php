<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lo stato di un import da PDF.
 *
 * 🚨 **`review` non e' uno stato tecnico: e' un cancello.** Fra «il modello ha
 * finito» e «la scheda esiste» ci deve stare una persona. Senza questo stato la
 * sequenza sarebbe letto → pubblicato, e un import sbagliato arriverebbe in mano
 * a un iscritto che si allena seguendolo.
 */
enum ImportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Review = 'review';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'In coda',
            self::Processing => 'In lettura',
            self::Review => 'Da rivedere',
            self::Done => 'Pubblicata',
            self::Failed => 'Non riuscito',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Processing => 'gray',
            self::Review => 'warning',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }
}
