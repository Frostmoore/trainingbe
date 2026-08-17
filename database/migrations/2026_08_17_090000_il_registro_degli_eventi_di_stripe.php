<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro degli eventi di Stripe — 17/08/2026.
 *
 * ── 🚨 Perche' questa tabella esiste prima ancora di vendere qualcosa ──────
 *
 * Perche' **Stripe rimanda lo stesso evento piu' volte**. Non e' un guasto: e'
 * il contratto. Se la nostra risposta tarda, o torna un 500, o si perde per
 * strada, Stripe riprova — anche per ore.
 *
 * ⚠️ Un accredito di gettoni senza questa tabella verrebbe eseguito **una volta
 * per ogni ritentativo**, e nessuno se ne accorgerebbe: il cliente e' contento,
 * i gettoni arrivano, il saldo cresce due volte. Lo si scopre dal bilancio.
 *
 * 💡 Per questo l'`event_id` e' **unico**: il secondo inserimento fallisce, e
 * quel fallimento e' esattamente la difesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * 🚨 L'identificativo dell'evento di Stripe (`evt_...`), **unico**.
             * E' l'intera difesa contro il doppio accredito.
             */
            $tabella->string('event_id')->unique();

            $tabella->string('type')->index();

            /*
             * Il corpo dell'evento, per intero.
             *
             * 💡 Si conserva **grezzo**: il giorno in cui un pagamento va
             * contestato, quello che conta e' cosa ha detto Stripe, non come
             * lo avevamo interpretato noi.
             *
             * ⚠️ Non contiene numeri di carta: Stripe non li manda mai.
             */
            $tabella->json('payload');

            /*
             * Quando e' stato **lavorato**, non quando e' arrivato.
             *
             * 🚨 `null` = ricevuto e non ancora gestito. Serve a distinguere
             * «mai visto» da «visto e ignorato di proposito», che oggi sono la
             * stessa cosa solo perche' non c'e' ancora nessun flusso.
             */
            $tabella->timestamp('processed_at')->nullable();

            $tabella->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
