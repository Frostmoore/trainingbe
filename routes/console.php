<?php

use App\Models\StimaCibo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
| Le buste usa e getta scadute (N16)
|--------------------------------------------------------------------------
|
| #! Un messaggio "una volta sola" smette di essere consegnato a chi lo riceve
| appena lo apre, ma resta sul server per le 24 ore di chi lo ha mandato. Se il
| destinatario non apre mai, non si fermerebbe nemmeno quello.
|
| /!\ Senza questa passata una busta effimera resterebbe sul server per sempre,
| che e' l'esatto contrario di quello che ha chiesto chi l'ha mandata - e
| nessuno se ne accorgerebbe, perche' dall'app sembrerebbe sparita.
|
| * Ogni ora come gli allegati: la promessa e' "24 ore", e una passata
| giornaliera la trasformerebbe in "fino a 48".
*/
Schedule::command('chat:pota-effimeri')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura delle buste effimere fallita: i messaggi usa e getta restano sul server.');
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

/*
|------------------------------------------------------------------------------
| 🆕 I documenti delle schede importate dal pannello — A8, 26/09/2026
|------------------------------------------------------------------------------
|
| Il PDF o la foto che una palestra carica per trascrivere una scheda restavano
| per sempre (DPIA R19). Adesso si cancellano 30 giorni dopo il caricamento:
| l'import resta come storia, la scheda creata non si tocca.
|
| /!\ In background e senza sovrapposizioni: server condiviso con altri domini.
*/
Schedule::command('schede:pota-documenti')
    ->dailyAt('03:25')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura dei documenti delle schede fallita: restano oltre i 30 giorni.');
    });

/*
|------------------------------------------------------------------------------
| 🆕 La potatura della coda — FASE 9.8
|------------------------------------------------------------------------------
|
| 🚨 `failed_jobs` **non si svuota da sola**, e cresce. Da quando le stime
| del cibo passano dalla coda, i lavori sono molti e piccoli: un fallimento ogni
| tanto è fisiologico, e in un anno diventa una tabella che nessuno guarda piena
| di righe che non servono a niente.
|
| ══ 🚨 VENTIQUATTR'ORE, NON SETTE GIORNI — FASE 8.6, 23/08/2026 ══════════════
|
| 📌 Il committente: *«devono restare per max 24h poi si cancellano»*.
|
| ⛔ **Erano 168 ore, e il motivo per cui non vanno piu' bene non e' la
| dimensione della tabella: e' cosa c'e' dentro.** Un lavoro in coda conserva il
| suo `payload`, e per le stime del cibo quel payload contiene **la foto di un
| pasto** e il contesto alimentare.
|
| 🚨 Sono dati che oggi **passano e basta** — il server li inoltra ad Anthropic e
| non li conserva. Un lavoro fallito li rende **dati a riposo**, per una
| settimana, in una tabella che nessuno guarda. E' una categoria di trattamento
| diversa, e l'informativa non la prevedeva.
|
| ⚠️ Ventiquattro ore restano perche' un fallimento va capito, e la domanda
| «perche' e' fallito» si fa il giorno stesso. Dopo, il diagnostico vale meno del
| dato che si tiene.
|
| 💡 Le `stime_cibo` si potano insieme: sono una **cache** (come `ai_advices`,
| §46), e una stima non confermata dopo 24 ore non interessa piu' a nessuno.
*/
/*
 * ══ 🎁 I REGALI SI RINNOVANO DA SOLI — 08/09/2026 ═════════════════════════
 *
 * 📌 Il committente: *«deve funzionare anche con gli abbonamenti che regalo»*.
 *
 * 🚨 **Per Stripe il rinnovo lo fa Stripe. Per un regalo non lo fa nessuno**:
 * senza questo giro, `rinnova = true` su un regalo sarebbe una spunta scollegata
 * da tutto — un interruttore che sembra funzionare e non e' attaccato a niente.
 *
 * ⚠️ **Alle 3:40, e guarda un giorno avanti.** Rinnovare solo cio' che e' gia'
 * scaduto lascerebbe un buco fra la mezzanotte e il giro: tre ore in cui una
 * persona che il rinnovo non l'ha mai spento si vede «il tuo abbonamento e'
 * scaduto».
 */
Schedule::command('abbonamenti:rinnova-i-regali')
    ->dailyAt('3:40')
    ->withoutOverlapping();

/*
 * ══ 💶 IL PIANO A CONSUMO SI FATTURA DA SOLO — 3b-AH.6.9, 17/09/2026 ══════
 *
 * 📌 Il committente, 13/09: *«Se una palestra sceglie il piano a consumo
 * paghera' un tot per ogni iscritto a cui regala l'abbonamento, per un minimo
 * di 100.»*
 *
 * ⛔ Dal 13/09 esistevano il prezzo, la soglia e il calcolo — e **nessuno che
 * chiedesse i soldi**. 🚨 E' il difetto che per definizione non da' errore: un
 * incasso che non arriva non lancia niente, non compare in nessun log, e si
 * scopre guardando il conto.
 *
 * ⚠️ **Il primo del mese, e fattura il mese APPENA CHIUSO.** A consumo si paga
 * quello che si e' consumato: finche' il mese e' aperto quel numero cambia
 * ancora, e chiedere in anticipo sarebbe un canone — cioe' l'altro piano.
 *
 * 💡 Alle 4:20, dopo il rinnovo dei regali: se una palestra regala abbonamenti
 * ai suoi iscritti, quel giro va fatto **prima** che si conti chi c'e' dentro.
 */
Schedule::command('fattura:il-consumo')
    ->monthlyOn(1, '4:20')
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=24')
    ->dailyAt('4:05')
    ->withoutOverlapping(30)
    ->runInBackground();

/*
| ══ 🚨 E I LAVORI CHE NON SONO MAI PARTITI ═══════════════════════════════════
|
| ⚠️ `queue:prune-failed` pota `failed_jobs`. ⛔ **Non tocca `jobs`**, dove sta
| un lavoro *in attesa* — con il suo payload dentro, foto compresa.
|
| 🚨 In condizioni normali un lavoro resta li' per secondi. Ma se il worker
| muore, o se una riga si incaglia per un bug, quel payload resta a riposo **per
| sempre**: nessuno lo pota, e non compare in nessun conteggio.
|
| 💡 Un lavoro fermo da piu' di ventiquattr'ore non va eseguito comunque: la
| persona ha gia' visto un errore e ha gia' rifatto la stima. Cancellarlo non
| perde niente, e chiude l'unico posto dove una foto poteva restare per sempre.
*/
Schedule::call(static function (): void {
    DB::table('jobs')
        ->where('created_at', '<', now()->subDay()->getTimestamp())
        ->delete();
})
    // ⚠️ `name()` PRIMA di `withoutOverlapping()`: su una closure il lucchetto
    // si chiama come il lavoro, e senza nome Laravel non sa cosa bloccare —
    // lancia, e lo fa solo quando lo schedulatore gira davvero.
    ->name('pota-i-lavori-incagliati')
    ->dailyAt('4:10')
    // ⛔ Niente `runInBackground()`: su una closure non esiste — girerebbe in un
    // processo che non ha il codice da eseguire. Una DELETE su una tabella
    // piccola costa meno del processo che si sarebbe risparmiata.
    ->withoutOverlapping(30);

Schedule::command('model:prune', ['--model' => StimaCibo::class])
    ->hourly()
    ->withoutOverlapping(15)
    ->runInBackground();

/*
| I registri: si tengono finche' servono a funzionare - 20/09/2026.
|
| «tempi di conservazione solo finche' serve per funzionare» - il committente.
|
| # Un termine scritto e non applicato e' peggio di un termine lungo: dichiara
|   una cosa che non succede. `audit_logs` e `ai_usage_logs` non li potava
|   nessuno, e l'informativa avrebbe detto il falso dal primo giorno.
|
| * Il primo del mese alle 4:45 - dopo la potatura della pubblicita' (4:35), per
|   non far partire due DELETE insieme sul server condiviso.
*/
Schedule::command('registri:pota')
    ->monthlyOn(1, '4:45')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('Potatura dei registri fallita: restano piu\' a lungo di quanto l\'informativa promette.');
    });

/*
 * ══ 🎁 LE PROMOZIONI CHE LAVORANO DA SOLE — 24/09/2026, P2 di plan_promozioni.md ══
 *
 * 💡 **Alle 00:10 il giro intero**: prima i gettoni regalati scaduti, poi quelli
 * ricorrenti del mese, poi le automatiche. Dieci minuti dopo la mezzanotte, così
 * un lotto che «scade il 25» sparisce il 25 e non la sera del 24.
 *
 * ⚠️ **E le automatiche ogni 15 minuti**: chi si iscrive (o entra in una
 * palestra) e rientra in una promozione con un regalo non deve aspettare la
 * notte. Ogni persona la riceve una volta sola (riferimento `auto:<user>`),
 * quindi i giri ripetuti non regalano due volte.
 */
Schedule::command('promozioni:giro')
    ->dailyAt('0:10')
    ->withoutOverlapping();

Schedule::command('promozioni:giro --solo=automatiche')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
