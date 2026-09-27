<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🔔 **Da dove parte una notifica** — voce 62, 27/09/2026.
 *
 * ══ 📌 LA REGOLA, IN UNA RIGA ═════════════════════════════════════════════
 *
 * 📌 Il committente, 26/09: *«le push saranno per messaggi e comunicazioni di
 * servizio, quelle locali saranno per allenamenti e cose interne dell'app
 * riguardanti i dati che non transitano sul server»*.
 *
 * 🔑 **È push solo se l'evento che la genera è già sul server.** ⛔ Se per
 * mandarla il server dovesse *venire a sapere* qualcosa che oggi non sa, allora
 * è locale — o non si fa.
 *
 * 💡 Formulata così regge anche sui casi che oggi non immaginiamo: è una domanda
 * che ci si può porre su qualunque notifica nuova, invece di un elenco da
 * ricordare a memoria.
 *
 * ══ ⚠️ PERCHE' NON E' UNA COMODITA' ═══════════════════════════════════════
 *
 * Gli allenamenti e il diario **stanno sul telefono** — il diario ha lasciato il
 * server il 22/09/2026. ⛔ Mandare una push per ricordare un allenamento
 * vorrebbe dire far passare dal server **e da Google**, che è chi consegna le
 * push, un'informazione che per scelta lì non ci passa.
 *
 * 🚨 Sarebbe il contrario di tutta l'architettura di questo progetto, fatto
 * senza cattiveria e senza che nessuno se ne accorga.
 */
enum CanaleDellaNotifica: string
{
    /**
     * 📡 Parte dal **server**, passa da FCM e da Apple/Google.
     *
     * ⚖️ Nell'informativa e nella DPIA è un trattamento con Google come
     * **sub-responsabile** (§129.3): ogni notifica messa qui è una cosa che
     * dichiariamo di far passare da loro.
     */
    case Push = 'push';

    /**
     * 📱 La programma l'**app**, sul dispositivo. Non esce di lì.
     *
     * ✅ Funziona anche senza rete, e ⚖️ **non è un trattamento con un
     * sub-responsabile**: è un punto a nostro favore, e va detto esplicitamente
     * invece di lasciarlo implicito.
     *
     * ⚠️ Limite pratico: si programmano in anticipo (iOS ne tiene una sessantina
     * per app). 💡 Vanno bene per i promemoria a orario; ⛔ non per cose che
     * dipendono da quello che sta succedendo adesso.
     */
    case Locale = 'locale';

    public function etichetta(): string
    {
        return match ($this) {
            self::Push => 'Push (dal server)',
            self::Locale => 'Locale (dal telefono)',
        };
    }
}
