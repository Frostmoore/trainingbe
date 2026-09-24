<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Quello che vale per **ogni** invito — 3b-V.1.1.
 *
 * ══ 🚨 PERCHE' ESISTE, E PERCHE' PRIMA DEL GEMELLO ════════════════════════
 *
 * Da 3b-V ci sono **due** inviti: quello di un trainer a una persona
 * (`TrainerInvite`, F6.2) e quello di una palestra (`InvitoInPalestra`).
 *
 * ⛔ La strada comoda sarebbe copiare il primo nel secondo e «unificare quando
 * ci sara' tempo». ⚠️ Due regole di validita' copiate divergono al primo
 * ritocco, e quella che diverge non da' nessun errore: e' **un invito che
 * continua a funzionare quando non dovrebbe**. Non si vede finche' qualcuno non
 * entra dove non poteva.
 *
 * 💡 Per questo la regola sta qui, in un posto solo, e i due modelli la usano.
 *
 * ══ ⚠️ LE QUATTRO CONDIZIONI ══════════════════════════════════════════════
 *
 * | Condizione | Cosa vuol dire |
 * |---|---|
 * | `used_at` | qualcuno l'ha gia' usato — **monouso** |
 * | `revoked_at` | chi l'ha mandato ci ha ripensato |
 * | `rifiutato_il` | 🆕 **chi l'ha ricevuto ha detto di no** |
 * | `expires_at` | e' passata una settimana |
 *
 * 🚨 **La terza e' nuova, e vale anche per gli inviti dei trainer.** 📌 Il
 * committente vuole *«due tasti, uno per accettare e uno per rifiutare»*: il
 * rifiuto e' una risposta, e una risposta va registrata.
 *
 * 💡 **Il rifiuto brucia l'invito**, e non e' una durezza: quell'invito era per
 * quella persona, e quella persona ha detto no. Lasciarlo valido vorrebbe dire
 * un invito che nessuno usera' mai e che chi l'ha mandato crede ancora in piedi.
 * Meglio che lo veda rifiutato e ne mandi un altro, se serve.
 */
trait EUnInvito
{
    /** Quanto vive un invito. Una settimana: il tempo di leggere un messaggio. */
    public const GIORNI_DI_VITA = 7;

    /**
     * 🚨 **Le condizioni, in un posto solo.**
     *
     * Ripeterle nei controller vorrebbe dire che il giorno in cui se ne aggiunge
     * una quinta bisogna ricordarsi di tutti i punti — e uno dimenticato e' un
     * invito che continua a funzionare quando non dovrebbe.
     */
    public function eValido(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->rifiutato_il === null
            && $this->expires_at->isFuture();
    }

    public function scopeValidi(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->whereNull('rifiutato_il')
            ->where('expires_at', '>', now());
    }

    /**
     * Un segreto lungo, non un codice da dettare al telefono.
     *
     * ⚠️ Al contrario di `Tenant::generateJoinCode()`, qui **non** si tolgono i
     * caratteri ambigui: questo non si legge ad alta voce, viaggia in un link.
     * Toglierli ridurrebbe l'alfabeto — cioe' la difesa — per un problema che
     * questo codice non ha.
     *
     * 🚨 **`withoutGlobalScopes()` nel controllo di unicita'.** Il modello e'
     * scopato per tenant: senza, si cercherebbe una collisione **solo dentro la
     * propria palestra**, e due palestre diverse potrebbero ritrovarsi con lo
     * stesso token. La colonna e' `unique`, quindi non passerebbe — ma il
     * fallimento arriverebbe come un errore di database davanti a chi invita,
     * invece che come un secondo giro del `do…while`.
     */
    public static function generaToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }

    /**
     * 🆕 🔗 **Il link da mandare** — blocco B2, 24/09/2026.
     *
     * ══ 🚨 SI COMPONE SUL SERVER, E NON E' UN DETTAGLIO ════════════════════
     *
     * ⛔ Lasciarlo comporre all'app vorrebbe dire che il giorno in cui cambia il
     * dominio bisogna **pubblicare una versione nuova sugli store** per farlo
     * cambiare — e nel frattempo ogni invito mandato punterebbe da nessuna
     * parte. 📌 È già successo il 22/09 col dominio definitivo.
     *
     * ══ ⚠️ PERCHE' STA QUI E NON NEI DUE CONTROLLER ════════════════════════
     *
     * 🔑 In B1 era un metodo privato di `TrainerController`. In B2 sarebbe
     * diventato **due** metodi privati identici in due controller, e due
     * composizioni dello stesso link divergono: quando divergono, mandano gente
     * su un indirizzo che non esiste — senza nessun errore da nessuna parte,
     * perché il server risponde 404 a qualcun altro.
     *
     * ⚠️ **Il percorso lo dichiara ogni modello**, perché è l'unica cosa che
     * cambia davvero: `/invito/…` per un trainer, `/invito-palestra/…` per una
     * palestra. Sono due pagine diverse, con due testi diversi.
     */
    public function link(): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/'.trim(static::PERCORSO_DEL_LINK, '/')
            .'/'.$this->token;
    }

    /**
     * 🆕 📨 **Come l'app vede un invito in sospeso** — blocco B2, 24/09/2026.
     *
     * 🔑 **Una forma sola per tutti e due gli inviti.** In B1 questa riga stava
     * dentro `TrainerController::invites()`; in B2 la stessa pagina dell'app
     * deve poter mostrare gli inviti di una palestra, e una seconda forma —
     * anche solo con un nome di campo diverso — vorrebbe dire due rami nel
     * lettore dell'app, cioè il posto in cui si dimentica sempre uno dei due.
     *
     * ⚠️ **`email_inviata_il` è una data e non un sì/no**: «mandata il 24/09
     * alle 14:03» è una risposta, «mandata: sì» è metà risposta — e quando un
     * invito viene rimandato a un altro indirizzo, la data dice **qual è
     * l'ultimo invio**.
     *
     * 🚨 Il `token` esce perché è **l'invito stesso** e chi lo legge qui è già
     * chi l'ha creato: è la stessa cosa del link, scritta in due modi.
     *
     * @return array<string, mixed>
     */
    public function perLApp(): array
    {
        return [
            'id' => $this->getKey(),
            'token' => $this->token,
            'url' => $this->link(),
            'email' => $this->email,
            'email_inviata_il' => $this->email_inviata_il?->toIso8601String(),
            'creato_il' => $this->created_at?->toIso8601String(),
            'scade_il' => $this->expires_at->toIso8601String(),
        ];
    }
}
