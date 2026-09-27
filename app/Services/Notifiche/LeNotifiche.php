<?php

declare(strict_types=1);

namespace App\Services\Notifiche;

use App\Enums\CanaleDellaNotifica;
use App\Enums\Notifica;
use App\Models\User;
use App\Services\Push\PushSender;
use LogicException;

/**
 * 🔔 **L'unica porta da cui esce una notifica dal server** — voce 62, 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«il sistema delle notifiche fallo autoconsistente. Voglio
 * un solo punto dove posso aggiungere notifiche»*.
 *
 * ⛔ Prima si chiamava `PushSender::toUser()` da tre posti, ognuno col suo titolo
 * scritto sul momento e il suo `tipo` inventato lì. 🚨 Niente impediva a
 * qualcuno di mandare per push un **promemoria di allenamento** — cioè di far
 * passare dal server, e da Google, un dato che per scelta lì non ci passa.
 *
 * ══ 🔑 COSA CAMBIA ════════════════════════════════════════════════════════
 *
 * Il primo parametro è un **`Notifica`**, non una stringa: ⛔ non si può mandare
 * una notifica che non sia nel catalogo, perché il tipo non lo accetta. ⚠️ E se
 * quella del catalogo è `Locale`, questo metodo **lancia**: non è un controllo
 * gentile, è un difetto di programmazione, e va visto in sviluppo.
 *
 * 💡 Il `tipo` nel carico lo mette **lei**, dal valore dell'enum: ⛔ prima era
 * una stringa scritta a mano in ogni chiamata, e l'app ci faceva `switch` sopra
 * — due posti che dovevano restare uguali per abitudine.
 */
class LeNotifiche
{
    public function __construct(private readonly PushSender $push) {}

    /**
     * 📡 Manda una notifica **push** a una persona.
     *
     * ⚠️ `$dati` è il carico silenzioso che l'app legge per sapere **dove
     * andare** toccandola. 🚨 Non ci va niente che il server non debba sapere:
     * se ci finisse il contenuto di un messaggio, lo passeremmo a Google.
     *
     * @param  array<string, string>  $dati
     * @return int quanti dispositivi hanno ricevuto
     *
     * @throws LogicException se la notifica non è di canale push
     */
    public function manda(
        Notifica $quale,
        User $a,
        string $titolo,
        string $corpo,
        array $dati = [],
    ): int {
        if ($quale->canale() !== CanaleDellaNotifica::Push) {
            throw new LogicException(
                "⛔ {$quale->value} è una notifica LOCALE: la programma l'app, non il server. "
                .'🚨 Mandarla da qui vorrebbe dire far passare dal server — e da Google — '
                .'un dato che per scelta lì non ci passa. Vedi App\\Enums\\CanaleDellaNotifica.'
            );
        }

        /*
         * 🔑 **Il `tipo` lo mette il catalogo, non chi chiama.**
         *
         * ⛔ Scritto a mano in ogni chiamata era una stringa che doveva restare
         * uguale a quella su cui l'app fa `switch`, per abitudine. 💡 Adesso
         * viene dal valore dell'enum: se si rinomina, si rinomina in un posto.
         *
         * ⚠️ Chi chiama può aggiungere i suoi dati, ma **non sovrascrivere il
         * tipo**: per questo `$dati` viene prima e `tipo` dopo.
         */
        return $this->push->toUser($a, $titolo, $corpo, [
            ...$dati,
            'tipo' => $quale->value,
        ]);
    }
}
