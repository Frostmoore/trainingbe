<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Non piu' di tre alternative alla stessa riga — G5.5 (D2).
 *
 * 🚨 **E' l'unica difesa che esiste.** Non c'e' modo di esprimere «al massimo
 * tre figli» come vincolo di database in MariaDB: un `CHECK` non puo' contare
 * righe di un'altra tabella, e un trigger sarebbe una seconda sede della stessa
 * regola.
 *
 * ⚠️ Quindi va usata in **tutte** le form request che accettano alternative —
 * schede e piani — o meta' delle porte resta aperta.
 *
 * 💡 Il limite non e' arbitrario ma non e' nemmeno tecnico: e' una scelta di
 * prodotto. Piu' di tre alternative a uno stesso alimento non sono flessibilita',
 * sono un piano che non dice piu' cosa mangiare.
 */
final class AlMassimoTreAlternative implements ValidationRule
{
    /**
     * Il limite, e **questa e' la sua unica sede** — D2.
     *
     * ⚠️ Stava nel trait `PuoAvereAlternative`, che e' il posto dove
     * concettualmente appartiene: e' una regola del dominio, non della
     * validazione. 🚨 Ma **PHP non consente di leggere una costante di trait
     * direttamente** (`Trait::COSTANTE` e' un errore fatale): si puo' solo
     * attraverso una classe che lo usa, e scegliere *quale* modello sarebbe
     * arbitrario.
     *
     * 💡 Quindi il numero vive qui e il trait lo rilegge da qui. Una sede sola,
     * raggiungibile da entrambi — che e' l'unica cosa che conta.
     */
    public const MAX = 3;

    /**
     * @param  mixed  $value  l'array di alternative arrivato dalla richiesta
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            // ⚠️ Non e' compito di questa regola dire che il tipo e' sbagliato:
            // se ne occupa `array` nella stessa catena. Dare due errori per lo
            // stesso campo confonde chi legge il modulo.
            return;
        }

        $massimo = self::MAX;

        if (count($value) > $massimo) {
            $fail("Al massimo {$massimo} alternative per ciascuna voce: qui ne sono state indicate ".count($value).'.');
        }
    }
}
