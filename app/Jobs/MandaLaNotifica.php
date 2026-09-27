<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notifica;
use App\Models\User;
use App\Services\Notifiche\LeNotifiche;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 📲 **Una notifica, consegnata in coda** — voce 62, 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE: LA DOMANDA DEL COMMITTENTE ═════════════════════════
 *
 * 📌 Il committente, 27/09: *«notifiche e email passano per una queue tipo
 * redis? Perché potrebbe essere una buona idea, e se non lo fanno poi succede
 * sempre un casino con le notifiche»*.
 *
 * ⛔ **Le push non ci passavano tutte.** Le tre che c'erano da prima —
 * `Messaggio`, `Avviso`, `Promozione` — partivano da un job o da un listener
 * accodato, quindi erano a posto. 🚨 Le otto aggiunte il 27/09 partivano
 * **dentro la richiesta**: dentro un webhook di Stripe, dentro una `Action` di
 * Filament, dentro una rotta dell'app.
 *
 * ══ 🚨 COSA ANDAVA STORTO, IN CONCRETO ════════════════════════════════════
 *
 * `PushSender` fa una **chiamata HTTP a Google per ogni dispositivo**, in fila.
 * Dentro una richiesta questo vuol dire:
 *
 * | Dove | Cosa succedeva |
 * |---|---|
 * | 💳 webhook di Stripe (`IncassoFallito`) | Stripe aspetta la risposta e va in timeout a ~20 s: 🚨 dichiara il webhook **fallito** e lo **rimanda**, e il lavoro si rifà — mentre l'incasso era già registrato |
 * | ✉️ invito dall'app | chi invita guarda una rotella per tutto il tempo delle chiamate a Google |
 * | 💬 risposta dal pannello | la schermata resta ferma, e un guasto di rete di Google si presenta come un errore del **pannello** |
 *
 * 💡 In coda, invece: la richiesta risponde subito, e una notifica che non parte
 * **si ritenta** invece di sparire in un `catch`.
 *
 * ══ ⚠️ `afterCommit`, E NON E' UN DETTAGLIO ═══════════════════════════════
 *
 * 🚨 Alcune notifiche nascono **dentro una transazione** — la riattivazione di
 * uno spazio, per dirne una. ⛔ Accodando subito, il worker può prendere il job
 * **prima** che la transazione sia chiusa: annuncerebbe un fatto che ancora non
 * è a terra, e se la transazione va indietro annuncerebbe un fatto che **non è
 * mai avvenuto**. 🔑 Una push non si ritira: è già sul telefono.
 *
 * 💡 `LeNotifiche::manda()` accoda con `afterCommit()`, quindi questo job parte
 * solo a transazione riuscita — e fuori da una transazione parte subito.
 *
 * ══ ⛔ PERCHE' PORTA UN ID E NON IL MODELLO ═══════════════════════════════
 *
 * 🚨 `SerializesModels` ricaricherebbe l'utente nel worker, e `User` è
 * `SoftDeletes` + `BelongsToTenant`: un utente cancellato fra l'accodamento e la
 * consegna farebbe **fallire il job** con `ModelNotFoundException`, e la coda lo
 * ritenterebbe per niente.
 *
 * 💡 Con l'id lo si cerca qui e, se non c'è più, **si smette**: una notifica a
 * chi ha chiuso l'account non è un guasto, è una notifica che non va mandata.
 */
class MandaLaNotifica implements ShouldQueue
{
    use Queueable;

    /**
     * 🔁 **Tre tentativi.**
     *
     * ⚖️ Non uno — il caso tipico di fallimento è Google momentaneamente
     * irraggiungibile, e riprovare risolve. ⛔ E non infiniti: una notifica
     * consegnata mezz'ora dopo il fatto è rumore, e `IncassoFallito` mandata
     * domani è peggio di niente.
     */
    public int $tries = 3;

    /** @param array<string, string> $dati */
    public function __construct(
        public readonly Notifica $quale,
        public readonly int $utenteId,
        public readonly string $titolo,
        public readonly string $corpo,
        public readonly array $dati = [],
    ) {}

    public function handle(LeNotifiche $notifiche): void
    {
        /*
         * ⚠️ `withoutGlobalScopes()`: nel worker il contesto di palestra non c'è,
         * e `TenantScope` a contesto vuoto non filtra — 🚨 ma affidarsi a quello
         * vorrebbe dire che il giorno che qualcuno esegue un job dentro un
         * contesto, questo non troverebbe più la persona. 💡 Si dice cosa si vuole.
         *
         * ⛔ E `whereNull('deleted_at')` a mano, perché `withoutGlobalScopes()`
         * spegne anche `SoftDeletes`: senza, si manderebbe a chi ha chiuso
         * l'account.
         */
        $utente = User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($this->utenteId);

        if ($utente === null) {
            /*
             * 💡 Nessun errore e nessun ritentativo: fra l'accodamento e adesso
             * quella persona ha chiuso l'account. ⚖️ È un esito normale, non un
             * guasto — e `AccountEraser` le ha già togliato i token comunque.
             */
            return;
        }

        $quanti = $notifiche->consegnaAdesso(
            $this->quale,
            $utente,
            $this->titolo,
            $this->corpo,
            $this->dati,
        );

        /*
         * 📝 Si scrive **solo quando non è arrivata a nessuno**, e non a ogni
         * consegna: ⚠️ un registro che logga ogni notifica riuscita diventa
         * illeggibile, e la riga che conta — «non è arrivata» — ci si perde
         * dentro.
         *
         * 💡 E zero dispositivi **non è un guasto**: è una persona che non ha
         * l'app, o che ha negato il permesso. 🔑 Per questo è `debug` e non
         * `warning`: serve a rispondere a «perché non l'ha ricevuta?», non a
         * segnalare che qualcosa è rotto.
         */
        if ($quanti === 0) {
            Log::debug('Notifica senza destinatari raggiungibili.', [
                'notifica' => $this->quale->value,
                'utente' => $this->utenteId,
            ]);
        }
    }
}
