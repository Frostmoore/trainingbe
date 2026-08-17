<?php

declare(strict_types=1);

/**
 * Il listino a posti — 16/08/2026.
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
 * DIVERSE**, ed e' scritto qui perche' non lo si scopra da un cliente:
 *
 * | | |
 * |---|---|
 * | Il sito annuncia | 4,99 € a posto, scaglioni, minimo 5 |
 * | `plans` contiene | «Palestra — 5 trainer» a 349,99 € al mese |
 *
 * 🎯 Si allineano in `H1.5` (il seeder del listino) e `H2.2` (i pacchetti di
 * gettoni, che oggi vivono qui e domani in `ai_credit_packs`). Fino ad allora
 * **non si vende a nessuno** su questi numeri senza saperlo.
 *
 * 💡 Perche' comunque **non** e' scritto dentro la vista: un prezzo in un
 * template e' un prezzo che un giorno dira' una cosa diversa da quella che il
 * sistema fattura, e la si scopre da un cliente arrabbiato invece che da un
 * test. Qui almeno c'e' un punto solo da cambiare, ed e' provato.
 *
 * Fonte dei numeri: `memory/business_plan.md`, versione «meta' e meta'».
 */
return [

    /*
     * Gli scaglioni sul prezzo di un posto AI, in **centesimi**.
     *
     * 🚨 Centesimi e non euro: `4.99` in virgola mobile e' `4.9899999...`, e su
     * una moltiplicazione per trecento posti l'errore si vede.
     *
     * ⚠️ `fino` e' **inclusivo** e `null` vuol dire «da qui in su».
     */
    'scaglioni' => [
        ['fino' => 25,   'prezzo_cent' => 499],
        ['fino' => 100,  'prezzo_cent' => 399],
        ['fino' => null, 'prezzo_cent' => 299],
    ],

    /*
     * Quanti posti bisogna accendere come minimo.
     *
     * 💡 Non e' un canone travestito: e' il modo di dire «sotto questa soglia
     * non conviene a nessuno dei due» senza far pagare chi non usa niente.
     */
    'minimo_palestra' => 5,
    'minimo_trainer' => 3,

    /** L'abbonamento di chi si iscrive da solo, IVA inclusa. */
    'singolo_cent' => 799,

    /*
     * I gettoni che un posto riceve ogni mese. Si azzerano al rinnovo.
     *
     * ── 🚨 Erano 500, portati a 300 il 16/08/2026 ─────────────────────────
     *
     * Decisione del committente: **«ci devono arrivare a mala pena a fine mese
     * facendo una o due foto»**. Dieci richieste al giorno per trenta giorni.
     *
     * ⚠️ E' una scelta **deliberatamente stretta**, non una stima: la misura
     * bottom-up in `memory/STIMA-COSTI-AI.md` dice che chi usa l'app davvero
     * ne consuma **320-490** al mese. Trecento vuol dire che chi la usa tutti
     * i giorni finisce i gettoni prima della fine del mese e ne compra — ed e'
     * il punto.
     *
     * 🚨 **Vale sia per l'abbonamento singolo sia per ogni posto di palestra**,
     * perche' e' un valore solo. Il giorno in cui i due dovessero divergere,
     * qui servono due chiavi e non una: separarle **dopo** aver fatturato
     * significa cambiare cosa hanno comprato le persone gia' abbonate.
     */
    'gettoni_mensili' => 300,

    /**
     * Il prezzo suggerito alla palestra per rivendere un posto.
     *
     * ⚠️ **Suggerito**, e va scritto ovunque compaia: se una palestra lo mette a
     * 20 € e l'iscritto scopre che da solo lo pagherebbe 7,99, la lamentela
     * torna indietro a noi.
     */
    'rivendita_suggerita_cent' => 1000,

    /*
     * I pacchetti di gettoni.
     *
     * ── 🚨 Il prezzo per gettone deve SCENDERE al crescere del taglio ──
     *
     *     100  →  2,50 €  =  2,50 centesimi a gettone
     *     500  → 10,00 €  =  2,00 centesimi a gettone
     *     2000 → 29,90 €  =  1,50 centesimi a gettone
     *
     * ⚠️ Se un taglio grande costasse **di piu'** a gettone di uno piccolo,
     * chi fa la divisione — e qualcuno la fa sempre — comprerebbe venti
     * pacchetti da cento. Il listino sembrerebbe una trappola invece di uno
     * sconto, ed e' la cosa che si racconta. C'e' un test che lo verifica.
     *
     * 📌 Rivisti il 17/08/2026: erano 1,99 / 7,99 / 24,99, e il taglio da 500
     * costava **quanto un mese di abbonamento**, che ne da' 300. Accostati sulla
     * stessa pagina, erano un invito a non abbonarsi.
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

    /** L'esempio mostrato sul sito: una palestra con questi posti accesi. */
    'esempio_posti' => 60,
];
