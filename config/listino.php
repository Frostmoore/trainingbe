<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Il listino — prezzi VERI dal 20/09/2026
|--------------------------------------------------------------------------
|
| 📌 Il committente, 20/09/2026: *«penso che sia anche il momento di definire i
| prezzi veri delle cose, almeno ci togliamo dal cazzo anche quel problema»*, e
| poi l'elenco completo. ⛔ **Non sono più segnaposto**: fino al 19/09 questo
| file diceva a chiare lettere «non si vende a nessuno su questi numeri». Adesso
| ci si vende.
|
| ── 💰 I prezzi sono FINALI ───────────────────────────────────────────────
|
| 📌 *«I prezzi sono TUTTI IVA INCLUSA»*: è la cifra che la persona paga, punto.
| ⚠️ Il titolare è in **regime forfettario** e non applica IVA (per questo
| `Cassa` spegne i *managed payments* di Stripe): non c'è un'imposta da
| scorporare, e sul sito non si scrive «IVA esclusa» da nessuna parte.
|
| 🚨 **Qui non si mettono i prezzi a mano nel codice.** Da questo file
| discendono: `PlanSeeder` (che crea i piani vendibili), la pagina dei prezzi
| del sito, la modale degli acquisti nell'app e i prodotti creati su Stripe.
| Cambiare un numero qui e ridare `db:seed --class=PlanSeeder` cambia tutto.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Chi si allena da solo
    |--------------------------------------------------------------------------
    |
    | 📌 *«Abbonamento singolo: 8€»* — 20/09/2026 (era 7,99 di prova).
    |
    | 💡 Comprende l'assistente **automatico**: il consiglio del giorno, che parte
    | da solo. Le funzioni a richiesta — riconoscere un pasto da una foto,
    | trascrivere una scheda — si pagano a gettoni, perché costano a chiamata.
    */

    'singolo_cent' => 800,

    /*
    | Quante chiamate automatiche al mese comprende l'abbonamento.
    |
    | ⚠️ È il tetto **tecnico**, non una promessa commerciale: 150 chiamate sono
    | cinque al giorno, e il consiglio del giorno ne usa una.
    */
    'chiamate_mensili' => 150,

    /*
    |--------------------------------------------------------------------------
    | I gettoni — le funzioni a richiesta
    |--------------------------------------------------------------------------
    |
    | 📌 I quattro tagli e i prezzi sono del committente (20/09/2026); i **nomi**
    | li ho scelti io, su sua richiesta.
    |
    | 💡 Sono nomi di allenamento e non «Bronzo/Argento/Oro»: chi compra qui sta
    | comprando lavoro, non uno status. E la scala dice da sola dove conviene —
    | 3 centesimi a gettone il primo, 1,5 l'ultimo.
    |
    | | Taglio | Prezzo | A gettone |
    | |---|---|---|
    | | 100 | 3,00 € | 3,0 cent |
    | | 500 | 12,50 € | 2,5 cent |
    | | 2.000 | 40,00 € | 2,0 cent |
    | | 5.000 | 75,00 € | 1,5 cent |
    */

    'pacchetti' => [
        [
            'nome' => 'Prova',
            'gettoni' => 100,
            'prezzo_cent' => 300,
            'nota' => 'per capire se ti serve',
        ],
        [
            'nome' => 'Serie',
            'gettoni' => 500,
            'prezzo_cent' => 1250,
            'nota' => 'per chi la usa ogni settimana',
        ],
        [
            'nome' => 'Blocco',
            'gettoni' => 2000,
            'prezzo_cent' => 4000,
            'nota' => 'per chi la usa tutti i giorni',
        ],
        /*
         * ⚠️ **Accentata, non apostrofata.** Questa nota si legge nella modale
         * degli acquisti: «il piu' conveniente» in mezzo a «l'app», «più» e
         * «perché» scritti bene sembra un refuso, e lo è.
         */
        [
            'nome' => 'Stagione',
            'gettoni' => 5000,
            'prezzo_cent' => 7500,
            'nota' => 'il più conveniente',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trainer — 20/09/2026
    |--------------------------------------------------------------------------
    |
    | 📌 *«Basic 30€/mese con 5 allievi · Advanced 70€/mese con 15 · Pro 100€/mese
    | con 30 · Personalizzato (min. 30 allievi): 2,80€/mese per allievo +
    | 10€/mese»*.
    |
    | 🚨 **Un «allievo» è un abbonamento regalato a una persona.** Non è «un posto
    | AI»: chi lo riceve è abbonato come se pagasse gli 8 € da solo, con dentro
    | l'assistente automatico. ⛔ Le funzioni a richiesta restano a gettoni, per
    | tutti.
    |
    | 💡 **Dove si incrociano i piani**: 30 allievi costano 100 € col piano Pro e
    | 94 € col personalizzato (30 × 2,80 + 10). Il personalizzato conviene appena
    | si superano i 30 — ed è per questo che il minimo è 30, non meno: sotto,
    | sarebbe solo un modo più caro di comprare il Basic.
    */

    'trainer' => [
        'piani' => [
            [
                'codice' => 'trainer_5',
                'nome' => 'Trainer Basic',
                'allievi' => 5,
                'prezzo_cent' => 3000,
                'nota' => 'per cominciare',
            ],
            [
                'codice' => 'trainer_15',
                'nome' => 'Trainer Advanced',
                'allievi' => 15,
                'prezzo_cent' => 7000,
                'nota' => 'il più scelto',
            ],
            [
                'codice' => 'trainer_30',
                'nome' => 'Trainer Pro',
                'allievi' => 30,
                'prezzo_cent' => 10000,
                'nota' => 'per chi ne segue tanti',
            ],
        ],

        /*
         * Il personalizzato: si paga quello che si usa, più un canone.
         *
         * 🚨 **Il canone non è un balzello**: il servizio costa anche quando un
         * trainer in un mese non segue nessuno — i dati restano, le chat
         * restano, il server gira. Senza canone, un account fermo costerebbe a
         * noi e non a lui.
         */
        'a_consumo' => [
            'codice' => 'trainer_consumo',
            'nome' => 'Trainer su misura',
            'prezzo_iscritto_cent' => 280,
            'canone_cent' => 1000,
            'minimo_iscritti' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Palestre — 20/09/2026
    |--------------------------------------------------------------------------
    |
    | 📌 *«Basic 60€/mese con 10 allievi e 5 trainer · Advanced 130€/mese con 30 e
    | 10 · Pro 200€/mese con 60 e 20 · Personalizzato (min. 60 allievi):
    | 2,80€/mese per allievo + 15€/mese con trainer illimitati»*.
    |
    | 🚨 **DUE tetti, e valgono tutti e due** (decisione del 13/09/2026): gli slot
    | trainer sono volutamente larghi rispetto agli allievi — *«una palestra deve
    | poter inserire più o meno tutti i suoi trainer anche se vuole dare
    | l'abbonamento a pochissimi allievi»* — ma restano un massimo: *«se una
    | palestra ha 50 trainer non mi sta bene che paghi soltanto per 10-20
    | iscritti»*.
    |
    | 💡 **Il personalizzato toglie il tetto dei trainer**, e ha senso: chi arriva
    | a 60 allievi paganti ha già dimostrato quanto vale, e contargli i trainer
    | diventa un fastidio che non protegge niente.
    */

    'palestre' => [
        'piani' => [
            [
                'codice' => 'gym_10',
                'nome' => 'Palestra Basic',
                'iscritti' => 10,
                'trainer' => 5,
                'prezzo_cent' => 6000,
                'nota' => 'per cominciare',
            ],
            [
                'codice' => 'gym_30',
                'nome' => 'Palestra Advanced',
                'iscritti' => 30,
                'trainer' => 10,
                'prezzo_cent' => 13000,
                'nota' => 'il più scelto',
            ],
            [
                'codice' => 'gym_60',
                'nome' => 'Palestra Pro',
                'iscritti' => 60,
                'trainer' => 20,
                'prezzo_cent' => 20000,
                'nota' => 'per una sala grande',
            ],
        ],

        /*
         * 🚨 `trainer` a `null` = **illimitati**, ed è la differenza che questo
         * piano vende. Chi legge `max_trainers` deve trovarci `null`, non un
         * numero grande: un numero grande è comunque un muro, e prima o poi ci
         * sbatte qualcuno.
         */
        'a_consumo' => [
            'codice' => 'gym_consumo',
            'nome' => 'Palestra su misura',
            'prezzo_iscritto_cent' => 280,
            'canone_cent' => 1500,
            'minimo_iscritti' => 60,
            'trainer' => null,
        ],
    ],

    /*
    | Il prezzo suggerito per rivendere un posto ai propri iscritti.
    |
    | ⚠️ **Suggerito**, e va scritto ovunque compaia: se una palestra lo mette a
    | 20 € e l'iscritto scopre che da solo lo pagherebbe 8, la lamentela torna
    | indietro a noi.
    |
    | 🆕 **8,00 € dal 20/09/2026**, cioè **esattamente quanto costa il piano
    | individuale**. 📌 Il committente, vedendo l'esempio a 10: *«il piano
    | individuale costa 8… metti 8, anche se è solo un esempio»*.
    |
    | 💡 Ed è la cifra giusta per una ragione che vale più dell'esempio: a 8 €
    | l'iscritto paga **quello che pagherebbe da solo**, quindi non c'è niente da
    | spiegare e niente da scoprire — il giorno che apre l'app e legge il prezzo
    | del Plus, trova lo stesso numero. ⚠️ Sopra quella cifra la palestra sta
    | rivendendo a più del prezzo di listino, e quello lo scopre l'iscritto, non
    | noi.
    |
    | 📌 Il margine resta: su Advanced (130 € per 30 iscritti, cioè 4,33 a testa)
    | la palestra tiene **3,67 € a persona** — 240 € incassati contro 130 pagati.
    */
    'rivendita_suggerita_cent' => 800,

    /** Il pacchetto su cui è costruito l'esempio del sito. */
    'esempio_posti' => 30,

    /*
    |--------------------------------------------------------------------------
    | La pubblicità nel catalogo
    |--------------------------------------------------------------------------
    */
    'pubblicita' => [
        /*
         * 🚨 **Due centesimi a visualizzazione** — confermato dal committente il
         * 18/08. Sono 20 € ogni mille persone raggiunte.
         *
         * ⚠️ Questo valore è il **listino**, non il prezzo di una campagna in
         * corso: all'attivazione viene fotografato in
         * `campagne.costo_visualizzazione_cent`, così un aumento non cambia il
         * prezzo di chi ha già acceso. Cambiare questa riga vale solo per le
         * campagne accese **dopo**.
         */
        'costo_visualizzazione_cent' => 2,

        /*
         * Il budget mensile minimo: 10 €, cioè cinquecento persone raggiunte.
         *
         * 💡 Non è un modo per far spendere di più: sotto questa cifra la
         * campagna si spegnerebbe in un giorno, e chi l'ha attivata concluderebbe
         * che la pubblicità «non funziona» avendola vista funzionare per poche
         * ore.
         */
        'budget_minimo_cent' => 1000,

        /*
         * 🚨 **Per quanti mesi si conservano le righe di dettaglio.**
         *
         * Servono a rispondere a una contestazione — «perché questo mese ho
         * speso tanto?» — e per quello tredici mesi bastano: coprono il confronto
         * con lo stesso mese dell'anno prima. ⚠️ Tenerle per sempre vorrebbe dire
         * conservare **chi ha visto cosa** all'infinito, che non serve a nessuno
         * e va contro la minimizzazione.
         */
        'mesi_di_dettaglio' => 13,
    ],
];
