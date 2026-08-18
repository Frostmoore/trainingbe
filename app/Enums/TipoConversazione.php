<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Che genere di filo e' — M4.1, 18/08/2026.
 *
 * 🚨 **Da qui dipende se il limite dei tre messaggi si applica.** Non e' una
 * etichetta descrittiva: e' il campo che decide se una persona puo' continuare a
 * scrivere.
 */
enum TipoConversazione: string
{
    /**
     * Fra chi si conosce gia': un iscritto e un trainer della sua palestra, o
     * qualcuno e il trainer che lo segue.
     *
     * 💡 **Nessun limite**, e non lo avra' mai: e' il rapporto per cui il
     * prodotto esiste. E' anche il valore di serie, cosi' tutte le conversazioni
     * scritte prima del 18/08 restano quello che erano.
     */
    case Iscritto = 'iscritto';

    /**
     * Nata dal catalogo: qualcuno che chiede a una palestra o a un trainer
     * **come ci si iscrive**.
     *
     * ⚠️ Qui il limite dei tre messaggi per parte vale — **a meno che** chi
     * scrive sia abbonato. Il limite non e' contro il primo contatto: e' quello
     * che rende l'abbonamento una cosa che si capisce da soli.
     */
    case Informazioni = 'informazioni';

    /** Se a questo tipo si applica il limite dei tre messaggi. */
    public function haIlLimite(): bool
    {
        return $this === self::Informazioni;
    }
}
