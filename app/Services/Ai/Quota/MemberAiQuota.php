<?php

declare(strict_types=1);

namespace App\Services\Ai\Quota;

use App\Enums\AiFeature;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Billing\PianoAttivo;
use Illuminate\Support\Carbon;

/**
 * Il tetto mensile di **chiamate** di ciascun iscritto — C20, riscritto in G2.
 *
 * 🚨 **Il limite si controlla PRIMA della chiamata, non dopo.** Controllarlo
 * dopo vorrebbe dire aver gia' pagato cio' che si sta rifiutando di concedere:
 * il tetto servirebbe a dire «hai sforato», non a impedire di sforare.
 *
 * 🚨 **Per iscritto e non per palestra, ed e' un cambio di rotta voluto.**
 * Il tetto per palestra era un pozzo comune: bastava per tre o quattro persone,
 * e la quarta restava senza AI per il consumo di qualcun altro. Chi consumava di
 * piu' non pagava di piu': pagavano gli altri, restando fuori. Con un tetto di
 * ciascuno, un utente pesante esaurisce **il proprio** e nessun altro se ne
 * accorge.
 *
 * ── 🆕 G2 — da token a chiamate (D6), con due contatori (D7) ───────────────
 *
 * I token sono l'unita' del fornitore, non del cliente: nessuno compra «due
 * milioni di token» e nessuno sa dire se gli bastano. Le chiamate le si conta da
 * soli.
 *
 * ⚠️ **Ma una chiamata non vale una chiamata**: `STIMA-COSTI-AI.md` misura
 * 0,0146 $ per una stima da foto contro 0,0013 $ per le calorie di un
 * allenamento — **undici volte**. Quindi i contatori sono **due**: quello
 * generale e il sotto-limite sulle chiamate con allegato.
 *
 * 🚨 **Il secondo e' un sotto-limite, non un budget a parte.** Una foto consuma
 * **entrambi** i contatori: chi ha 400 chiamate di cui 40 con foto, dopo 40 foto
 * ha 360 chiamate rimaste, non 400.
 */
class MemberAiQuota
{
    /**
     * Il tetto di questa persona, in chiamate al mese. `null` = illimitato.
     *
     * ── 🚨 Cinque livelli, dal piu' specifico al piu' generale — F4.3, D3 ──
     *
     * | # | Livello | Chi lo decide |
     * |---|---|---|
     * | 1 | `users.ai_monthly_call_cap` | l'eccezione per **una persona** |
     * | 2 | `tenants.ai_monthly_calls_per_member` | **la palestra**, se ce n'e' una |
     * | 3 | il trainer indipendente che l'ha invitata | solo se non c'e' una palestra, e la quota e' quella del `PLUS` |
     * | 4 | `plans.ai_monthly_calls_per_member` | il **piano** in corso |
     * | 5 | `ai.quota.default_monthly_calls_per_user` | il default di sistema |
     *
     * 🚨 In **tutti** i livelli, `0` vale «illimitato» e `null` vale «non
     * impostato, scendi al livello successivo». Sono due cose diverse: senza la
     * distinzione non si potrebbe sbloccare una persona sola lasciando il
     * default a tutte le altre — si potrebbe solo alzare il tetto a tutti.
     *
     * ⚠️ E proprio per questo **qui dentro non si decide se l'AI spetti**: non
     * esiste un valore che significhi «niente AI». Quella domanda ha un cancello
     * suo, `RequirePlanWithAi`, che gira **prima** (D2).
     *
     * ── 🚨 La palestra PRIMA del trainer, non viceversa ────────────────────
     *
     * E' la decisione D3. Se un iscritto di una palestra si fa seguire **anche**
     * da un trainer indipendente, non deve poter drenare il monte di quel
     * trainer, che se lo paga di tasca sua per i **suoi** utenti. Chi paga per
     * quella persona e' la palestra, e il tetto e' suo.
     *
     * 💡 Il livello 3 si guarda **solo** se il tenant non e' una palestra: e' la
     * stessa condizione detta al contrario, e tenerla esplicita evita che
     * qualcuno, un giorno, «semplifichi» togliendo il controllo su `ePersonale()`.
     *
     * @param  bool  $conFoto  se si chiede il sotto-limite delle chiamate con allegato
     */
    public function capFor(User $utente, bool $conFoto = false): ?int
    {
        // 1. L'eccezione per una persona.
        $suo = $conFoto ? $utente->ai_monthly_photo_call_cap : $utente->ai_monthly_call_cap;

        if ($suo !== null) {
            return $suo > 0 ? $suo : null;
        }

        $tenant = $utente->tenant;

        // 2. La palestra. ⚠️ Solo se e' una palestra vera: il tetto di un tenant
        // personale sarebbe il tetto che una persona si e' data da sola.
        if ($tenant !== null && ! $tenant->ePersonale()) {
            $dallaPalestra = $conFoto
                ? $tenant->ai_monthly_photo_calls_per_member
                : $tenant->ai_monthly_calls_per_member;

            if ($dallaPalestra !== null) {
                return $dallaPalestra > 0 ? $dallaPalestra : null;
            }
        }

        // 3. Il trainer indipendente che l'ha invitata, se non c'e' una palestra.
        $dalTrainer = $this->tettoDalTrainerIndipendente($utente, $conFoto);

        if ($dalTrainer !== null) {
            return $dalTrainer > 0 ? $dalTrainer : null;
        }

        // 4. Il piano in corso.
        $piano = app(PianoAttivo::class)->per($utente);
        $dalPiano = $conFoto ? $piano->chiamateConFotoAlMese() : $piano->chiamateAlMese();

        if ($dalPiano !== null) {
            return $dalPiano > 0 ? $dalPiano : null;
        }

        // 5. Il default di sistema.
        $default = (int) config($conFoto
            ? 'ai.quota.default_monthly_photo_calls_per_user'
            : 'ai.quota.default_monthly_calls_per_user');

        return $default > 0 ? $default : null;
    }

    /**
     * Il tetto che arriva dal trainer indipendente che segue questa persona.
     *
     * 🚨 **Solo per chi non ha una palestra.** Se ce l'ha, il livello 2 ha gia'
     * risposto e questo metodo non viene nemmeno interrogato — ma la condizione
     * resta scritta anche qui, perche' il giorno in cui qualcuno riordinasse i
     * livelli il vincolo non deve dipendere dall'ordine.
     *
     * ── 🆕 U.2 — non e' piu' il tetto DEL TRAINER, e' la quota del PLUS ────
     *
     * 📌 *«all'allievo arriva la stessa quota che gli arriverebbe se si
     * abbonasse»* — 150 chiamate e 15 con foto, uguali per tutti.
     *
     * ⛔ **Prima si leggeva `ai_monthly_call_cap` del trainer**, cioe' il tetto
     * **suo**: un trainer con l'AI illimitata avrebbe regalato l'illimitato a
     * cinquanta persone, e un trainer senza eccezioni non avrebbe dato niente.
     * Nessuno dei due e' quello che e' stato chiesto.
     *
     * 💡 Il «piu' alto vince» fra piu' trainer non serve piu' — il valore e' lo
     * stesso per tutti — ma la ragione per cui c'era resta scritta perche' e'
     * ancora vera: farsi seguire da un secondo trainer non deve **peggiorare**
     * il servizio.
     *
     * 🚨 **E la domanda «quanta» non basta**: l'allievo di un trainer sta su un
     * piano `free`, e `hasQuotaLeft()` si ferma prima, su `haLaAi()`. La
     * risposta «se» sta in `PianoAttivo::haLaAi()`, che chiede allo stesso
     * servizio. Questo metodo da solo era — ed e' stato per settimane — un
     * numero che nessuno leggeva.
     */
    private function tettoDalTrainerIndipendente(User $utente, bool $conFoto): ?int
    {
        return app(QuotaDelTrainer::class)->tetto($utente, $conFoto);
    }

    /**
     * Quante chiamate ha gia' fatto questa persona nel mese.
     *
     * 🚨 **Righe, non token.** Da G2 la quota conta le **chiamate**: una riga di
     * `ai_usage_logs` e' una chiamata, indipendentemente da quanto e' costata.
     */
    public function usedThisMonth(User $utente, bool $conFoto = false, ?Carbon $month = null): int
    {
        return AiUsageLog::callsForUser((int) $utente->getKey(), $conFoto, $month);
    }

    /** Quante chiamate restano. `null` = illimitato. */
    public function remaining(User $utente, bool $conFoto = false): ?int
    {
        $cap = $this->capFor($utente, $conFoto);

        if ($cap === null) {
            return null;
        }

        return max(0, $cap - $this->usedThisMonth($utente, $conFoto));
    }

    /**
     * C'e' ancora spazio per **questa** chiamata?
     *
     * 🚨 **Prende la funzione, e non e' un dettaglio di comodita'.** Il limite
     * dipende da *quale* chiamata si sta per fare: una firma che non lo chiede
     * non puo' applicare D7 — sarebbe un metodo che si legge come una regola e
     * non ne applica nessuna, che e' il difetto ricorrente gia' annotato due
     * volte in questo progetto.
     *
     * ⚠️ Una chiamata con allegato deve passare **entrambi** i controlli: il
     * sotto-limite e quello generale. Controllare solo il primo lascerebbe
     * sforare il totale a chi ha ancora foto disponibili.
     */
    public function hasQuotaLeft(User $utente, AiFeature $funzione): bool
    {
        /*
         * 🚨 **Chi ha l'AI SOLO dai gettoni non ha nessuna quota inclusa** —
         * 15/08/2026.
         *
         * ⚠️ Senza questa riga si apriva una falla che costava piu' del difetto
         * che si stava correggendo. Il piano `free` ha
         * `ai_monthly_calls_per_member = null`, cioe' «non decide questo
         * livello»: la catena scendeva fino al **default di sistema, 400
         * chiamate**. Risultato: chi comprava **un** gettone si portava a casa
         * 400 chiamate gratis prima di spenderlo.
         *
         * 🎯 La regola giusta e' semplice e va detta a parole: **la quota
         * inclusa esiste solo se il piano comprende l'AI**. Chi l'AI ce l'ha
         * perche' ha comprato dei gettoni, paga ogni chiamata con quelli — che
         * e' esattamente cio' per cui li ha comprati.
         *
         * 💡 `haLaAi()` e non `aiUtilizzabile()`: qui serve sapere se l'AI
         * spetti **per contratto**, non se sia usabile in qualunque modo.
         * Chiamare la seconda darebbe sempre `true` a chi ha gettoni, cioe'
         * rimetterebbe in piedi la falla.
         */
        if (! app(PianoAttivo::class)->haLaAi($utente)) {
            return false;
        }

        if ($funzione->isMultimodal()) {
            $foto = $this->remaining($utente, conFoto: true);

            if ($foto !== null && $foto <= 0) {
                return false;
            }
        }

        $generale = $this->remaining($utente);

        return $generale === null || $generale > 0;
    }

    /**
     * @throws AiQuotaExceededException
     */
    public function assertWithinQuota(User $utente, AiFeature $funzione): void
    {
        if ($this->hasQuotaLeft($utente, $funzione)) {
            return;
        }

        /*
         * 💡 Si dice **quale** dei due tetti si e' esaurito. «Hai finito le
         * chiamate» a chi ne ha ancora 300 ma ha finito le foto e' un messaggio
         * che sembra un guasto: la persona sa di non averle finite.
         */
        $eraLaFoto = $funzione->isMultimodal()
            && ($this->remaining($utente, conFoto: true) ?? 1) <= 0;

        throw new AiQuotaExceededException(
            resetsAt: Carbon::now()->addMonthNoOverflow()->startOfMonth(),
            capCalls: $this->capFor($utente, $eraLaFoto),
            soloFoto: $eraLaFoto,
        );
    }

    /**
     * La percentuale consumata, per la dashboard.
     *
     * `null` quando non c'e' tetto: mostrare «0%» a chi e' illimitato darebbe
     * l'impressione di avere un limite enorme invece di non averne.
     */
    public function usedPercent(User $utente, bool $conFoto = false): ?float
    {
        $cap = $this->capFor($utente, $conFoto);

        if ($cap === null || $cap === 0) {
            return null;
        }

        return round(min(100, $this->usedThisMonth($utente, $conFoto) / $cap * 100), 1);
    }
}
