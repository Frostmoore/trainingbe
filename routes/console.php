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

/*
|--------------------------------------------------------------------------
| 🧹 La potatura del dettaglio delle visualizzazioni — M5
|--------------------------------------------------------------------------
|
| `visualizzazioni` contiene **chi ha visto cosa**: un dato personale che non ha
| ragione di stare li' per sempre. Tredici mesi coprono il confronto con lo
| stesso mese dell'anno prima, che e' la forma piu' lontana che puo' prendere
| una contestazione su una fattura.
|
| ⚠️ Gli importi in `campagne` **non** vengono toccati: la fattura resta
| corretta senza dover conservare chi era. E' la differenza fra «quanto hai
| speso», che va conservato, e «chi ti ha visto», che no.
|
| 💡 Il primo del mese alle 4:35 — cinque minuti dopo l'aggiornamento degli
| alimenti, per non farli partire insieme sul server condiviso.
*/
Schedule::command('pubblicita:pota')
    ->monthlyOn(1, '4:35')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura delle visualizzazioni fallita: il dettaglio resta piu\' a lungo del dovuto.');
    });

/*
| Gli allegati cifrati della chat — N14.3.
|
| #! Un allegato si cancella da solo appena il destinatario lo scarica. Questo
| comando copre il caso in cui NON lo scarichi mai: telefono spento, ferie, app
| disinstallata. Senza, quelle righe e quei file resterebbero per sempre.
|
| «restano finche' non le scarica, max 24h» - il committente, 18/08/2026.
|
| * Ogni ora e non una volta al giorno: la promessa e' "24 ore", e una passata
| giornaliera la trasformerebbe in "fino a 48". Costa una query su un indice.
|
| /!\ In background e senza sovrapposizioni: gira su un server condiviso con
| altri domini, e due passate insieme si contenderebbero gli stessi file.
*/
Schedule::command('chat:pota-allegati')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura degli allegati fallita: le foto della chat restano oltre le 24 ore.');
    });

/*
|--------------------------------------------------------------------------
| Le importazioni di piani alimentari scadute (N20)
|--------------------------------------------------------------------------
|
| #! Un'importazione se ne va quando l'app la chiude: bozza confermata e portata
| sul telefono, oppure scartata. Questo comando copre il caso in cui nessuno la
| chiuda mai - la persona apre il PDF, si stanca alla decima riga e non torna.
|
| /!\ E non e' un file qualunque: e' un PDF con dentro la dieta di qualcuno,
| cioe' la cosa piu' delicata che passi da questo server.
|
| * Ogni notte e non ogni ora: la promessa e' "sette giorni", non "24 ore", e
| una passata giornaliera la mantiene con ampio margine.
|
| /!\ In background e senza sovrapposizioni: server condiviso con altri domini.
*/
Schedule::command('piani:pota-importazioni')
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura delle importazioni fallita: PDF di piani alimentari restano oltre i 7 giorni.');
    });
