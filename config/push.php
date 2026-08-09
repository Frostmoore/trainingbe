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
        'project_id' => env('FCM_PROJECT_ID'),
        // Token OAuth di servizio. In produzione va generato dal service account
        // e rinnovato: tenerlo in `.env` va bene per staging, non oltre.
        'access_token' => env('FCM_ACCESS_TOKEN'),
    ],
];
