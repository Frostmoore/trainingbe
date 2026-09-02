<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\AiCreditMovement;
use App\Models\User;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PortafoglioGettoni;

/**
 * Il cancello commerciale davanti a ogni chiamata all'AI — G2, D8 + D16.
 *
 * ── 🚨 Perche' e' un servizio e non due metodi privati del controller ──────
 *
 * Fino a N20 questa regola viveva dentro `AiController` come coppia di metodi
 * privati, ed era giusto finche' **tutte** le chiamate all'AI partivano da li'.
 * L'importazione dei piani alimentari (N20) rompe quel presupposto: il cancello
 * si apre in una richiesta HTTP, ma la chiamata al modello avviene **in coda**,
 * minuti dopo, in un job.
 *
 * ⚠️ Copiare le due righe nel job avrebbe voluto dire **due sedi della stessa
 * regola commerciale** — e due sedi divergono. Quella che diverge per prima e'
 * sempre la copia, cioe' quella meno provata. Lo stesso avvertimento sta scritto
 * su `AiController::planFood()` dal giorno in cui e' nato.
 *
 * ── 🚨 L'ordine, che e' tutto ─────────────────────────────────────────────
 *
 *     1. la quota inclusa del mese  (MemberAiQuota)
 *     2. se e' finita, i gettoni     (PortafoglioGettoni)
 *     3. se non bastano, 402
 *
 * ⚠️ **Un gettone speso mentre la quota e' ancora piena e' un gettone rubato**,
 * e nessuno se ne accorge: il servizio funziona, la chiamata riesce, il saldo
 * cala. Si vedrebbe solo dalla fattura di qualcun altro. Per questo la quota si
 * guarda **per prima**, sempre.
 *
 * ══ 🎟️ LA REGOLA DEL 31/08/2026: A RICHIESTA SI PAGA A GETTONI ═══════════
 *
 * 📌 Il committente: *«tutte le richieste all'ai non automatiche devono costare
 * GETTONI. Se finisci i gettoni non le puoi fare»*.
 *
 * 🎯 **E il perche' e' commerciale, non tecnico.** Sempre lui, il 30/08:
 * *«l'abbonato deve avere tutte le funzionalita' automatiche che funzionano
 * automaticamente consumando meno richieste ai possibile per me … ho idea di
 * fare in modo che tutte le richieste "a richiesta" debbano per forza usare
 * gettoni, per massimizzare i profitti»*.
 *
 * 💡 Da cui la divisione, che e' l'intero modello commerciale in due righe:
 *
 * | | Chi paga |
 * |---|---|
 * | quello che parte **da solo** | la quota inclusa dell'abbonamento |
 * | quello che **chiedi tu** | i gettoni, sempre |
 *
 * ⚠️ **E i conti tornano**: tre consigli al giorno fanno 90 chiamate al mese, le
 * analisi delle schede una ventina. `PlanSeeder::CHIAMATE` vale 150 — cioe' la
 * quota inclusa e' tarata **sull'automatico e basta**, ed e' il motivo per cui
 * 3b-AB (le fasce) e' venuta prima di questa.
 *
 * ══ 🚨 «A RICHIESTA» NON E' UNA PROPRIETA' DELLA FUNZIONE ════════════════
 *
 * ⛔ E qui sta la trappola. `DailyAdvice` e `PlanProgress` sono **tutte e due le
 * cose**: il consiglio parte da solo tre volte al giorno *e* si rigenera col
 * pulsante; l'analisi della scheda parte dopo l'allenamento *e* si rifa' a mano.
 *
 * 🚨 Quindi la decisione **non puo' stare sull'enum**: dipende da come si e'
 * arrivati qui, non da cosa si sta chiedendo. Da qui il parametro `$aRichiesta`,
 * che ogni chiamante passa dicendo la verita' su se stesso.
 *
 * 💡 `AiFeature::siPagaSoloConIGettoni()` resta per i due PDF, che sono
 * **sempre** a richiesta e lo sono per definizione (U.6): un import da PDF
 * automatico non esiste.
 *
 * ── ✨ L'eccezione che viene prima di tutte: «AI illimitata» ────────────
 *
 * 📌 *«gli utenti che hanno ai illimitata devono avere anche GETTONI
 * illimitati, non 0 gettoni»* — 02/09/2026.
 *
 * 🚨 Si guarda **per prima**, prima ancora dei PDF: chi ha quella concessione
 * la ha per provare l'app, e una funzione che non puo' provare e' una funzione
 * che non e' stata provata.
 *
 * ⚠️ E' il **tetto della persona**, non quello ereditato da palestra o piano:
 * vedi `MemberAiQuota::senzaTetto()`, dove c'e' scritto perche' la differenza
 * costa denaro.
 *
 * ── ⚠️ Cosa NON garantisce questo cancello ───────────────────────────────
 *
 * 🚨 **Un client modificato puo' dichiararsi automatico e non pagare.** Non c'e'
 * modo di impedirlo dal server: se l'analisi della scheda sia partita da sola lo
 * sa **il telefono**, perche' lo stato dell'analisi vive li' (D9).
 *
 * 💡 Ma non e' un buco nuovo: quel client otterrebbe esattamente cio' che oggi
 * ottiene chiunque — la quota inclusa del mese, e nulla di piu'. Il tetto vero
 * resta `MemberAiQuota`. E' scritto qui perche' non venga scoperto come se fosse
 * una dimenticanza.
 *
 * ── ⚠️ Il ripiego cortese salta ─────────────────────────────────────────
 *
 * In fondo a `apri()` c'e' il messaggio che manda alla quota chi non ha mai
 * comprato gettoni. Per una chiamata a richiesta sarebbe una **bugia**: direbbe
 * «hai finito la quota del mese» a chi ce l'ha intatta, e quella persona
 * aspetterebbe il mese nuovo per una cosa che il mese nuovo non sistema.
 */
class CancelloDeiGettoni
{
    public function __construct(
        private readonly MemberAiQuota $quota,
        private readonly PortafoglioGettoni $portafoglio,
    ) {}

    /**
     * Apre il cancello, o lancia.
     *
     * ── 🚨 Perche' RESTITUISCE la decisione invece di farla ricontrollare ──
     *
     * La prima versione non tornava niente, e un secondo metodo richiamava
     * `hasQuotaLeft()` **dopo** la chiamata per decidere se scalare un gettone.
     * Era sbagliato, e in un modo che si vede solo sul bordo:
     *
     *     tetto 400, gia' usate 399  →  prima della chiamata la quota BASTA
     *     la chiamata scrive la sua riga in ai_usage_logs  →  usate 400
     *     dopo la chiamata la quota NON basta piu'  →  scala un gettone
     *
     * ⚠️ **Quella chiamata era coperta dalla quota inclusa**, e si sarebbe fatta
     * pagare lo stesso. Una volta al mese per ogni cliente, in silenzio.
     *
     * 💡 La decisione si prende **una volta sola, prima**, e viaggia fino al
     * consumo. Nell'import dei piani (N20) viaggia perfino su una colonna di
     * database, perche' fra il cancello e il consumo c'e' una coda.
     *
     * @param  bool  $aRichiesta  se questa chiamata l'ha chiesta una persona
     *                            toccando qualcosa, invece di partire da sola.
     *                            🚨 Decide chi paga: vedi la nota in testa.
     * @return bool se questa chiamata andra' pagata con i gettoni
     *
     * @throws AiQuotaExceededException
     * @throws GettoniEsauritiException
     */
    public function apri(?User $utente, AiFeature $funzione, bool $aRichiesta = false): bool
    {
        if ($utente === null) {
            return false;
        }

        /*
         * ══ ✨ «AI ILLIMITATA»: NON SI PAGA NIENTE — 02/09/2026 ════════════
         *
         * 📌 Il committente: *«gli utenti che hanno ai illimitata devono avere
         * anche GETTONI illimitati, non 0 gettoni»*.
         *
         * 🚨 **Prima di tutto il resto, PDF compresi.** La regola U.6 — *«i pdf
         * si pagano SEMPRE a gettoni, abbonato o no»* — parla di **abbonati**,
         * non di questa concessione: chi ha l'illimitato lo ha per provare
         * l'app, e una funzione che non puo' provare e' una funzione che non e'
         * stata provata.
         *
         * ⛔ **Era rotta da 3b-AE, e in silenzio**: finche' tutto passava dalla
         * quota, «AI illimitata» bastava a se stessa. Da quando le richieste
         * fatte a mano si pagano solo a gettoni, quella concessione consegnava
         * un 402 al primo alimento scritto — mentre il pannello prometteva
         * *«Nessun tetto mensile, foto comprese»*.
         *
         * 💡 `false` vuol dire **«questa chiamata non si paga con i gettoni»**:
         * `consuma()` non scala niente, e il saldo resta dov'e'.
         */
        if ($this->quota->senzaTetto($utente, $funzione)) {
            return false;
        }

        /*
         * ⛔ **Chi chiede paga a gettoni, e la quota non si guarda nemmeno.**
         *
         * 📌 *«tutte le richieste all'ai non automatiche devono costare
         * GETTONI»* — 31/08/2026.
         *
         * ⚠️ I due PDF ci finiscono dentro comunque (`siPagaSoloConIGettoni()`),
         * e non e' un doppione: quelli lo sono **per definizione** — un import
         * da PDF automatico non esiste — mentre il consiglio e l'analisi della
         * scheda lo sono **solo a volte**.
         */
        $soloGettoni = $aRichiesta || $funzione->siPagaSoloConIGettoni();

        if (! $soloGettoni && $this->quota->hasQuotaLeft($utente, $funzione)) {
            return false;
        }

        /*
         * 🚨 **Si guarda soltanto**, non si scala. Il consumo avviene dopo che
         * la chiamata e' andata a buon fine (`consuma()`): scalare qui vorrebbe
         * dire far pagare anche le chiamate che il fornitore ha rifiutato —
         * cioe' far pagare i nostri guasti al cliente.
         */
        if ($this->portafoglio->bastanoPer($utente, $funzione)) {
            return true;
        }

        /*
         * ⚠️ **Chi non ha mai comprato gettoni riceve il messaggio di quota**,
         * non quello dei gettoni: dirgli «ricarica i gettoni» a chi non sa
         * nemmeno che esistano e' un errore che non spiega niente.
         */
        if (! $soloGettoni
            && $this->portafoglio->saldo($utente) === 0
            && ! AiCreditMovement::withoutGlobalScopes()
                ->where('tenant_id', $utente->tenant_id)
                ->exists()) {
            $this->quota->assertWithinQuota($utente, $funzione);
        }

        throw new GettoniEsauritiException(
            saldo: $this->portafoglio->saldo($utente),
            servivano: $funzione->costoInGettoni(),
        );
    }

    /**
     * Scala i gettoni **dopo** che la chiamata e' riuscita.
     *
     * 🚨 **`$conGettoni` arriva da `apri()`, non si ricalcola.** Vedi la nota
     * li' sopra: ricalcolarlo qui farebbe pagare la chiamata che ha esaurito la
     * quota, che invece era coperta.
     *
     * ⚠️ **Dopo e non prima**: scalare prima vorrebbe dire far pagare anche le
     * chiamate che il fornitore ha rifiutato — cioe' far pagare i nostri guasti
     * al cliente.
     */
    public function consuma(?User $utente, AiFeature $funzione, bool $conGettoni): void
    {
        if ($utente === null || ! $conGettoni) {
            return;
        }

        try {
            $this->portafoglio->consuma($utente, $funzione);
        } catch (GettoniEsauritiException) {
            /*
             * ⚠️ **Non si rilancia, e la scelta e' deliberata.** Arrivare qui
             * vuol dire che i gettoni sono finiti **fra** il controllo e la
             * risposta — cioe' una corsa fra due chiamate della stessa persona.
             * La chiamata al fornitore e' gia' stata pagata da noi: negare la
             * risposta ora vorrebbe dire buttarla e far arrabbiare il cliente
             * per un centesimo. Si passa, e il saldo resta dov'e'.
             */
        }
    }
}
