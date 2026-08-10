<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I fornitori di identita' esterni ammessi.
 *
 * 🚨 **Un enum e non una stringa.** E' lo stesso difetto che ha morso su
 * `meal` in C0.5: una stringa libera su un valore chiuso non da' errore, passa
 * la validazione e produce una riga che nessuna query ritrovera' piu'. Qui
 * sarebbe peggio, perche' quella riga e' un'identita' di accesso.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Apple => 'Apple',
        };
    }

    /**
     * ⚠️ **Apple verifica sempre l'email, Google no.**
     *
     * Google consegna `email_verified`, che puo' essere falso sugli account
     * Workspace non confermati. Il collegamento a un account esistente per
     * corrispondenza di email si fa **solo** con un'email verificata: senza
     * quella condizione, chiunque riesca a registrare un account Google con
     * l'email di qualcun altro entrerebbe nel suo account qui dentro.
     */
    public function verificaSempreEmail(): bool
    {
        return $this === self::Apple;
    }
}
