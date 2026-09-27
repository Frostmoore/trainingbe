<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 🧪 La vista dei 25 casi di prova — 63.H, 27/09/2026
    |--------------------------------------------------------------------------
    |
    | 📌 Il committente: «ho bisogno di una view di debug in locale con una serie
    | di link di accesso che mi permettano di verificare ogni singolo caso per
    | conto mio».
    |
    | 🚨 QUESTA E' LA SECONDA DI DUE GUARDIE, NON L'UNICA.
    |
    | La rotta pretende **anche** `app()->environment('local')`, e le due sono
    | indipendenti: su un server fallirebbero tutte e due. ⛔ Due guardie e non
    | una perché `APP_ENV` su un server si sbaglia **una volta sola e per
    | sempre**, e quello che si aprirebbe è un elenco di collegamenti che entrano
    | in un account con un clic.
    |
    | ⚠️ Di serie segue l'ambiente: accesa in locale, spenta in qualunque altro
    | posto. 💡 Si può spegnere anche in locale mettendo `CASI_DI_PROVA=false`
    | nel proprio `.env` — utile a chi lavora su altro e non vuole venticinque
    | palestre finte nel database.
    |
    | ⛔ Metterla a `true` su un server NON apre niente: la guardia
    | sull'ambiente resta, e `CasiDiProva` si rifiuta di essere costruita.
    |
    */

    /*
     * ⚠️ **`env('APP_ENV')` e non `app()->environment()`**, e non e' pignoleria:
     * i file di configurazione si leggono **prima** che il contenitore sia
     * pronto, e chiamare `app()` qui fa morire ogni comando `artisan` con
     * *«Target class [env] does not exist»* — un errore che non nomina nemmeno
     * lontanamente la sua causa.
     *
     * 💡 Pagato in diretta scrivendo questa voce.
     */
    'attiva' => (bool) env('CASI_DI_PROVA', env('APP_ENV') === 'local'),

];
