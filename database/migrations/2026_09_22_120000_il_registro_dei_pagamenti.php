<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💰 **Il registro dei pagamenti incassati** — 22/09/2026, fase F3.
 *
 * ══ ⛔ IL BUCO CHE CHIUDE ════════════════════════════════════════════════
 *
 * 📌 Il committente, sulla pagina di una palestra: *«Quanti soldi mi ha dato
 * dall'inizio»*.
 *
 * 🚨 **Non lo sapevamo.** `invoice.paid` allungava l'abbonamento e non scriveva
 * da nessuna parte **quanto** era stato pagato; i pacchetti di gettoni
 * lasciavano un movimento di gettoni, non di soldi; solo il piano a consumo
 * aveva una riga con un importo (`addebiti_mensili`).
 *
 * ⛔ Quindi l'unica risposta possibile sarebbe stata «guarda su Stripe», che
 * per un cruscotto vuol dire **non lo sappiamo**.
 *
 * ══ 💡 E SERVE TRE VOLTE ═════════════════════════════════════════════════
 *
 * | Dove | Cosa ci chiede |
 * |---|---|
 * | Pagina della palestra (F3) | quanto ha dato dall'inizio, e il grafico |
 * | Pagina della persona (F4) | *«quanti soldi ha speso dall'inizio (inclusi abbonamenti)»* |
 * | Fatture (F8) | ⚠️ **la riga da fatturare**: senza un registro, una fattura si emette a memoria |
 *
 * ══ 🚨 UNA RIGA SI SCRIVE QUANDO I SOLDI SONO ARRIVATI ═══════════════════
 *
 * ⛔ Non quando si apre un checkout, non quando si emette: **quando Stripe dice
 * che è stato pagato**. Un registro che conta gli incassi previsti è un
 * registro che non torna con il conto in banca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamenti_incassati', function (Blueprint $tabella): void {
            $tabella->id();

            /**
             * ⚠️ **Chi ha pagato**, non chi ne ha beneficiato: la palestra che
             * compra gettoni per un iscritto è lei che tira fuori i soldi.
             */
            $tabella->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $tabella->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /** `canone`, `gettoni`, `consumo`. */
            $tabella->string('tipo', 20);

            /** 💡 In centesimi, come tutto il resto dei soldi. */
            $tabella->unsignedInteger('importo_cent');

            /** Lo sconto applicato, se c'era: serve a spiegare l'importo. */
            $tabella->unsignedInteger('sconto_cent')->default(0);

            /** Il codice del piano o del taglio comprato, per leggibilità. */
            $tabella->string('cosa', 60)->nullable();

            /**
             * 🚨 L'identificativo di Stripe, e **unico**: è la difesa contro il
             * doppio conteggio. ⛔ Un webhook che arriva due volte non deve
             * raddoppiare l'incasso — e i webhook arrivano due volte.
             */
            $tabella->string('stripe_id', 80)->nullable()->unique();

            $tabella->timestamp('pagato_il');

            $tabella->timestamps();

            $tabella->index(['tenant_id', 'pagato_il']);
            $tabella->index(['user_id', 'pagato_il']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamenti_incassati');
    }
};
