<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💶 `addebiti_mensili` — l'addebito del piano a consumo, 3b-AH.6.9
 * (17/09/2026).
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 13/09/2026: *«Se una palestra sceglie il piano a consumo
 * paghera' un tot per ogni iscritto a cui regala l'abbonamento, per un minimo
 * di 100.»*
 *
 * ⛔ Da allora esistevano il prezzo (`plans.prezzo_iscritto_cent`), la soglia
 * (`plans.minimo_iscritti`) e il calcolo (`Plan::costoDelMese()`) — e **nessuno
 * che addebitasse niente**. 🚨 Un listino che promette un prezzo senza che
 * arrivi mai una fattura non e' un piano: e' un regalo con un cartellino.
 *
 * ══ 🚨 PERCHE' UNA TABELLA E NON UNA CHIAMATA A STRIPE E BASTA ════════════
 *
 * Per tre ragioni, e tutte e tre sono gia' costate a qualcuno:
 *
 * 1. **L'idempotenza.** `unique(tenant_id, periodo)` e' l'unica garanzia vera
 *    che un mese non venga addebitato due volte. ⛔ Un comando schedulato
 *    ripartito a mano, un deploy durante il giro, una coda che riprova: senza
 *    il vincolo, il secondo giro fattura di nuovo. 💡 E' la stessa difesa di
 *    `stripe_events.event_id`, che ha gia' salvato gli accrediti dei gettoni.
 *
 * 2. **La spiegazione.** Quando la palestra chiede *«perche' questo mese 41 e
 *    non 38?»*, la risposta deve essere una riga da leggere, non una query da
 *    ricostruire a posteriori su dati che nel frattempo sono cambiati. 🚨 Gli
 *    iscritti si contano **adesso**: fra un mese quel numero non esiste piu'.
 *
 * 3. **Il fallimento visibile.** Una palestra senza cliente Stripe non si puo'
 *    addebitare. ⛔ Senza una riga, quel mese sparisce in silenzio e si scopre
 *    a fine anno; con la riga in stato `senza_cliente`, si vede subito e si
 *    recupera.
 *
 * ══ ⚠️ PERCHE' `periodo` E' UNA STRINGA `YYYY-MM` ═════════════════════════
 *
 * Perche' e' **l'identita'** dell'addebito, non una data. ⛔ Una `date` al primo
 * del mese inviterebbe a confrontarla con `>=` e `<`, e basterebbe un fuso
 * orario perche' il giro del primo alle 00:30 finisca nel mese prima. 💡 Una
 * stringa si confronta solo con `=`, che e' l'unico confronto giusto qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addebiti_mensili', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * ⚠️ **Il piano si conserva, non si rilegge.** Fra sei mesi quella
             * palestra potrebbe stare su un altro piano, o il prezzo a iscritto
             * potrebbe essere cambiato: una fattura deve restare spiegabile con
             * i numeri del giorno in cui e' stata emessa.
             */
            $table->foreignId('plan_id')->constrained();

            $table->string('periodo', 7);

            /*
             * 🚨 **Due conteggi, non uno.** `iscritti_contati` e' quanti erano
             * davvero; `iscritti_fatturati` e' quelli dopo il minimo. ⛔
             * Salvarne uno solo renderebbe impossibile rispondere alla domanda
             * che fara' ogni palestra piccola: *«ma io ne ho 40, perche' pago
             * per 100?»*.
             */
            $table->unsignedInteger('iscritti_contati');
            $table->unsignedInteger('iscritti_fatturati');

            /** Il prezzo unitario del giorno, in centesimi. */
            $table->unsignedInteger('prezzo_iscritto_cent');

            $table->unsignedInteger('importo_cent');

            /*
             * 💡 Gli stati sono pochi e dichiarati: `da_incassare` appena
             * calcolato, `incassato` quando Stripe ha la fattura,
             * `senza_cliente` quando non c'e' nessuno a cui mandarla, `fallito`
             * quando Stripe ha detto di no.
             */
            $table->string('stato', 20)->default('da_incassare');

            $table->string('stripe_invoice_id', 64)->nullable()->unique();

            /** L'errore di Stripe, per esteso, quando c'e' stato. */
            $table->text('errore')->nullable();

            $table->timestamp('incassato_il')->nullable();

            $table->timestamps();

            /*
             * ⛔ **Il vincolo, non un controllo nel codice.** Un `if` prima
             * dell'insert lascia aperta la finestra fra la lettura e la
             * scrittura; due giri in parallelo ci passano tutti e due.
             */
            $table->unique(['tenant_id', 'periodo']);

            $table->index(['stato', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addebiti_mensili');
    }
};
