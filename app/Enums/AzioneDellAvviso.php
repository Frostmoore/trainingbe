<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 **Cosa fa il tasto «approva»** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente: *«Il tasto per approvare deve poter fare una serie di cose:
 * dismiss (chiude la modale e basta), link (rimanda a un link), promo (attiva
 * una promozione) e logga»*.
 *
 * ⚠️ **«E logga» non è una quarta azione**: è quello che succede **sempre**,
 * qualunque sia la scelta — e vale anche per il rifiuto. 💡 Per questo qui i
 * casi sono tre e il registro è un'altra cosa (`avvisi_decisioni`).
 */
enum AzioneDellAvviso: string
{
    /** Chiude e basta. 💡 È il valore di serie: non fare niente è la cosa più prudente. */
    case Dismiss = 'dismiss';

    /** Apre un indirizzo, poi chiude. */
    case Link = 'link';

    /**
     * Attiva una promozione.
     *
     * 🚨 **Si esegue sul server**, dentro la risposta alla decisione: farla
     * eseguire all'app vorrebbe dire lasciare che sia il telefono a dire
     * «assegnami questa promozione».
     */
    case Promo = 'promo';

    public function etichetta(): string
    {
        return match ($this) {
            self::Dismiss => 'Chiude e basta',
            self::Link => 'Apre un link',
            self::Promo => 'Attiva una promozione',
        };
    }
}
