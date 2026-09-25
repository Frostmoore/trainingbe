<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔕 **«Non mandarmi offerte»** — voce 43, DPIA **R16**, 25/09/2026.
 *
 * ══ ⛔ IL RESIDUO CHE CHIUDE ════════════════════════════════════════════════
 *
 * 📌 La DPIA, R16: *«Non c'è ancora un modo, nel prodotto, per dire "non
 * mandarmi offerte": oggi ci si oppone scrivendo (informativa §3.13)»*.
 *
 * 🚨 **Opporsi scrivendo una email non è un modo di opporsi**: chi lo fa deve
 * sapere che esiste il diritto, trovare l'indirizzo, scrivere, e sperare che
 * qualcuno legga. ⛔ L'art. 21(2) dà il diritto di opporsi al marketing **in
 * qualsiasi momento**, e il 21(4) vuole che sia presentato *«chiaramente e
 * separatamente»*: un interruttore è la forma minima che regge.
 *
 * ══ 🔑 PERCHE' DUE COLONNE E NON UNA ═══════════════════════════════════════
 *
 * ⚠️ Il marketing passa da **due strade**, e spegnerne una sola darebbe alla
 * persona la certezza di essere a posto mentre continua a ricevere offerte
 * dall'altra:
 *
 * | Strada | Chi la governa |
 * |---|---|
 * | gli avvisi delle **promozioni** (push, email) | `AvvisaIDestinatari` |
 * | gli **avvisi in-app** che propongono un acquisto (B4) | `QualeAvvisoMostrare` |
 *
 * 💡 Per la seconda serve che un avviso **dichiari** di essere commerciale:
 * `avvisi.commerciale`. 🚨 E non è una dichiarazione libera — `Avviso::saving()`
 * la impone a chi ha azione `promo`: ⛔ non si manda un'offerta dichiarandola
 * «non commerciale», nemmeno per sbaglio.
 *
 * ══ ⚠️ QUELLO CHE L'INTERRUTTORE **NON** SPEGNE ════════════════════════════
 *
 * ⛔ Gli avvisi **urgenti** e quelli di servizio, le notifiche dei **messaggi**,
 * le email di sistema (password, inviti, ricevute). 💡 Non sono marketing, e
 * spegnerli con lo stesso interruttore vorrebbe dire che chi rifiuta la
 * pubblicità perde anche l'avviso che il suo abbonamento sta per scadere.
 *
 * ══ 🚨 SI CHIAMA `niente_offerte` E NON `marketing_consent` ════════════════
 *
 * ⚠️ Perché è un **opt-out**, non un consenso: di serie le offerte **arrivano**
 * (legittimo interesse, informativa §3.13), e chi non le vuole lo dice.
 * ⛔ Chiamarlo «consenso» farebbe credere che di serie sia spento, e un giorno
 * qualcuno scriverebbe `if (! $u->marketing_consent) return;` mandando a zero
 * le promozioni per tutti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * 💡 `false` di serie = le offerte arrivano, come prima di questa
             * migrazione. ⚠️ Il default deve essere lo **stato di oggi**: una
             * migrazione che cambia il comportamento di tutti mentre aggiunge
             * un interruttore è due cose in una, e la seconda non se l'aspetta
             * nessuno.
             */
            $table->boolean('niente_offerte')->default(false)->after('trovabile');

            // 🚨 Quando l'ha detto: serve a dimostrare **da quando** vale.
            $table->timestamp('niente_offerte_dal')->nullable()->after('niente_offerte');
        });

        Schema::table('avvisi', function (Blueprint $table): void {
            /*
             * ⚠️ `false` di serie, ed è il verso giusto: un avviso di servizio
             * scritto prima di questa colonna **non** deve diventare
             * pubblicità. ⛔ Il verso opposto spegnerebbe avvisi veri a chi ha
             * l'interruttore acceso.
             */
            $table->boolean('commerciale')->default(false)->after('urgente');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['niente_offerte', 'niente_offerte_dal']);
        });

        Schema::table('avvisi', function (Blueprint $table): void {
            $table->dropColumn('commerciale');
        });
    }
};
