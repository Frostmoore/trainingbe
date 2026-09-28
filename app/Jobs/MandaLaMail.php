<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ✉️ **Una mail, mandata in coda** — voce 62.7, 28/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 28/09: *«E certo che le accodiamo, tutte»*, dopo aver
 * chiesto — il giorno prima — se notifiche ed email passassero da una coda.
 *
 * ⛔ **Non ci passavano**: tredici `Mail::to(...)->send()` in `app/`, **zero**
 * accodati. 🚨 E uno stava **dentro il webhook di Stripe**
 * (`PagamentoFallitoMail`), dove una connessione SMTP lenta significa un webhook
 * in timeout, dichiarato fallito e **rimandato** da Stripe.
 *
 * ══ 🔑 PERCHE' UN JOB NOSTRO E NON `Mail::queue()` ════════════════════════
 *
 * ⚖️ Laravel offre `Mail::to()->queue()`, ed è una riga. 💡 Ma cambia il modo in
 * cui i test guardano la posta: con un Mailable accodato si verifica con
 * `Mail::assertQueued()`, non con `assertSent()` — e in questo progetto ci sono
 * **trentasette** asserzioni scritte con `assertSent`, in dieci file.
 *
 * 🔑 Con un job nostro invece la mail parte **dentro** `handle()`, e nei test
 * `QUEUE_CONNECTION=sync` (vedi `phpunit.xml`) lo esegue in linea: le asserzioni
 * restano vere, e in produzione la mail va in coda. ⚖️ Nessuna riscrittura di
 * test per una cosa che non cambia il comportamento provato.
 *
 * 💡 Ed è la stessa forma di `MandaLaNotifica`, che fa lo stesso per le push.
 *
 * ══ 📝 E PER CHI DEVE SEGNARE CHE E' PARTITA ══════════════════════════════
 *
 * ⚠️ Quattro punti del codice **leggono l'esito** dell'invio per scriverlo in
 * tabella (`email_inviata_il` dei due inviti, `mandata` della risposta a una
 * richiesta) o per dirlo a schermo. 🚨 Accodando, quell'esito non esiste più nel
 * momento in cui si accoda: si saprà nel worker, dopo.
 *
 * 💡 Per questo il job sa, se glielo si dice, **quale riga segnare** quando
 * l'invio è riuscito: `$modello`, `$id`, `$colonna`. ⛔ Così la colonna dice la
 * verità — «è partita» — invece di «l'abbiamo accodata», che è un'altra cosa.
 */
class MandaLaMail implements ShouldQueue
{
    use Queueable;

    /**
     * 🔁 **Tre tentativi.**
     *
     * ⚖️ Il caso tipico di fallimento è il server di posta momentaneamente
     * irraggiungibile, e riprovare risolve. ⛔ E non infiniti: una mail di
     * «recupera la password» consegnata due ore dopo non serve più a nessuno.
     */
    public int $tries = 3;

    public function __construct(
        public readonly string $a,
        public readonly Mailable $mail,

        /*
         * 📝 Opzionali, e vanno **tutti e tre insieme**: la classe del modello,
         * il suo id, e **cosa scrivergli** se l'invio riesce.
         *
         * ⛔ Si passa la **classe e l'id**, non il modello: un modello
         * serializzato si ricarica da solo, ma se nel frattempo è stato
         * cancellato il job **fallisce** — e qui il fallimento sarebbe «la mail
         * non parte», per una riga che non c'entra.
         *
         * ══ 🚨 E IL VALORE SI PASSA, NON SI INDOVINA ══════════════════════
         *
         * ⛔ Qui c'era `$colonna`, e il job ci scriveva `now()`. 🚨 Andava bene
         * per `email_inviata_il`, che è un **timestamp**, e **non** per
         * `mandata`, che è un **booleano**: MySQL riceveva una data in un
         * `tinyint(1)` e ci metteva **0**. La mail partiva, e la colonna diceva
         * «non partita» — cioè il contrario di quello che questo giro serve a
         * garantire.
         *
         * 💡 Trovato il 28/09 da `e_quando_il_job_gira_la_colonna_si_riempie`,
         * ma **solo dopo** aver corretto quel test: la prima versione usava
         * `assertNotNull` su un booleano che parte da `false`, e passava sempre.
         *
         * 🔑 Adesso chi accoda dice **cosa** scrivere: `['mandata' => true]` o
         * `['email_inviata_il' => now()]`. ⚖️ Un job che indovina il valore
         * giusto per una colonna che non conosce è un job che prima o poi lo
         * indovina male.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?string $modello = null,
        public readonly ?int $id = null,
        public readonly ?array $segna = null,
    ) {}

    public function handle(): void
    {
        Mail::to($this->a)->send($this->mail);

        $this->segnaCheEPartita();
    }

    /**
     * 📝 Scrive la colonna, se c'è da scriverla.
     *
     * ⚠️ **Dopo `send()` e non prima**: è il punto di tutto. 🔑 La colonna deve
     * dire *«è partita»*, e se la si scrivesse prima direbbe *«ci abbiamo
     * provato»* — che è esattamente la bugia che questo giro è venuto a togliere.
     *
     * 🚨 E **non lancia** se la riga non c'è più: l'invio è **già riuscito**, e
     * far fallire il job a quel punto lo farebbe ritentare, mandando la mail una
     * seconda volta. ⛔ Una mail doppia è peggio di una colonna non scritta.
     */
    private function segnaCheEPartita(): void
    {
        if ($this->modello === null || $this->id === null || $this->segna === null) {
            return;
        }

        try {
            /** @var class-string<Model> $classe */
            $classe = $this->modello;

            $riga = $classe::query()->withoutGlobalScopes()->find($this->id);

            $riga?->forceFill($this->segna)->save();
        } catch (\Throwable $e) {
            Log::warning('Mail partita, ma la riga non è stata segnata.', [
                'modello' => $this->modello,
                'id' => $this->id,
                'segna' => array_keys($this->segna),
                'errore' => $e->getMessage(),
            ]);
        }
    }
}
