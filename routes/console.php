<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Il catalogo alimenti si tiene aggiornato da solo — 17/08/2026
|--------------------------------------------------------------------------
|
| 🚨 **Di lunedì alle 4:20 del mattino**, e nessuno dei due numeri è casuale.
|
| Di notte perché il server è condiviso con `lepatenti.it` e
| `aeternagrandtour.it`: qualunque cosa duri qualche minuto va messa quando non
| c'è nessuno. ⚠️ E alle 4:20 e non alle 4:00 perché le ore tonde sono l'orario
| preferito di ogni processo pianificato del mondo, compresi quelli degli altri
| due siti.
|
| 💡 `withoutOverlapping` perché una settimana con molte modifiche può far
| durare l'aggiornamento più del previsto: due esecuzioni sovrapposte
| scriverebbero le stesse righe due volte, e la seconda non aggiungerebbe
| niente tranne carico.
|
| 📌 `runInBackground` per non tenere occupato lo schedulatore mentre gira.
*/
Schedule::command('alimenti:aggiorna-off')
    ->weeklyOn(1, '4:20')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error(
            'Aggiornamento settimanale di Open Food Facts fallito.',
        );
    });
