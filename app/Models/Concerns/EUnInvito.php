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
}
