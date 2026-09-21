<?php

declare(strict_types=1);

/*
 * 🧾 Le fatture — fase F8, 23/09/2026.
 *
 * ══ 📌 LE DECISIONI DEL COMMITTENTE (D2, R5) ═════════════════════════════
 *
 * *«ogni acquisto delle palestre - incluso l'abbonamento ogni mese - deve
 * generare una vera fattura xml … e pdf»* · *«Basta che mi generi l'xml e il
 * pdf. Il pdf lo deve poter scaricare anche il cliente, l'xml solo io … Certo il
 * bollo va messo»*.
 *
 * ⛔ **Niente trasmissione allo SDI** da qui: l'XML lo scarica il super admin e
 * lo porta lui dove serve. Questo file dice **chi emette** le fatture.
 *
 * 💡 I valori predefiniti sono i dati fiscali del titolare, che **non sono
 * segreti** (stanno su ogni fattura). Le variabili d'ambiente esistono per non
 * dover toccare il codice il giorno che cambiano.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Il cedente / prestatore — chi emette
    |--------------------------------------------------------------------------
    |
    | ⚠️ `regime` RF19 = regime forfettario (L. 190/2014): niente IVA, e sopra
    | 77,47 € il bollo da 2 € (virtuale). Cambiarlo senza cambiare `natura` e
    | `riferimento_normativo` produrrebbe fatture che si contraddicono.
    */
    'cedente' => [
        'denominazione' => env('FATTURE_DENOMINAZIONE', 'SeeMyPage di Ronconi Riccardo'),
        'partita_iva' => env('FATTURE_PARTITA_IVA', '02288080563'),
        'codice_fiscale' => env('FATTURE_CODICE_FISCALE', 'RNCRCR88T16H501M'),
        'indirizzo' => env('FATTURE_INDIRIZZO', 'Via degli Orti'),
        'numero_civico' => env('FATTURE_NUMERO_CIVICO', '426'),
        'cap' => env('FATTURE_CAP', '01036'),
        'comune' => env('FATTURE_COMUNE', 'Nepi'),
        'provincia' => env('FATTURE_PROVINCIA', 'VT'),
        'nazione' => 'IT',
        'regime' => env('FATTURE_REGIME', 'RF19'),
        'pec' => env('FATTURE_PEC', 'legale@pec.seemypage.it'),
        'email' => env('FATTURE_EMAIL', 'info@smp-digital.it'),
    ],

    /*
    |--------------------------------------------------------------------------
    | La numerazione
    |--------------------------------------------------------------------------
    |
    | 🚨 **Un sezionale, non la numerazione principale.** Il titolare emette
    | fatture anche per altre attività: se queste partissero da 1 come le sue,
    | due fatture diverse avrebbero lo stesso numero nello stesso anno. Col
    | sezionale il numero è `12/TC`, e le due serie non si toccano.
    |
    | `primo_numero` serve solo se, a metà anno, si parte da un numero diverso
    | da 1 (per esempio passando qui una serie già iniziata altrove).
    */
    'sezionale' => env('FATTURE_SEZIONALE', 'TC'),
    'primo_numero' => (int) env('FATTURE_PRIMO_NUMERO', 1),

    /*
    |--------------------------------------------------------------------------
    | Il regime: niente IVA, e il perché scritto sulla fattura
    |--------------------------------------------------------------------------
    |
    | `natura` N2.2 = «non soggette – altri casi», il codice che la
    | FatturaPA 1.2.2+ chiede per le operazioni in regime forfettario.
    */
    'natura' => 'N2.2',
    'riferimento_normativo' => 'Operazione in franchigia da IVA ai sensi art. 1 commi 54-89 L. 190/2014',

    /*
    | La dicitura per esteso, sul PDF. ⚠️ Due frasi, entrambe dovute: la
    | franchigia IVA e l'esclusione dalla ritenuta d'acconto (comma 67).
    */
    'dicitura' => 'Operazione effettuata ai sensi dell\'art. 1, commi da 54 a 89, della Legge n. 190/2014 '
        .'(regime forfettario): operazione senza applicazione dell\'IVA e non soggetta a ritenuta '
        .'d\'acconto ai sensi dell\'art. 1, comma 67, della Legge n. 190/2014.',

    /*
    |--------------------------------------------------------------------------
    | Il bollo
    |--------------------------------------------------------------------------
    |
    | Sopra 77,47 € una fattura senza IVA vuole la marca da bollo da 2 €, e
    | sulla fattura elettronica è **virtuale** (`DatiBollo/BolloVirtuale = SI`).
    |
    | 💡 **Il bollo lo paghiamo noi**, non il cliente: la fattura nasce da un
    | pagamento già fatto su Stripe, e non si può aggiungere 2 € a posteriori.
    | Per questo non entra nel totale del documento.
    */
    'bollo' => [
        'soglia_cent' => 7747,
        'importo_cent' => 200,
    ],

    /*
    | Il testo in fondo al PDF. ⚠️ «Copia di cortesia» perché il documento
    | fiscale è l'XML: il PDF è la sua versione leggibile.
    */
    'nota_pdf' => env(
        'FATTURE_NOTA_PDF',
        'Copia di cortesia. Il documento fiscale è la fattura elettronica in formato XML.'
    ),
];
