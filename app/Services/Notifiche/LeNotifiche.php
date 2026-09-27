<?php

declare(strict_types=1);

namespace App\Services\Notifiche;

use App\Enums\CanaleDellaNotifica;
use App\Enums\Notifica;
use App\Jobs\MandaLaNotifica;
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
     * 📡 **Manda una notifica push a una persona — accodandola.**
     *
     * ══ 📌 PERCHE' ACCODA, E NON MANDA SUBITO ═════════════════════════════
     *
     * 📌 Il committente, 27/09: *«notifiche e email passano per una queue tipo
     * redis? [...] se non lo fanno poi succede sempre un casino con le
     * notifiche»*. ⚖️ Aveva ragione, e la risposta era «solo in parte».
     *
     * 🚨 `PushSender` fa **una chiamata HTTP a Google per ogni dispositivo**.
     * Farlo dentro una richiesta vuol dire, nei casi veri: un webhook di Stripe
     * che va in timeout e viene **rimandato**, una schermata del pannello ferma, e
     * un guasto di rete di Google che si presenta come un errore nostro.
     *
     * 🔑 Accodando qui — nella **porta** — la regola vale per tutte e undici le
     * push senza che nessun chiamante debba ricordarsela. ⛔ Metterla nei
     * chiamanti sarebbe undici posti da non sbagliare.
     *
     * ══ ⚠️ `afterCommit()`, E NON E' UN DETTAGLIO ═════════════════════════
     *
     * 🚨 Alcune notifiche nascono dentro una transazione. ⛔ Senza `afterCommit()`
     * il worker potrebbe consegnarla **prima** che il fatto sia scritto a terra, o
     * dopo che è stato annullato. 💡 Una push non si ritira: è già sul telefono.
     *
     * ══ 💡 E IL CONTROLLO SUL CANALE STA PRIMA DELL'ACCODAMENTO ═══════════
     *
     * ⚖️ Di proposito: mandare per push una notifica **locale** è un difetto di
     * programmazione, e deve esplodere **dove è stato scritto** — non nel worker
     * mezz'ora dopo, in un registro che nessuno guarda.
     *
     * ⚠️ `$dati` è il carico silenzioso che l'app legge per sapere **dove
     * andare** toccandola. 🚨 Non ci va niente che il server non debba sapere:
     * se ci finisse il contenuto di un messaggio, lo passeremmo a Google.
     *
     * @param  array<string, string>  $dati
     *
     * @throws LogicException se la notifica non è di canale push
     */
    public function manda(
        Notifica $quale,
        User $a,
        string $titolo,
        string $corpo,
        array $dati = [],
    ): void {
        $this->pretendiCheSiaPush($quale);

        MandaLaNotifica::dispatch(
            $quale,
            (int) $a->getKey(),
            $titolo,
            $corpo,
            $dati,
        )->afterCommit();
    }

    /**
     * 📤 **La consegna vera, adesso e in questo processo.**
     *
     * ⚠️ **La chiama `MandaLaNotifica`**, e nessun altro dovrebbe: chi manda una
     * notifica usa `manda()`, che accoda. 🚨 Chiamare questa da una richiesta
     * rimette l'HTTP verso Google dentro la richiesta — cioè il difetto che la
     * coda è venuta a chiudere.
     *
     * 💡 È `public` perché il job vive in un'altra classe e deve poterla
     * chiamare; ⛔ non perché sia una seconda strada ammessa. `IlCatalogoDelleNotificheTest`
     * verifica che nessuno la usi fuori dal job.
     *
     * @param  array<string, string>  $dati
     * @return int quanti dispositivi hanno ricevuto
     *
     * @throws LogicException se la notifica non è di canale push
     */
    public function consegnaAdesso(
        Notifica $quale,
        User $a,
        string $titolo,
        string $corpo,
        array $dati = [],
    ): int {
        $this->pretendiCheSiaPush($quale);

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
        /*
         * 🆕 🧭 **La destinazione viaggia col carico** — 27/09/2026.
         *
         * 📌 Il committente: *«ci deve essere un altro parametro nelle notifiche:
         * dove rimanda»*.
         *
         * 🔑 E la mette **il catalogo**, non chi chiama: `manda()` non ha un
         * parametro per la destinazione, quindi due notifiche dello stesso tipo
         * non possono finire su pagine diverse. ⛔ È la differenza fra una
         * proprietà della notifica e un argomento che qualcuno dimentica.
         *
         * ⚠️ `dove` è un **nome**, non un percorso: l'app lo traduce con un
         * elenco chiuso. 🚨 Un percorso vorrebbe dire che chi controlla il
         * server fa aprire all'app qualunque schermata.
         */
        $dove = $quale->destinazione();

        return $this->push->toUser($a, $titolo, $corpo, [
            ...$dati,
            'tipo' => $quale->value,
            ...($dove === null ? [] : ['dove' => $dove]),
        ]);
    }

    /**
     * 🚨 **Una notifica locale non esce dal server.**
     *
     * ⚠️ Lancia, non restituisce `false`: ⛔ un rifiuto silenzioso sarebbe una
     * notifica che non arriva e nessuno che se ne accorge. 💡 È un difetto di
     * programmazione, e va visto in sviluppo.
     *
     * 🔑 Sta in un metodo suo perché il controllo serve **due volte** — a
     * `manda()`, che accoda, e a `consegnaAdesso()`, che consegna — e le due
     * risposte devono essere la stessa.
     *
     * @throws LogicException
     */
    private function pretendiCheSiaPush(Notifica $quale): void
    {
        if ($quale->canale() === CanaleDellaNotifica::Push) {
            return;
        }

        throw new LogicException(
            "⛔ {$quale->value} è una notifica LOCALE: la programma l'app, non il server. "
            .'🚨 Mandarla da qui vorrebbe dire far passare dal server — e da Google — '
            .'un dato che per scelta lì non ci passa. Vedi App\\Enums\\CanaleDellaNotifica.'
        );
    }

    /**
     * 🆕 📧 **A chiunque abbia questa email** — voce 62, 27/09/2026.
     *
     * ══ 📌 QUANDO SERVE ═══════════════════════════════════════════════════
     *
     * ⚠️ Tre notifiche nascono da un **indirizzo**, non da una persona: l'invito
     * di un trainer, l'invito di una palestra e la risposta a una richiesta dal
     * sito. 🚨 In tutti e tre, chi scrive conosce solo l'email — chi la riceve
     * può anche non avere un account.
     *
     * ⛔ Senza questo metodo la stessa ricerca stava scritta in tre posti, e la
     * prima che qualcuno avesse copiato male avrebbe mandato notifiche
     * all'account sbagliato senza dare nessun errore.
     *
     * ══ 🔑 A TUTTI GLI ACCOUNT CON QUELL'EMAIL, E NON E' UN DIFETTO ═══════
     *
     * 🚨 `users.email` è unica **per palestra**, non per piattaforma (migrazione
     * del 09/08/2026: *«la stessa persona può essere iscritta a due palestre
     * diverse con lo stesso indirizzo»*). 💡 Quindi più righe sono la stessa
     * persona, e si avvisa su tutte: `PushSender` parla ai dispositivi, e il
     * dispositivo è uno.
     *
     * ══ ⚠️ SENZA LO SCOPE, E DI PROPOSITO ═════════════════════════════════
     *
     * 🔑 `withoutGlobalScopes()` perché la domanda *«chi ha questa email?»* non è
     * una domanda di una palestra: ⛔ filtrando, chi manda dal pannello di
     * piattaforma (contesto vuoto) troverebbe tutti e chi manda da una richiesta
     * HTTP troverebbe solo i suoi. 🚨 Lo stesso invio funzionerebbe o no a
     * seconda di dove parte — lo stesso difetto che `PushSender` aveva sui token.
     *
     * ⚠️ E si escludono i cancellati (`deleted_at`): un account eliminato non
     * riceve notifiche. 💡 `AccountEraser` toglie già i suoi token, quindi è una
     * cintura in più, non l'unica.
     *
     * ══ 🚨 NON SI MANDA NIENTE DI RISERVATO PER QUESTA STRADA ═════════════
     *
     * 🔑 Le email **non sono verificate** in questa applicazione (`User` non
     * implementa `MustVerifyEmail`, e non esistono rotte di verifica). ⛔ Quindi
     * chi si registra con l'indirizzo di un altro riceve quello che si manda da
     * qui. ⚠️ Per questo i testi che passano da questo metodo dicono **che c'è
     * qualcosa**, e mai cosa: chi non è il destinatario non deve poterci ricavare
     * niente, e soprattutto non deve poterci **agire**.
     *
     * @param  array<string, string>  $dati
     * @return int a quante **persone** è stata accodata
     *
     * ⚠️ Persone, non dispositivi: da quando `manda()` accoda (voce 62), quanti
     * telefoni riceveranno non si sa ancora qui — lo scrive `MandaLaNotifica`.
     */
    public function aQuestaEmail(
        Notifica $quale,
        string $email,
        string $titolo,
        string $corpo,
        array $dati = [],
    ): int {
        if ($email === '') {
            return 0;
        }

        $persone = User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('email', $email)
            ->get();

        foreach ($persone as $persona) {
            $this->manda($quale, $persona, $titolo, $corpo, $dati);
        }

        return $persone->count();
    }
}
