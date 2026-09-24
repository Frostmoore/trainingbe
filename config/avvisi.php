<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 📣 Gli avvisi in-app — il tetto di frequenza
|--------------------------------------------------------------------------
|
| 📌 Il committente, 24/09/2026: *«come tetto di frequenza non lo so, mi fido di
| te»*. 🚨 Quindi i numeri sono **miei**, e il perché di ciascuno sta scritto
| qui e in `memory/decisioni.md` — o fra sei mesi sembreranno arbitrari.
|
| ⛔ Stanno in configurazione e non sparsi nel codice: il giorno che si cambiano,
| si cambiano in un posto solo.
|
*/

return [

    /*
     * 📅 **Quanti al mese, per persona.**
     *
     * 💡 Quattro ≈ uno a settimana. Sopra questa soglia un avviso smette di
     * essere una notizia e diventa rumore di fondo — e il meccanismo funziona
     * benissimo **fino al giorno in cui la gente disinstalla**.
     *
     * ⚠️ Si contano quelli **mostrati**, non quelli creati: un avviso che una
     * persona non ha mai visto non le ha dato fastidio.
     */
    'al_mese' => (int) env('AVVISI_AL_MESE', 4),

    /*
     * ⏳ **Quante ore fra uno e l'altro.**
     *
     * 🚨 Senza, quattro avvisi possono arrivare tutti lo stesso pomeriggio: il
     * tetto mensile da solo non difende da una raffica.
     */
    'ore_fra_due' => (int) env('AVVISI_ORE_FRA_DUE', 24),

];
