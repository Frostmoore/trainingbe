<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | La versione minima dell'app — FASE 10
    |---------------------------------------------------------------------------
    |
    | 🚨 **Il default e' 0, cioe' «non blocco nessuno», e non e' pigrizia: e'
    | l'interruttore di sicurezza.**
    |
    | Un valore sbagliato qui **blocca tutti gli utenti insieme**, e l'unico
    | rimedio sarebbe un altro intervento sul server. ⚠️ Un cancello che di serie
    | e' chiuso e' un cancello che il giorno del primo deploy chiude fuori tutti,
    | compresi noi.
    |
    | Si confronta il `versionCode` (`X-App-Build`), che e' un **intero**. Non la
    | stringa `7.45.0`: confrontando le stringhe, `7.10.0` risulta **minore** di
    | `7.9.0`, e non ce ne si accorge fino alla decima versione minore.
    |
    | ⚠️ **Per piattaforma**, perche' le pubblicazioni sugli store non arrivano
    | nello stesso momento: alzare il minimo per Android quando la versione iOS
    | non e' ancora approvata vorrebbe dire fermare gli utenti iOS per una cosa
    | che non possono fare.
    */
    'minima' => [
        'android' => (int) env('APP_MIN_BUILD_ANDROID', 0),
        'ios' => (int) env('APP_MIN_BUILD_IOS', 0),
    ],

    /*
    |---------------------------------------------------------------------------
    | Dove si va ad aggiornare
    |---------------------------------------------------------------------------
    |
    | 💡 L'indirizzo lo manda il server e non lo scrive l'app: il giorno che
    | l'identificativo del pacchetto cambia — un'app white-label per una catena,
    | per dire — le copie gia' installate manderebbero la gente sulla scheda
    | sbagliata, e sarebbero **proprio quelle che non si possono aggiornare**.
    */
    'store' => [
        'android' => env(
            'APP_STORE_ANDROID',
            'https://play.google.com/store/apps/details?id=com.smp.mytrainingcompanion',
        ),
        'ios' => env('APP_STORE_IOS', ''),
    ],

];
