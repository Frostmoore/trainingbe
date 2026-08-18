<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Accesso con Google e Apple — C17
    |---------------------------------------------------------------------------
    |
    | 🚨 **`client_ids` e' l'elenco degli `aud` ammessi, e va compilato.**
    | Finche' e' vuoto, il fornitore risulta «non configurato»: l'endpoint
    | risponde 501 e l'app non mostra il pulsante. E' voluto — un «Accedi con
    | Apple» che da' sempre errore fa sembrare rotta tutta l'applicazione.
    |
    | ⚠️ **Sono piu' di uno, ed e' normale.** Google emette un client id per
    | ogni piattaforma: quello Android e quello iOS sono diversi, e il token
    | arrivera' con l'uno o con l'altro a seconda del telefono. Metterne uno
    | solo fa funzionare l'accesso su meta' dei dispositivi — il tipo di guasto
    | che in prova non si vede, perche' si prova su un telefono solo.
    |
    | Per Apple l'`aud` e' il **bundle id** dell'app (es. `it.riccardoronconi.
    | training`), non un client id in senso proprio. Per «Accedi con Apple» sul
    | web sarebbe invece il Service ID: se un giorno ci sara' anche il web, ne
    | va aggiunto un secondo qui.
    |
    | Valori separati da virgola nel `.env`:
    |   SOCIAL_GOOGLE_CLIENT_IDS="1234-android.apps.googleusercontent.com,1234-ios.apps.googleusercontent.com"
    |   SOCIAL_APPLE_CLIENT_IDS="it.riccardoronconi.training"
    */
    'social' => [
        'google' => [
            'client_ids' => env('SOCIAL_GOOGLE_CLIENT_IDS', ''),
        ],
        'apple' => [
            'client_ids' => env('SOCIAL_APPLE_CLIENT_IDS', ''),
        ],
    ],

    /*
     * Stripe — 17/08/2026.
     *
     * 🚨 **Le chiavi stanno solo in `.env`, che e' ignorato da git.** Non
     * finiscono mai in un file versionato: `.env.example` porta i nomi, non i
     * valori.
     *
     * ⚠️ **`webhook_secret` non e' facoltativo.** La conferma di un pagamento
     * deve arrivare dal webhook firmato, **non** dal ritorno del browser: chi
     * torna sulla pagina di successo puo' averci messo l'indirizzo a mano, e
     * accreditare gettoni su quella base vuol dire regalarli a chiunque legga
     * l'URL una volta.
     */
    'stripe' => [
        /*
         * 🚨 **Le chiavi vere si usano SOLO in produzione.**
         *
         * Su questa macchina convivono due coppie di chiavi: quelle live e
         * quelle di prova. ⚠️ Fino al 18/08 la configurazione leggeva
         * `STRIPE_SECRET_KEY` e basta - cioe' **quella live**, anche in locale.
         * Un flusso di pagamento scritto male avrebbe mosso soldi veri al primo
         * tentativo, e nessuno se ne sarebbe accorto prima dell'estratto conto.
         *
         * 💡 Il verso della condizione conta: si parte dalle chiavi di prova
         * e si passa a quelle vere **solo** dichiarando `APP_ENV=production`.
         * Il contrario - live salvo eccezioni - sbaglierebbe in silenzio ogni
         * volta che qualcuno dimentica una variabile.
         *
         * 📌 In locale `APP_ENV=local`, su staging `APP_ENV=staging`:
         * entrambi prendono la sandbox, ed e' quello che serve.
         */
        'key' => env('APP_ENV') === 'production'
            ? env('STRIPE_PUBLIC_KEY')
            : env('STRIPE_STAGING_PUBLIC_KEY'),

        'secret' => env('APP_ENV') === 'production'
            ? env('STRIPE_SECRET_KEY')
            : env('STRIPE_STAGING_SECRET_KEY'),

        /*
         * ⚠️ Il segreto dei webhook e' **diverso fra sandbox e produzione**:
         * Stripe ne genera uno per ogni endpoint registrato, e quello della
         * sandbox non verifica le firme di produzione.
         */
        'webhook_secret' => env('APP_ENV') === 'production'
            ? env('STRIPE_WEBHOOK_SECRET')
            : env('STRIPE_STAGING_WEBHOOK_SECRET'),
    ],

];
