<?php

declare(strict_types=1);

/**
 * Notifiche push — B10.6.
 *
 * 🚨 Il driver di default e' `log`, di proposito: senza credenziali il sistema
 * **funziona** e scrive cosa avrebbe mandato, invece di rompersi. Un'ambiente di
 * sviluppo che esplode perche' manca una chiave di Firebase e' un ambiente che
 * nessuno usa.
 */
return [
    'driver' => env('PUSH_DRIVER', 'log'),

    'fcm' => [
        // Vuoto: si legge dalla chiave di servizio.
        'project_id' => env('FCM_PROJECT_ID'),

        /*
         * 🆕 24/09/2026 — **il percorso della chiave di servizio** di Firebase (il
         * JSON della console). Da lì `TokenDiFirebase` ricava e rinnova il token
         * OAuth. ⚠️ È un segreto: fuori dal repository, mai sul Projects Tracker.
         */
        'credentials' => env('FIREBASE_CREDENTIALS'),

        // Ripiego per le prove brevi: un token OAuth scritto a mano, che scade in un'ora.
        'access_token' => env('FCM_ACCESS_TOKEN'),
    ],
];
