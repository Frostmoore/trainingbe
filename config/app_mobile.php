<?php

declare(strict_types=1);

/**
 * Dove si scarica l'app — 16/08/2026.
 *
 * ── 🚨 Perche' sono vuoti ──────────────────────────────────────────────────
 *
 * Perche' **l'app non e' ancora pubblicata su nessuno store**. Finche' questi
 * due valori sono vuoti, il sito dice «presto su Google Play e App Store»
 * invece di mostrare un pulsante che non porta da nessuna parte.
 *
 * ⚠️ Un pulsante «Scarica» che non scarica niente e' peggio di nessun
 * pulsante: chi lo preme una volta e non succede niente non lo preme piu', e
 * non torna a controllare se nel frattempo funziona.
 *
 * 🎯 **Il giorno della pubblicazione si riempiono questi due e basta**: i
 * pulsanti compaiono da soli in apertura, in chiusura e nella barra in alto,
 * senza toccare nessun template.
 */
return [

    'android' => env('APP_ANDROID_URL', ''),

    'ios' => env('APP_IOS_URL', ''),

];
