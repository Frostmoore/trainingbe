<?php

declare(strict_types=1);

/**
 * Il listino — rifatto il 18/08/2026.
 *
 * ── 🚨 Perche' sta in configurazione e non in `plans` ─────────────────────
 *
 * La tabella `plans` contiene ancora il **modello vecchio**, a scaglioni di
 * trainer («Palestra — 5 trainer», 349,99 €), ed e' quello su cui poggiano gli
 * abbonamenti gia' attivi (`plan_subscriptions`). Riscriverla adesso vorrebbe
 * dire cambiare cosa viene fatturato, che e' lavoro della **Parte H** e non del
 * sito.
 *
 * ⚠️ **QUINDI OGGI IL SITO E IL SISTEMA DI FATTURAZIONE DICONO DUE COSE
 * DIVERSE**, ed e' scritto qui perche' non lo si scopra da un cliente. Si
 * allineano in `H1.5` e `H2.2`. Fino ad allora **non si vende a nessuno** su
 * questi numeri senza saperlo.
 *
 * ── 🎯 Da dove vengono i prezzi ───────────────────────────────────────────
 *
 * **Tutto discende dal prezzo del singolo: 7,99 € al mese.** E' il numero che
 * chiunque puo' vedere e con cui chiunque puo' fare il confronto.
 *
 * Da li' due regole che non si possono violare senza che il listino diventi
 * incoerente:
 *
 * 1. 🚨 **Un posto in un pacchetto costa sempre MENO di 7,99.** Se costasse di
 *    piu', alla palestra converrebbe dire ai propri iscritti «abbonatevi da
 *    soli», e il pacchetto non lo comprerebbe nessuno.
 * 2. 🚨 **Piu' grande e' il pacchetto, meno costa a posto.** Se un pacchetto
 *    grande costasse di piu' a posto di uno piccolo, chi fa la divisione — e
 *    qualcuno la fa sempre — ne comprerebbe tanti piccoli. Il listino
 *    sembrerebbe una trappola invece di uno sconto.
 *
 * 💡 E il **piano a consumo** costa piu' del pacchetto piu' piccolo: e' il
 * prezzo della flessibilita', ed e' cio' che rende il pacchetto conveniente.
 * Senza quella differenza il pacchetto sarebbe li' per finta.
 *
 * ⚠️ **Tutto in centesimi.** `4.99` in virgola mobile e' `4.9899999...`: su
 * cento posti l'errore si vede, e su una fattura si contesta.
 *
 * 📌 Ci sono test che verificano entrambe le regole su **tutti** i pacchetti:
 * sono invarianti facili da rompere ritoccando un prezzo solo, che e' proprio
 * quello che si fa.
 */
return [

    /*
     |--------------------------------------------------------------------------
     | Chi si allena da solo
     |--------------------------------------------------------------------------
     */

    /** L'abbonamento del singolo, IVA inclusa. E' il perno di tutto il listino. */
    'singolo_cent' => 799,

    /**
     * I gettoni che l'abbonamento accredita ogni mese. Si azzerano al rinnovo.
     *
     * ── 🚨 Erano 500, portati a 300 il 16/08/2026 ─────────────────────────
     *
     * Decisione del committente: **«ci devono arrivare a mala pena a fine mese
     * facendo una o due foto»**. Dieci richieste al giorno per trenta giorni.
     *
     * ⚠️ E' una scelta **deliberatamente stretta**, non una stima: la misura
     * bottom-up in `memory/STIMA-COSTI-AI.md` dice che chi usa l'app davvero ne
     * consuma **320-490** al mese.
     *
     * 📌 Dal 18/08 questo numero **e' pubblicato sul sito**. Prima non lo era,
     * per non invitare al confronto con i pacchetti; poi il committente:
     * *«a sto punto diciamolo, tanto sta scritto nelle condizioni d'uso»*.
     */
    'gettoni_mensili' => 300,

    /*
     * I pacchetti di gettoni, per chi finisce quelli del mese o non si abbona.
     *
     * ── 🚨 Il prezzo per gettone deve SCENDERE al crescere del taglio ──────
     *
     *     100  →  2,50 €  =  2,50 centesimi a gettone
     *     500  → 10,00 €  =  2,00 centesimi a gettone
     *     2000 → 29,90 €  =  1,50 centesimi a gettone
     *
     * 📌 Vivono qui **provvisoriamente**: `H2.2` li sposta in `ai_credit_packs`,
     * dove potranno essere versionati (cambiare prezzo crea una riga nuova e
     * ritira la vecchia, invece di riscrivere il passato).
     */
    'pacchetti' => [
        ['gettoni' => 100,  'prezzo_cent' => 250,  'nota' => 'per provare'],
        ['gettoni' => 500,  'prezzo_cent' => 1000, 'nota' => 'per chi la usa spesso'],
        ['gettoni' => 2000, 'prezzo_cent' => 2990, 'nota' => 'il piu\' conveniente'],
    ],

    /*
     |--------------------------------------------------------------------------
     | Palestre e trainer: pacchetti di posti, piu' un piano a consumo
     |--------------------------------------------------------------------------
     |
     | 🚨 Un «posto» e' una persona a cui l'AI e' stata accesa. Tutti gli altri
     | iscritti usano l'app gratis e non si pagano.
     |
     | ⚠️ Il piano a consumo e' **uno solo** per ciascuno: piu' d'uno sarebbe un
     | listino da studiare invece che da leggere.
     */

    'palestre' => [
        'pacchetti' => [
            ['posti' => 10,  'prezzo_cent' => 4990,  'nota' => 'per cominciare'],
            ['posti' => 25,  'prezzo_cent' => 10990, 'nota' => 'il piu\' scelto'],
            ['posti' => 50,  'prezzo_cent' => 19990, 'nota' => 'per una palestra piena'],
            ['posti' => 100, 'prezzo_cent' => 34990, 'nota' => 'per piu\' sedi'],
        ],

        /** A consumo: si paga per ogni posto acceso, mese per mese. */
        'a_consumo_cent' => 599,

        /** Il minimo di posti sul piano a consumo. */
        'minimo' => 5,
    ],

    'trainer' => [
        'pacchetti' => [
            ['posti' => 5,  'prezzo_cent' => 2990,  'nota' => 'per cominciare'],
            ['posti' => 10, 'prezzo_cent' => 5490,  'nota' => 'il piu\' scelto'],
            ['posti' => 25, 'prezzo_cent' => 11990, 'nota' => 'per chi ne segue tanti'],
        ],

        'a_consumo_cent' => 699,

        'minimo' => 3,
    ],

    /**
     * Il prezzo suggerito per rivendere un posto ai propri iscritti.
     *
     * ⚠️ **Suggerito**, e va scritto ovunque compaia: se una palestra lo mette a
     * 20 € e l'iscritto scopre che da solo lo pagherebbe 7,99, la lamentela
     * torna indietro a noi.
     */
    'rivendita_suggerita_cent' => 1000,

    /** Il pacchetto su cui e' costruito l'esempio del sito. */
    'esempio_posti' => 25,

    /*
    |---------------------------------------------------------------------------
    | 📣 La pubblicita' nel catalogo — M5, 18/08/2026
    |---------------------------------------------------------------------------
    |
    | Si paga **a visualizzazione**, non a canone. Scelta del committente: una
    | cifra fissa uguale per chi raggiunge dieci persone e per chi ne raggiunge
    | mille «e' ridicola».
    |
    | ⚠️ **Il rovescio, da sapere prima**: a listino fisso l'incasso c'e' dal
    | primo giorno; a visualizzazione, finche' l'app ha poche installazioni,
    | l'incasso e' vicino allo zero. Il modello diventa buono quando c'e'
    | traffico, non prima.
    */
    'pubblicita' => [
        /*
         * 🚨 **Due centesimi a visualizzazione** — confermato dal committente il
         * 18/08. Sono 20 € ogni mille persone raggiunte.
         *
         * ⚠️ Questo valore e' il **listino**, non il prezzo di una campagna in
         * corso: all'attivazione viene fotografato in
         * `campagne.costo_visualizzazione_cent`, cosi' un aumento non cambia il
         * prezzo di chi ha gia' acceso. Cambiare questa riga vale solo per le
         * campagne accese **dopo**.
         */
        'costo_visualizzazione_cent' => 2,

        /*
         * Il budget mensile minimo: 10 €, cioe' cinquecento persone raggiunte.
         *
         * 💡 Non e' un modo per far spendere di piu': sotto questa cifra la
         * campagna si spegnerebbe in un giorno, e chi l'ha attivata concluderebbe
         * che la pubblicita' «non funziona» avendola vista funzionare per poche
         * ore.
         */
        'budget_minimo_cent' => 1000,

        /*
         * 🚨 **Per quanti mesi si conservano le righe di dettaglio.**
         *
         * Servono a rispondere a una contestazione — «perche' questo mese ho
         * speso tanto?» — e per quello tredici mesi bastano: coprono il confronto
         * con lo stesso mese dell'anno prima. ⚠️ Tenerle per sempre vorrebbe dire
         * conservare **chi ha visto cosa** all'infinito, che non serve a nessuno
         * e va contro la minimizzazione.
         */
        'mesi_di_dettaglio' => 13,
    ],
];
