<?php

/*
|------------------------------------------------------------------------------
| 🚨 Stripe: quando si usano le chiavi VERE
|------------------------------------------------------------------------------
|
| Serve **una sola risposta**, usata in quattro punti. Calcolarla qui invece di
| ripetere la condizione quattro volte non e' eleganza: ⚠️ quattro copie della
| stessa condizione sono quattro posti da cambiare, e il giorno che se ne
| cambiano tre su quattro il risultato e' una configurazione **mista** — chiave
| pubblica di prova e chiave segreta vera — che fallisce in un modo che non
| parla di ambienti.
|
| 🚨 **La regola vive in `ChiaviDiStripe::usaChiaviVere()`, non qui.** Se fosse
| scritta in questo file esisterebbe due volte — una in configurazione e una nel
| servizio che fa da guardia — e un test scritto contro una delle due resterebbe
| verde mentre l'altra sbaglia.
|
| 💡 Chiamare una classe da un file di configurazione si puo': `bootstrap/app.php`
| registra l'autoloader **prima** di caricare la configurazione.
*/
$stripeUsaChiaviVere = App\Services\Billing\Stripe\ChiaviDiStripe::usaChiaviVere(
    env('APP_ENV'),
    env('APP_DEBUG', true),
);

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
    | Per Apple l'`aud` e' il **bundle id** dell'app (`com.smp.mytrainingcompanion`),
    | non un client id in senso proprio. Per «Accedi con Apple» sul
    | web sarebbe invece il Service ID: se un giorno ci sara' anche il web, ne
    | va aggiunto un secondo qui.
    |
    | Valori separati da virgola nel `.env`:
    |   SOCIAL_GOOGLE_CLIENT_IDS="1234-android.apps.googleusercontent.com,1234-ios.apps.googleusercontent.com"
    |   SOCIAL_APPLE_CLIENT_IDS="com.smp.mytrainingcompanion"
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
         * 🚨 **Le chiavi vere si usano SOLO in produzione, e SOLO con il debug
         * spento.** Servono **tutte e due** le condizioni.
         *
         * ── La regola, testuale dal committente (18/08) ────────────────────
         *
         * *«Finche' il sito e' in debug mode deve usare le chiavi staging e il
         * webhook staging. Quando sara' in production mode allora usera'
         * l'endpoint vero e le chiavi vere.»*
         *
         * ── ⚠️ Perche' non basta `APP_DEBUG`, e non basta `APP_ENV` ────────
         *
         * Sono due interruttori indipendenti, e ciascuno da solo ha un buco:
         *
         * - **Solo `APP_DEBUG`**: qualcuno spegne il debug su staging per
         *   provare una pagina d'errore vera, e staging comincia a muovere
         *   soldi veri. Il debug e' un interruttore che si tocca spesso e per
         *   ragioni che non c'entrano niente con i pagamenti.
         * - **Solo `APP_ENV`**: un `.env` copiato dalla produzione, o un
         *   `APP_ENV=production` messo per riprodurre un difetto, e le chiavi
         *   vere entrano in gioco su una macchina di sviluppo.
         *
         * 💡 **Insieme sono piu' sicuri di entrambi**: per arrivare alle chiavi
         * vere bisogna sbagliare **due** variabili nello stesso momento, e in
         * un modo che sulla macchina di sviluppo non ha nessuna ragione di
         * capitare — nessuno mette `APP_ENV=production` **e** `APP_DEBUG=false`
         * in locale, perche' vorrebbe dire lavorare senza vedere gli errori.
         *
         * 🚨 **Il verso della condizione conta.** Si parte dalle chiavi di
         * prova e si passa a quelle vere solo dichiarando esplicitamente
         * entrambe le cose. Il contrario — live salvo eccezioni — sbaglierebbe
         * **in silenzio** ogni volta che qualcuno dimentica una variabile, ed
         * e' esattamente com'era scritto fino al 18/08: la configurazione
         * leggeva `STRIPE_SECRET_KEY` e basta, cioe' quella live, anche in
         * locale. Un flusso di pagamento scritto male avrebbe mosso soldi veri
         * al primo tentativo, e nessuno se ne sarebbe accorto prima
         * dell'estratto conto.
         *
         * 📌 Oggi: in locale `APP_ENV=local`, su staging `APP_ENV=staging`,
         * debug acceso su entrambi. Prendono la sandbox due volte, ed e' giusto
         * cosi'.
         *
         * ⚠️ **L'endpoint webhook di produzione non esiste ancora**, per
         * decisione del committente: si creera' quando il sito sara' sul
         * dominio vero. Finche' non c'e', `STRIPE_WEBHOOK_SECRET` resta vuota e
         * in produzione il webhook risponderebbe `503` — che e' il
         * comportamento giusto, non un difetto da aggirare.
         */
        'key' => $stripeUsaChiaviVere
            ? env('STRIPE_PUBLIC_KEY')
            : env('STRIPE_STAGING_PUBLIC_KEY'),

        'secret' => $stripeUsaChiaviVere
            ? env('STRIPE_SECRET_KEY')
            : env('STRIPE_STAGING_SECRET_KEY'),

        /*
         * ⚠️ Il segreto dei webhook e' **diverso fra sandbox e produzione**:
         * Stripe ne genera uno per ogni endpoint registrato, e quello della
         * sandbox non verifica le firme di produzione. Non sono intercambiabili
         * neanche fra due endpoint della stessa modalita'.
         */
        'webhook_secret' => $stripeUsaChiaviVere
            ? env('STRIPE_WEBHOOK_SECRET')
            : env('STRIPE_STAGING_WEBHOOK_SECRET'),

        /*
         * 💡 Se stiamo parlando con la sandbox — per **dirlo nei pannelli**.
         *
         * ⚠️ Non si deduce guardando il prefisso della chiave: una chiave
         * mancante non ha prefisso, e «nessuna chiave» verrebbe letto come
         * «sandbox». Qui invece e' la stessa decisione che sceglie le chiavi,
         * quindi non puo' discordare.
         */
        'sandbox' => ! $stripeUsaChiaviVere,
    ],

];
