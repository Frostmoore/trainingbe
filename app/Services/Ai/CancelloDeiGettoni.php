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
     * @return bool se questa chiamata andra' pagata con i gettoni
     *
     * @throws AiQuotaExceededException
     * @throws GettoniEsauritiException
     */
    public function apri(?User $utente, AiFeature $funzione): bool
    {
        if ($utente === null) {
            return false;
        }

        if ($this->quota->hasQuotaLeft($utente, $funzione)) {
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
        if ($this->portafoglio->saldo($utente) === 0
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
