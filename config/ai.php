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
            'daily_advice' => env('AI_MODEL_ADVICE', 'claude-haiku-4-5'),
            'pdf_import' => env('AI_MODEL_PDF_IMPORT', 'claude-sonnet-5'),

            /*
             * D13 — la stima di un alimento mentre il trainer compone.
             *
             * 💡 Stesso modello di `food_text`: e' la stessa domanda,
             * fatta da un'altra persona. Ha una chiave sua perche' il
             * giorno che le due divergessero — un modello piu' preciso
             * per chi scrive i piani — non serva una migrazione per
             * accorgersene.
             */
            'plan_food' => env('AI_MODEL_PLAN_FOOD', 'claude-haiku-4-5'),

            /*
             * N20 - la trascrizione di un piano alimentare da PDF.
             *
             * 🚨 **Il modello migliore che abbiamo, e non si scende.** Qui
             * il rischio non e' fallire - un fallimento si vede e si rifa'. E'
             * riuscire **a meta'**: <<200 g>> letti <<20 g>> non danno nessun
             * errore, danno un piano plausibile e sbagliato che qualcuno
             * seguira' per settimane.
             *
             * ⚠️ Non esiste un'escalation su questa funzione (vedi il job): a 50
             * gettoni, un secondo tentativo automatico e' una seconda fattura.
             * Quindi il primo tentativo dev'essere gia' quello buono.
             */
            'nutrition_pdf_import' => env('AI_MODEL_NUTRITION_PDF_IMPORT', 'claude-sonnet-5'),

            /*
             * 3b-I.A - l'analisi della progressione degli esercizi.
             *
             * 💡 **Il modello piccolo, e va bene cosi'.** Il compito non e'
             * capire l'allenamento: e' guardare otto numeri e dire se salgono.
             * Un modello grande costerebbe di piu' per scrivere la stessa riga.
             *
             * 🚨 **Ma il rischio non e' la qualita', e' il divieto.** Un modello
             * piccolo sbaglia piu' facilmente a stare dentro le regole - ed e'
             * esattamente per questo che il setaccio
             * (`Prompts::ripulisciProgresso()`) non e' facoltativo: la scelta di
             * risparmiare qui e' sostenibile **solo** perche' c'e' quello dopo.
             */
            'plan_progress' => env('AI_MODEL_PLAN_PROGRESS', 'claude-haiku-4-5'),
        ],
        'openai' => [
            'food_text' => env('AI_MODEL_OPENAI_FOOD_TEXT', 'gpt-4.1-mini'),
            'food_photo' => env('AI_MODEL_OPENAI_FOOD_PHOTO', 'gpt-4.1'),
            'daily_advice' => env('AI_MODEL_OPENAI_ADVICE', 'gpt-4.1-mini'),
            'plan_food' => env('AI_MODEL_OPENAI_PLAN_FOOD', 'gpt-4.1-mini'),
            'pdf_import' => env('AI_MODEL_OPENAI_PDF_IMPORT', 'gpt-4.1'),

            /*
             * ⚠️ C'e' per non far esplodere `modelFor()`, ma il provider OpenAI
             * rifiuta comunque i PDF (`ai_pdf_unsupported`): fallire chiaro e'
             * meglio che provarci e restituire una trascrizione peggiore senza
             * dirlo - e su questo documento <<peggiore>> vuol dire grammi
             * sbagliati.
             */
            'nutrition_pdf_import' => env('AI_MODEL_OPENAI_NUTRITION_PDF_IMPORT', 'gpt-4.1'),
            'plan_progress' => env('AI_MODEL_OPENAI_PLAN_PROGRESS', 'gpt-4.1-mini'),
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
    | 🆕 **Da G2 il tetto si conta in CHIAMATE, non in token** (D6), e i livelli
    | sono cinque, non tre:
    |   1. `users.ai_monthly_call_cap`            — l'eccezione per una persona
    |   2. `tenants.ai_monthly_calls_per_member`  — la scelta della palestra
    |   3. il trainer indipendente che segue quella persona
    |   4. `plans.ai_monthly_calls_per_member`    — il piano in corso
    |   5. questo default
    |
    | In **tutti** i livelli `0` vale «illimitato» e `null` vale «non impostato,
    | scendi al livello successivo»: sono due cose diverse, e senza la
    | distinzione non si potrebbe sbloccare una persona sola.
    |
    | ⚠️ E i contatori sono **due**: quello generale e il sotto-limite sulle
    | chiamate con allegato, che costano fino a undici volte le altre (D7).
    |
    | ⛔ La chiave dei token qui sotto **non la legge piu' nessuno**: resta
    | finche' `G2.5` non toglie anche le colonne.
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
        /*
         * ══ 🚨 400 → 150 il 26/08/2026, ed e' il numero che COMANDA ════════
         *
         * 📌 *«non va bene 300 chiamate per gli abbonati, facciamo 150»* ·
         * *«uniformali tutti a 150»*.
         *
         * ⛔ **Lo stesso numero viveva in CINQUE posti**, e nessuno era
         * d'accordo con gli altri:
         *
         * | Dove | Diceva | Chi lo leggeva |
         * |---|---|---|
         * | `listino.gettoni_mensili` | 300 | il sito e l'app |
         * | `PlanSeeder::CHIAMATE` | 450 | solo chi lancia il seeder |
         * | la migration dei piani | **niente** | il database vero |
         * | questo default | **400** | 🚨 **la guardia, cioe' la realta'** |
         * | la schermata dell'app | 400 scritto a mano | il cliente |
         *
         * 💡 Il tetto vero era **questo**: la migration lascia
         * `ai_monthly_calls_per_member` a `null` sul piano `plus`, quindi
         * `QuotaAi::capFor()` scendeva fino al livello 5 e trovava 400.
         */
        'default_monthly_calls_per_user' => (int) env('AI_DEFAULT_MONTHLY_CALLS_PER_USER', 150),
        'default_monthly_photo_calls_per_user' => (int) env('AI_DEFAULT_MONTHLY_PHOTO_CALLS_PER_USER', 15),
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

    /*
    |---------------------------------------------------------------------------
    | Quante chiamate AI insieme — FASE 8.2
    |---------------------------------------------------------------------------
    |
    | Misurato il 21/08/2026: una chiamata dura ~3s (mediana) e il pool PHP-FPM
    | di questo dominio ha `pm.max_children = 6`. Sei processi diviso tre secondi
    | fa ~2 richieste AI al secondo prima che il dominio sia pieno — e quando i
    | processi finiscono non si ferma l'AI, si ferma il sito.
    |
    | `slot` e' quanti processi al massimo possono stare dentro una chiamata AI.
    | Metterlo a 0 spegne il tetto.
    |
    | 🚨 **Era 3, ed era sbagliato** — corretto il 21/08 prima di andare in
    | produzione. Il committente: *«se la pubblico e cinque persone all'ora di
    | pranzo scrivono il pranzo si impalla tutto?»*. Con tre slot la QUARTA
    | richiesta contemporanea prendeva 429: cioe' a cinque persone a pranzo due
    | vedevano un errore. ⚠️ Un fusibile giusto contro una valanga, e sbagliato
    | per il funzionamento normale dell'app — il cibo si scrive tutto alla stessa
    | ora, per definizione.
    |
    | 💡 A 5 resta comunque un processo libero per chi apre l'app, e il fusibile
    | scatta solo quando la valanga c'e' davvero.
    |
    | ⛔ E resta un RIPIEGO: la difesa vera e' la FASE 9 (le stime del cibo in
    | coda), che toglie l'attesa dai processi web invece di respingerla.
    |
    | ⚠️ `ttl` deve superare la chiamata piu' lenta mai vista (8,8s): serve solo
    | a liberare lo slot se il processo che lo teneva e' morto.
    */
    'concorrenza' => [
        'slot' => (int) env('AI_SLOT_CONTEMPORANEI', 5),
        'ttl' => (int) env('AI_SLOT_TTL', 30),
        'riprova_fra' => (int) env('AI_SLOT_RIPROVA_FRA', 5),
    ],
];
