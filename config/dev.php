<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Accesso rapido nella schermata di login
    |--------------------------------------------------------------------------
    |
    | Mostra sotto il form di login dei pulsanti che entrano direttamente come
    | super admin, amministratore di palestra o iscritto, senza digitare
    | credenziali. Serve a provare i tre livelli senza ricordarsi tre account.
    |
    | ⚠️ NON è legato ad `APP_DEBUG`, che sarebbe stata la scelta ovvia.
    | Lo staging ha `APP_DEBUG=false` — è la configurazione giusta per un
    | ambiente raggiungibile da internet — ma è anche l'ambiente da cui questa
    | funzione serve davvero, perché è lì che si prova dal telefono. Legandola
    | al debug non comparirebbe proprio dove serve.
    |
    | Quindi: interruttore dedicato, spento di default. Chi lo accende lo fa
    | apposta, e si vede nel `.env`.
    |
    */

    'quick_login' => (bool) env('DEV_QUICK_LOGIN', false),

];
