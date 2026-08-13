<?php

declare(strict_types=1);

/**
 * Configurazione del layer AI — ADR-05.
 *
 * 🚨 **Nessun identificativo di modello e' scritto nel codice.** Vive tutto qui,
 * alimentato da `.env`, perche' la scelta del modello e' una decisione di
 * prodotto che cambiera' — quando esce un modello migliore, o quando il test
 * §9.4 del piano dira' che Haiku basta anche sulle foto — mentre il codice che
 * lo usa non deve cambiare per questo. Puntare il sistema su un modello diverso
 * deve essere una riga di `.env`, non un deploy.
 *
 * La motivazione di ogni scelta sta in §9 di `plan_trainingbe.md`, che resta la
 * fonte di verita': qui ci sono solo i valori.
 */
return [

    'default' => env('AI_DRIVER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
        ],
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            // Base URL configurabile: serve a puntare il driver a un endpoint
            // compatibile (un modello gia' in uso altrove, un proxy) senza
            // toccare il codice.
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Modelli, per driver e per funzione
    |---------------------------------------------------------------------------
    |
    | ⚠️ `pdf_import` non deve MAI puntare a un modello con finestra da 200K
    | token: il limite di 600 pagine per richiesta scende a 100 su quelli, e una
    | scheda impaginata larga li supera piu' facilmente di quanto sembri.
    */
    'models' => [
        'anthropic' => [
            'food_text' => env('AI_MODEL_FOOD_TEXT', 'claude-haiku-4-5'),
            'food_photo' => env('AI_MODEL_FOOD_PHOTO', 'claude-sonnet-5'),
            'workout_kcal' => env('AI_MODEL_WORKOUT_KCAL', 'claude-haiku-4-5'),
            'daily_advice' => env('AI_MODEL_ADVICE', 'claude-haiku-4-5'),
            'pdf_import' => env('AI_MODEL_PDF_IMPORT', 'claude-sonnet-5'),
        ],
        'openai' => [
            'food_text' => env('AI_MODEL_OPENAI_FOOD_TEXT', 'gpt-4.1-mini'),
            'food_photo' => env('AI_MODEL_OPENAI_FOOD_PHOTO', 'gpt-4.1'),
            'workout_kcal' => env('AI_MODEL_OPENAI_WORKOUT_KCAL', 'gpt-4.1-mini'),
            'daily_advice' => env('AI_MODEL_OPENAI_ADVICE', 'gpt-4.1-mini'),
            'pdf_import' => env('AI_MODEL_OPENAI_PDF_IMPORT', 'gpt-4.1'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Prezzi — millesimi di centesimo di dollaro per token
    |---------------------------------------------------------------------------
    |
    | 🚨 L'unita' e' scomoda apposta: **e' un intero**. I costi si sommano su
    | milioni di righe, e in virgola mobile le somme di importi piccoli accumulano
    | errore fino a rendere il totale del mese diverso a seconda dell'ordine in
    | cui si sommano. Un intero non ha questo problema.
    |
    | Conversione: $3 per milione di token = 300.000 millesimi di centesimo per
    | milione = 0,3 per token. Qui si tiene il valore **per milione**, che e' come
    | vengono pubblicati i listini, e la divisione la fa `AiUsageRecorder`.
    |
    | `cache_read` e' il costo di lettura dalla cache del prompt: circa un decimo
    | dell'input. E' il motivo per cui il prompt caching e' il risparmio singolo
    | piu' grande della piattaforma.
    |
    | Verificati il 2026-08-08. Vanno riletti quando cambia un listino: sono
    | numeri, non verita'.
    */
    'pricing' => [
        'claude-haiku-4-5' => ['in' => 100_000, 'out' => 500_000, 'cache_read' => 10_000],
        'claude-sonnet-5' => ['in' => 300_000, 'out' => 1_500_000, 'cache_read' => 30_000],
        'claude-opus-5' => ['in' => 500_000, 'out' => 2_500_000, 'cache_read' => 50_000],
        'gpt-4.1' => ['in' => 200_000, 'out' => 800_000, 'cache_read' => 50_000],
        'gpt-4.1-mini' => ['in' => 40_000, 'out' => 160_000, 'cache_read' => 10_000],
    ],

    /*
    |---------------------------------------------------------------------------
    | Quote
    |---------------------------------------------------------------------------
    |
    | 🚨 **Il tetto e' per ISCRITTO, non per palestra** (C20).
    |
    | Lo era per palestra fino a `v4.10.0`, ed era un pozzo comune: con
    | 2.000.000 di token a palestra e un consumo medio di **551.000 a testa**
    | (conti in `STIMA-COSTI-AI.md`) bastava per tre o quattro persone, e la
    | quarta trovava le funzioni AI spente per il consumo di qualcun altro.
    |
    | Il valore si risolve in tre passi, dal piu' specifico al piu' generale:
    |   1. `users.ai_monthly_token_cap`         — l'eccezione per una persona
    |   2. `tenants.ai_monthly_tokens_per_member` — la scelta della palestra
    |   3. questo default
    |
    | In tutti e tre **`0` vale «illimitato»** e `null` vale «non impostato,
    | scendi al livello successivo»: sono due cose diverse, e senza la
    | distinzione non si potrebbe sbloccare una persona sola.
    |
    | ⚠️ **1.200.000 non e' un numero tondo a caso**: copre l'utente pesante
    | del documento dei costi (5 pasti a testo, 3 foto, 10 aperture al giorno
    | ≈ 1,07 M token/mese) con un margine. Chi lo supera sta usando l'app in un
    | modo che vale la pena guardare, non assecondare in silenzio.
    */
    'quota' => [
        /*
         * ⚠️ **Non lo legge piu' nessuno da G2.** La quota si conta in chiamate
         * (D6). La chiave resta finche' `G2.5` non toglie anche le colonne dei
         * token: cancellarla adesso vorrebbe dire che un `.env` di staging o di
         * produzione, ancora popolato, smette di corrispondere a qualcosa senza
         * che nessuno se ne accorga.
         */
        'default_monthly_tokens_per_user' => (int) env('AI_DEFAULT_MONTHLY_TOKENS_PER_USER', 1_200_000),

        /*
         * Il livello 5 della catena — G2, D6/D7.
         *
         * 💡 **400 di cui 40 con allegato**: sono i numeri dei piani a pagamento
         * (`PlanSeeder::CHIAMATE`), ricavati dai consumi misurati in
         * `STIMA-COSTI-AI.md` — l'utente medio fa ≈ 320 chiamate al mese di cui
         * ≈ 30 con foto.
         *
         * 🚨 Questo default si applica solo a chi arriva in fondo alla catena
         * **avendo gia' superato `RequirePlanWithAi`**: chi non ha diritto
         * all'AI e' stato fermato prima e non arriva mai qui.
         */
        'default_monthly_calls_per_user' => (int) env('AI_DEFAULT_MONTHLY_CALLS_PER_USER', 400),
        'default_monthly_photo_calls_per_user' => (int) env('AI_DEFAULT_MONTHLY_PHOTO_CALLS_PER_USER', 40),
    ],

    /*
    |---------------------------------------------------------------------------
    | Import PDF
    |---------------------------------------------------------------------------
    */
    'pdf' => [
        'escalation_confidence' => (float) env('AI_PDF_ESCALATION_CONFIDENCE', 0.7),
        'escalation_model' => env('AI_MODEL_PDF_ESCALATION', 'claude-opus-5'),
        'max_bytes' => 32 * 1024 * 1024,
    ],

    /*
    |---------------------------------------------------------------------------
    | Immagini
    |---------------------------------------------------------------------------
    |
    | 1568 px sul lato lungo: oltre, i token immagine crescono con l'area senza
    | migliorare di nulla la stima di un piatto. E' la seconda delle tre leve di
    | risparmio elencate in §9.3 del piano.
    */
    'image' => [
        'max_edge' => (int) env('AI_IMAGE_MAX_EDGE', 1568),
        'jpeg_quality' => (int) env('AI_IMAGE_JPEG_QUALITY', 85),
    ],

    /*
    |---------------------------------------------------------------------------
    | Consiglio del giorno
    |---------------------------------------------------------------------------
    |
    | La cache e' su (utente, giorno, hash del contesto). **La data fa parte del
    | contesto**, quindi l'hash cambia a mezzanotte e la rigenerazione e'
    | garantita senza nessun cron dedicato — e cambia anche a ogni pasto o
    | allenamento nuovo, che e' esattamente quando il consiglio va rifatto.
    */
    'advice' => [
        'enabled' => (bool) env('AI_ADVICE_ENABLED', true),
    ],
];
