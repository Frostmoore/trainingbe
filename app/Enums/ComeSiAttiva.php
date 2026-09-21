<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ⚡ **Come arriva una promozione a chi la riceve** — 24/09/2026, fase P1.
 *
 * ⛔ **Niente codici sconto** (24/09/2026): 📌 *«il codice sconto non deve
 * esistere, le promo si attivano solo in automatico o con il link di
 * attivazione»*. Massa ed evento sono due modi dell'automatico: nessuno scrive
 * niente.
 *
 * | Caso | Cosa succede |
 * |---|---|
 * | `automatica` | vale da sola per chi rientra nei destinatari |
 * | `link` | chi apre l'indirizzo la **attiva**: i regali arrivano, lo sconto resta pronto per il pagamento |
 * | `massa` | «Esegui»: i regali arrivano adesso a tutti i destinatari (P2) |
 * | `evento` | quando succede qualcosa: registrazione, rinnovo… (P6) |
 */
enum ComeSiAttiva: string
{
    case Automatica = 'automatica';
    case Link = 'link';
    case Massa = 'massa';
    case Evento = 'evento';

    public function label(): string
    {
        return match ($this) {
            self::Automatica => 'Automatica',
            self::Link => 'Con un link',
            self::Massa => 'Accredito di massa',
            self::Evento => 'Quando succede qualcosa',
        };
    }

    public function descrizione(): string
    {
        return match ($this) {
            self::Automatica => 'Vale da sola per chi rientra nei destinatari.',
            self::Link => 'Si manda il link: chi lo apre la attiva, dall\'app o dal pannello.',
            self::Massa => 'Premi «Esegui» e i regali arrivano subito a tutti i destinatari.',
            self::Evento => 'Scatta da sola quando la persona fa qualcosa.',
        };
    }

    /**
     * 🚧 **Funziona già?** Come per i premi: una scelta che non fa niente non
     * compare. Da P8 sono tutte costruite (massa da P2, evento da P6).
     */
    public function costruito(): bool
    {
        return true;
    }
}
