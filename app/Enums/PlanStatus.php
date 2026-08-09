<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lo stato di una scheda o di un piano alimentare.
 *
 * 🚨 **`draft` non e' un dettaglio dell'interfaccia: e' una regola di prodotto.**
 * Una scheda a meta' non deve comparire nell'app dell'iscritto. Senza questo
 * stato, un trainer che salva per riprendere domani manderebbe in mano al
 * cliente un allenamento incompleto — e nell'app storica, dove tutto era
 * immediatamente visibile, era esattamente cosi'.
 *
 * `archived` invece di cancellare: lo storico degli allenamenti gia' fatti punta
 * alla scheda, e cancellarla lascerebbe sessioni senza origine.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Published => 'Pubblicata',
            self::Archived => 'Archiviata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Archived => 'warning',
        };
    }

    /** L'iscritto la vede nell'app? */
    public function isVisibleToMember(): bool
    {
        return $this === self::Published;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
