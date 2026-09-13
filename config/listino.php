<?php

declare(strict_types=1);

/**
 * Il listino — rifatto il 18/08/2026.
 *
 * ── ✅ QUESTO FILE E' LA FONTE UNICA DEI NUMERI — 13/09/2026 ─────────────
 *
 * ⛔ **Fino al 12/09 i listini erano due**: questo lo leggeva solo il sito, e
 * la tabella `plans` — con numeri diversi e un'unità di misura diversa —
 * governava tutti i cancelli. Una palestra leggeva *«25 posti a 109,90»*,
 * comprava, e si ritrovava su un piano da 349,99 che contava i **trainer**.
 *
 * 🚨 Non era un difetto che desse errore: erano **due promesse diverse**.
 *
 * ✅ Da oggi **`PlanSeeder` legge da qui**. I numeri stanno in un posto solo,
 * e cambiarli è cambiare una riga di questo file e riseminare.
 *
 * ⚠️ **Quello che NON sta qui e' la capienza gia' venduta**: le righe di
 * `plan_subscriptions` puntano a un `plan_id`, e un piano ritirato resta in
 * tabella con `is_public = false`. ⛔ Un piano non si cancella mai: si ritira.
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
     * Le **richieste incluse** nell'abbonamento, ogni mese.
     *
     * ══ ⛔ NON SONO GETTONI, E IL NOME VECCHIO DICEVA IL CONTRARIO ═════════
     *
     * 📌 Il committente, il 31/08/2026: *«Le chiamate dell'abbonamento non sono
     * gettoni, sono proprio chiamate che si possono usare. Un abbonato ha
     * comunque 0 gettoni se non li compra»*.
     *
     * 🚨 **Questa chiave si chiamava `gettoni_mensili`, e il commento diceva
     * «i gettoni che l'abbonamento accredita».** Da li' e' nato un accredito di
     * 150 gettoni all'accensione dell'abbonamento, scritto e poi tolto lo stesso
     * giorno: ⛔ un nome sbagliato non fa sbagliare *se* qualcuno sbaglia, fa
     * sbagliare **chi lo legge per primo**.
     *
     * 💡 Il sito e Stripe le hanno sempre chiamate **richieste** — *«150
     * richieste al mese, rinnovate ogni mese»* — quindi la parola giusta era
     * gia' quella usata con i clienti. Era il codice a chiamarle come non erano.
     *
     * ══ 🎯 I DUE CONTATORI, E SONO DIVERSI ════════════════════════════════
     *
     * | | Cos'e' | Da dove viene |
     * |---|---|---|
     * | **richieste incluse** | il tetto mensile dell'abbonamento | si rinnova col canone, non si compra |
     * | **gettoni** | credito comprato | si comprano, o si vincono in premio |
     *
     * ⛔ Un abbonato ha **zero gettoni** finche' non li compra, ed e' voluto: da
     * 3b-AE le richieste incluse coprono l'automatico, i gettoni tutto cio' che
     * si chiede a mano.
     *
     * ── 🚨 500 → 300 (16/08) → 150 (26/08/2026) ───────────────────────────
     *
     * 📌 *«non va bene 300 chiamate per gli abbonati, facciamo 150, stesso
     * prezzo»*.
     *
     * ⚠️ **E questo numero non era quello che comandava.** Il tetto vero di un
     * abbonato `plus` e' `plans.ai_monthly_calls_per_member`, cioe'
     * `PlanSeeder::CHIAMATE`, che valeva **450**: questo qui era solo quello
     * *raccontato*. 🚨 Tre numeri per la stessa cosa — 300 qui, 450 nel piano,
     * 400 scritti a mano nella schermata dell'app — e nessuno che li tenesse
     * allineati.
     *
     * ✅ Dal 26/08 sono **150 tutti e tre**, e la schermata dell'app il numero
     * lo **chiede all'API** invece di scriverlo.
     *
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
    'chiamate_mensili' => 150,

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
     | Palestre e trainer: piani a DUE tetti — rifatto il 13/09/2026
     |--------------------------------------------------------------------------
     |
     | 🚨 **Uno «slot» è un abbonamento regalato a una persona.** Non è «un posto
     | AI»: chi riceve lo slot è abbonato, con tutto quello che l'abbonamento
     | comprende — e l'AI **automatica** è dentro l'abbonamento.
     |
     | ⛔ Le funzioni **on-demand** non sono comprese in nessun piano: si pagano
     | a gettoni, che si comprano a parte.
     |
     | ── 📌 La decisione del committente, 13/09/2026 ──────────────────────────
     |
     | *«Ogni piano per le palestre ha un tot di slot per trainer e iscritti.
     | Quegli iscritti riceveranno un abbonamento in omaggio, e i trainer
     | potranno agire come trainer ricevendo l'abbonamento trainer
     | gratuitamente.»*
     |
     | 🚨 **DUE tetti, e valgono tutti e due.** Gli slot trainer sono
     | volutamente sproporzionati rispetto agli slot iscritto — *«una palestra
     | deve poter inserire più o meno tutti i suoi trainer anche se vuole dare
     | l'abbonamento a pochissimi allievi»* — ma restano un **massimo**: *«se una
     | palestra ha 50 trainer non mi sta bene che paghi soltanto per 10-20
     | iscritti»*.
     |
     | ⚠️ **Questo supera la decisione Z.1 del 31/08**, che diceva *«l'unità è il
     | posto (l'iscritto) — non tutte e due»*.
     |
     | ── 🚨 I NUMERI QUI SOTTO SONO SEGNAPOSTO ───────────────────────────────
     |
     | 📌 *«Per il momento mettici dei valori placeholder e via, devo fare prima
     | un'analisi di costi e ritorni. Non è fondamentale per il codice, è solo un
     | numero che possiamo decidere di cambiare in qualunque momento.»*
     |
     | ⛔ **Non si vende a nessuno su questi numeri.** Rispettano le due regole
     | del listino e il pavimento del costo AI, quindi il codice gira e i test
     | passano — ma il prezzo vero lo decide l'analisi di costi e ritorni.
     |
     | 💡 **Cambiarli è una riga qui**: `PlanSeeder` li legge da questo file, e
     | il sito pure. Un solo posto, come dev'essere.
     */

    'palestre' => [
        'piani' => [
            [
                'codice' => 'gym_20',
                'nome' => 'Palestra — piccola',
                'iscritti' => 20,
                'trainer' => 5,
                'prezzo_cent' => 9990,
                'nota' => 'per cominciare',
            ],
            [
                'codice' => 'gym_50',
                'nome' => 'Palestra — media',
                'iscritti' => 50,
                'trainer' => 10,
                'prezzo_cent' => 19990,
                'nota' => 'il piu\' scelto',
            ],
            [
                'codice' => 'gym_100',
                'nome' => 'Palestra — grande',
                'iscritti' => 100,
                'trainer' => 30,
                'prezzo_cent' => 34990,
                'nota' => 'per piu\' sedi',
            ],
        ],

        /*
         * 🆕 **Il piano a consumo** — 13/09/2026.
         *
         * 📌 *«Se una palestra sceglie il piano a consumo pagherà un tot per
         * ogni iscritto a cui regala l'abbonamento, per un minimo di 100.»*
         *
         * ⚠️ **Trainer illimitati**: oltre i cento iscritti il numero di
         * allenatori smette di essere la leva commerciale.
         *
         * 🚨 Il prezzo a iscritto è **più alto** del migliore pacchetto, e deve
         * restarlo: a consumo si paga la flessibilità.
         */
        'a_consumo' => [
            'codice' => 'gym_consumo',
            'nome' => 'Palestra — a consumo',
            'prezzo_iscritto_cent' => 599,
            'minimo_iscritti' => 100,
        ],
    ],

    'trainer' => [
        /*
         * ⛔ **Niente fascia gratuita** — decisione del 13/09/2026.
         *
         * 📌 *«Ogni free trainer avrà il suo piano trainer, con un tot di
         * allievi minimo (diciamo 5).»*
         *
         * ⚠️ `trainer_free` non si cancella: si **ritira** (`is_public = false`),
         * come `trainer_pro` e `gym`. Chi ce l'ha se lo tiene; nessuno lo può
         * più scegliere. ⛔ Cancellarlo romperebbe le righe di
         * `plan_subscriptions` che ci puntano.
         */
        'piani' => [
            [
                'codice' => 'trainer_5',
                'nome' => 'Trainer — 5 allievi',
                'allievi' => 5,
                'prezzo_cent' => 1990,
                'nota' => 'per cominciare',
            ],
            [
                'codice' => 'trainer_15',
                'nome' => 'Trainer — 15 allievi',
                'allievi' => 15,
                'prezzo_cent' => 4990,
                'nota' => 'il piu\' scelto',
            ],
            [
                'codice' => 'trainer_30',
                'nome' => 'Trainer — 30 allievi',
                'allievi' => 30,
                'prezzo_cent' => 6990,
                'nota' => 'per chi ne segue tanti',
            ],
        ],
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
