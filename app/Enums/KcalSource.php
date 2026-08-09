<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Come si e' arrivati al numero di calorie bruciate.
 *
 * 🚨 **E' la colonna che tiene in piedi la regola piu' importante del dominio
 * allenamento: il valore manuale batte sempre la stima.**
 *
 * Nell'app storica dashboard, calendario e diario mostravano tre numeri diversi
 * per lo stesso allenamento, perche' ognuno ricalcolava per conto suo e non
 * c'era modo di sapere se il valore salvato fosse una stima o una correzione
 * dell'utente. Con questa colonna la domanda «posso sovrascriverlo?» ha una
 * risposta leggibile da una riga di SQL.
 */
enum KcalSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case Formula = 'formula';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Inserito a mano',
            self::Ai => 'Stimato con AI',
            self::Formula => 'Calcolato (MET)',
        };
    }

    /**
     * Una nuova stima puo' sovrascrivere questo valore?
     *
     * No, se lo ha scritto una persona. Una stima che cancella una correzione
     * manuale e' il modo piu' rapido per far smettere l'utente di correggere.
     */
    public function canBeOverwrittenByEstimate(): bool
    {
        return $this !== self::Manual;
    }
}
