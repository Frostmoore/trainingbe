<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Ai\Quota\QuotaDelTrainer;
use App\Support\Tenancy\TenantContext;

/**
 * Qual è il piano in corso di una persona — F4 della Parte B.
 *
 * 🚨 **È il punto unico da cui passa la domanda «questa persona ha diritto
 * all'AI?»**. Due implementazioni della stessa regola divergono sempre: è la
 * trappola già pagata con `TenantAiQuota`, e il motivo per cui questa classe
 * esiste invece di due `if` in due controller.
 *
 * ── ⚠️ Perché il piano si legge dal TENANT e non dall'utente ───────────────
 *
 * Perché ogni utente ne ha uno: gli iscritti a una palestra hanno quello della
 * palestra — ed è la palestra a pagare — e chi si è iscritto da solo ha il suo
 * tenant personale (F1). Un abbonamento per utente moltiplicherebbe le righe
 * per gli iscritti di una palestra, che invece sono già coperti.
 *
 * ── 💡 Il piano di riserva, e perché non è `null` ─────────────────────────
 *
 * Chi non ha nessun abbonamento **non resta senza piano**: ricade su
 * `Plan::FREE`. Restituire `null` avrebbe voluto dire che ogni chiamante deve
 * ricordarsi di trattare quel caso, e chi se lo dimentica scriverebbe
 * `$piano->ai_enabled` su `null` — cioè un errore fatale nel percorso dell'AI,
 * oppure, peggio, un `?->ai_enabled` che vale `null` e passa per «falso» solo
 * per fortuna.
 *
 * 🚨 E se nemmeno il piano `free` esistesse a database — installazione nuova,
 * seeder non girato — si restituisce un piano **costruito al volo e non
 * salvato**, con `ai_enabled = false`. ⚠️ La direzione dell'errore è quella
 * giusta: un'installazione mal configurata deve **negare** l'AI, non regalarla.
 */
class PianoAttivo
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PortafoglioGettoni $portafoglio,
    ) {}

    /**
     * Il piano in corso per questa persona. Mai `null`.
     */
    public function per(User $utente): Plan
    {
        $tenantId = $utente->tenant_id;

        if ($tenantId === null) {
            /*
             * ⚠️ Il super admin non ha tenant, quindi non ha abbonamento.
             * Ricade sul gratuito, e va benissimo: il pannello `/god` non usa
             * l'AI, e se un giorno la usasse dovrebbe essere una decisione
             * esplicita e non un effetto collaterale di questa riga.
             */
            return $this->piadinoDiRiserva();
        }

        /*
         * 🚨 `runWithoutTenant` e non il contesto corrente.
         *
         * `PlanSubscription` usa `BelongsToTenant`, quindi la sua query è
         * filtrata dal tenant **del contesto**. Questo metodo però viene
         * chiamato anche da posti dove il contesto è vuoto o è un altro — un
         * comando artisan, un job in coda, il pannello `/god` — e lì la ricerca
         * non troverebbe niente e la persona risulterebbe senza piano.
         *
         * ⚠️ Il filtro non si perde: si rimette **a mano** con `where('tenant_id')`.
         * Non è un bypass dello scoping, è lo stesso filtro applicato in modo
         * esplicito invece che ambientale.
         */
        $abbonamento = $this->context->runWithoutTenant(
            fn (): ?PlanSubscription => PlanSubscription::query()
                ->where('tenant_id', $tenantId)
                ->attivi()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first(),
        );

        return $abbonamento?->plan ?? $this->piadinoDiRiserva();
    }

    /**
     * 🎯 **L'AI si puo' usare?** — la domanda del cancello, 15/08/2026.
     *
     * ── 🚨 Diversa da `haLaAi()`, e la differenza vale soldi ───────────────
     *
     * `haLaAi()` risponde «il piano la comprende». Questa risponde «questa
     * persona puo' fare una chiamata», e sono due cose diverse dal momento in
     * cui **i gettoni si comprano**: chi ha pagato deve poter usare cio' che ha
     * comprato, anche se il suo piano l'AI non ce l'ha.
     *
     * ⚠️ **Era il difetto**: `RequirePlanWithAi` chiedeva `haLaAi()`, quindi
     * rispondeva `403` **prima** che il portafoglio venisse interrogato. Chi
     * comprava cento gettoni su un piano `free` non otteneva niente — e non
     * c'era nessun modo di accorgersene dal codice del portafoglio, che
     * funzionava benissimo e non veniva mai raggiunto.
     *
     * ── L'ordine, che non e' arbitrario ────────────────────────────────────
     *
     * | # | Condizione | Esito |
     * |---|---|---|
     * | 1 | `ai_enabled_override === false` | 🚨 **no**, e vince su tutto |
     * | 2 | il piano (o l'override `true`) la comprende | si |
     * | 3 | il portafoglio ha almeno un gettone | si |
     *
     * 🚨 **Lo spegnimento esplicito vince anche sui gettoni**, ed e' voluto: chi
     * ha spento l'AI a una persona dal pannello non deve vedersela riaccendere
     * perche' la palestra ha ricaricato. Un interruttore che si riaccende da
     * solo non e' un interruttore.
     *
     * 💡 Qui basta **un** gettone: se non bastano per *questa* chiamata lo dice
     * il controller, con `402` e il numero esatto che serve. Il cancello decide
     * se l'AI esista per questa persona, non se sia sufficiente il credito.
     */
    public function aiUtilizzabile(User $utente): bool
    {
        if ($utente->ai_enabled_override === false) {
            return false;
        }

        if ($this->haLaAi($utente)) {
            return true;
        }

        return $this->portafoglio->saldo($utente) > 0;
    }

    /**
     * L'AI spetta a questa persona?
     *
     * ── 🚨 L'eccezione per una persona sola viene PRIMA del piano ──────────
     *
     * `users.ai_enabled_override` (15/08/2026) e' l'unico modo di accendere o
     * spegnere l'AI a **una** persona senza toccare il piano di nessun altro.
     *
     * | Valore | Cosa succede |
     * |---|---|
     * | `null` | decide il piano — il comportamento di sempre |
     * | `true` | accesa, qualunque cosa dica il piano |
     * | `false` | **spenta**, anche se il piano ce l'ha |
     *
     * ⚠️ **Perche' serviva.** Dal 14/08 il pannello sapeva dare una quota
     * illimitata, e non bastava: la quota dice *quante* chiamate, questo metodo
     * dice *se*. Chi si registra da solo prende un tenant personale sul piano
     * `free`, che l'AI non ce l'ha — il tetto era un rubinetto senza acqua.
     *
     * 🚨 **Il `false` non e' simmetria decorativa**: e' il solo modo di provare
     * cosa vede chi non ha l'AI senza smontare il piano di una palestra intera.
     * L'app deve funzionare **interamente** senza AI, ed e' una meta' di
     * prodotto che va guardata ogni tanto.
     *
     * 💡 `=== true` e `=== false` e non un `??`: `null` qui e' un **terzo**
     * valore con un significato suo, non «falso non impostato». Scriverlo con
     * `??` funzionerebbe per caso e si romperebbe alla prima modifica.
     */
    public function haLaAi(User $utente): bool
    {
        if ($utente->ai_enabled_override === true) {
            return true;
        }

        if ($utente->ai_enabled_override === false) {
            return false;
        }

        if ($this->per($utente)->ai_enabled) {
            return true;
        }

        /*
         * ── 🆕 U.2: gliela paga il trainer ─────────────────────────────────
         *
         * 📌 *«all'allievo arriva la stessa quota che gli arriverebbe se si
         * abbonasse»*.
         *
         * 🚨 **Senza questa riga il livello 3 di `MemberAiQuota` non serviva a
         * niente**, ed e' stato cosi' per settimane senza che nessun test se ne
         * accorgesse: l'allievo di un trainer indipendente sta in un tenant
         * personale col piano `free`, quindi `ai_enabled` e' falso e
         * `hasQuotaLeft()` si fermava qui. Il tetto del trainer veniva
         * calcolato bene e non veniva mai raggiunto.
         *
         * ⚠️ **Dopo il piano e non prima**, perche' e' la strada piu' cara: e'
         * una query, e chi ha l'AI dal proprio piano non deve pagarla.
         *
         * ⛔ **E dopo l'override `false`**, che continua a vincere su tutto: chi
         * ha spento l'AI a una persona dal pannello non deve vedersela
         * riaccendere perche' quella persona si e' fatta seguire da un trainer.
         */
        return app(QuotaDelTrainer::class)->copre($utente);
    }

    /**
     * 🎯 **Questa persona e' abbonata?** — 3b-C.8, 25/08/2026.
     *
     * 📌 Il committente: *«aggiusta anche il server in modo che gli utenti (free
     * users o iscritti in una palestra o con un trainer) abbiano il flag
     * abbonato e il flag tier»*.
     *
     * ── 🚨 «Abbonato» NON e' «ha l'AI illimitata» ─────────────────────────
     *
     * ⛔ L'app le aveva confuse, e il committente l'ha corretto: *«ovviamente AI
     * illimitata e abbonato sono due cose diverse, non va bene che siano
     * trattati come una cosa singola»*.
     *
     * ⚠️ Oggi si somigliano perche' l'abbonamento concede la quota illimitata,
     * ma sono due domande: una riguarda **il contratto**, l'altra **cosa puoi
     * fare adesso** — e chi compra dei gettoni su un piano gratuito ha l'AI
     * senza essere abbonato.
     *
     * ── ⚠️ L'abbonamento e' del TENANT, e va saputo ───────────────────────
     *
     * 💡 In questo impianto **paga il tenant, non la persona**: la palestra
     * compra i posti (`max_members`, `ai_monthly_calls_per_member`) e i suoi
     * iscritti sono coperti da quello. Un iscritto a una palestra abbonata
     * **e' abbonato**, ed e' voluto: e' il modello di vendita.
     *
     * ⚠️ Chi non sta in nessuna palestra ha un **tenant personale** (F3), e li'
     * l'abbonamento e' davvero suo. 🚨 Quindi la stessa riga risponde bene a
     * tutti e tre i casi del committente — *«free users o iscritti in una
     * palestra o con un trainer»* — senza doverli distinguere.
     *
     * ⛔ **Non si guarda `Tenant::isActive()`**: quello dice che la palestra
     * puo' entrare, non che ha comprato qualcosa. Un trial e' attivo e non e'
     * un abbonamento.
     *
     * 💡 La risposta e' una sola: **c'e' un abbonamento attivo e non e' quello
     * gratuito**. Il ripiego di [piadinoDiRiserva] non conta, ed e' il punto:
     * quando non si trova niente si ricade sul `free`, e ricadere non e' essere
     * abbonati.
     */
    public function eAbbonato(User $utente): bool
    {
        return $this->per($utente)->code !== Plan::FREE;
    }

    /**
     * Il **livello** del piano, per l'app.
     *
     * 💡 E' il `code` del piano — `free`, `plus`, `gym`, `trainer_pro`… — e non
     * un'etichetta nuova: inventarne una vorrebbe dire una seconda tabella da
     * tenere allineata al listino, e la seconda diverge sempre.
     *
     * ⚠️ **Mai `null`**: chi non ha niente e' `free`, che e' un livello e non
     * un'assenza. Un `null` costringerebbe ogni client a un ramo in piu' per
     * dire la stessa cosa.
     */
    public function livello(User $utente): string
    {
        return $this->per($utente)->code;
    }

    /**
     * Il piano gratuito, o un piano finto che nega tutto.
     *
     * 🚨 Il nome è volutamente buffo perché questo metodo va letto: qui si
     * decide cosa succede a un'installazione **mal configurata**, ed è l'unico
     * punto in cui la risposta può essere inventata. La direzione è sempre la
     * stessa — **negare**, mai concedere.
     */
    private function piadinoDiRiserva(): Plan
    {
        $free = $this->context->runWithoutTenant(
            fn (): ?Plan => Plan::query()->where('code', Plan::FREE)->first(),
        );

        return $free ?? new Plan([
            'code' => Plan::FREE,
            'name' => 'Gratuito',
            'ai_enabled' => false,
        ]);
    }
}
