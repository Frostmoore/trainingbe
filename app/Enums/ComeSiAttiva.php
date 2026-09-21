<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ⚡ **Come arriva una promozione a chi la riceve** — 24/09/2026, fase P1.
 *
 * | Caso | Cosa succede |
 * |---|---|
 * | `automatica` | vale da sola per chi rientra nei destinatari |
 * | `codice` | si scrive il codice al pagamento (il campo arriva in P8) |
 * | `link` | si apre l'indirizzo che la porta |
 * | `massa` | «Esegui»: i regali arrivano adesso a tutti i destinatari (P2) |
 * | `evento` | quando succede qualcosa: registrazione, rinnovo… (P6) |
 */
enum ComeSiAttiva: string
{
    case Automatica = 'automatica';
    case Codice = 'codice';
    case Link = 'link';
    case Massa = 'massa';
    case Evento = 'evento';

    public function label(): string
    {
        return match ($this) {
            self::Automatica => 'Automatica',
            self::Codice => 'Con un codice',
            self::Link => 'Con un link',
            self::Massa => 'Accredito di massa',
            self::Evento => 'Quando succede qualcosa',
        };
    }

    public function descrizione(): string
    {
        return match ($this) {
            self::Automatica => 'Vale da sola per chi rientra nei destinatari.',
            self::Codice => 'Chi ha il codice lo scrive al pagamento.',
            self::Link => 'Si manda l\'indirizzo: chi lo apre vede l\'offerta nel suo pannello.',
            self::Massa => 'Premi «Esegui» e i regali arrivano subito a tutti i destinatari.',
            self::Evento => 'Scatta da sola quando la persona fa qualcosa.',
        };
    }

    /**
     * 🚧 **Funziona già?** Come per i premi: una scelta che non fa niente non
     * compare. `codice` aspetta il campo al pagamento (P8), `evento` gli
     * agganci (P6).
     */
    public function costruito(): bool
    {
        // 🆕 P2 — `massa`, con i regali.
        // 🆕 P6 — `evento`.
        return in_array($this, [self::Automatica, self::Link, self::Massa, self::Evento], true);
    }

    /** Si spende solo presentando il codice (a mano o dal link). */
    public function serveIlCodice(): bool
    {
        return $this === self::Codice || $this === self::Link;
    }
}
