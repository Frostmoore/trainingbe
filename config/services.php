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

];
