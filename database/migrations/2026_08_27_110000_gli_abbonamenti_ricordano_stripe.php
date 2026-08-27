<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli abbonamenti ricordano da quale abbonamento di Stripe vengono — 3b-H.9.
 *
 * ── 🚨 PERCHE' SERVE ──────────────────────────────────────────────────────
 *
 * Il primo pagamento arriva con `checkout.session.completed`, che porta con se'
 * i **metadata** che abbiamo scritto noi: da li' si sa a quale tenant
 * accreditare. ⛔ **I rinnovi no.** `invoice.paid` arriva un mese dopo e parla
 * solo di un `subscription` e di un `customer` di Stripe: senza queste due
 * colonne non c'e' modo di risalire a chi sia, e l'abbonamento scadrebbe **il
 * mese successivo** a chi sta regolarmente pagando.
 *
 * ⚠️ E si scoprirebbe **trenta giorni dopo**, quando l'AI smette di funzionare
 * a un cliente che ha l'addebito sulla carta.
 *
 * ── 💡 Perche' anche `stripe_customer_id` ─────────────────────────────────
 *
 * Serve al **portale di fatturazione**: per aprire la pagina dove si disdice o
 * si cambia carta, Stripe vuole il cliente, non l'abbonamento. Senza, l'unico
 * modo di disdire sarebbe scrivere a noi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            /*
             * ⚠️ `unique`, e non un indice qualunque: due righe con lo stesso
             * abbonamento di Stripe vorrebbero dire che un rinnovo ne prolunga
             * una a caso. 🚨 Meglio un errore di scrittura che due verita'.
             */
            $table->string('stripe_subscription_id', 64)->nullable()->unique()->after('plan_id');

            $table->string('stripe_customer_id', 64)->nullable()->index()->after('stripe_subscription_id');

            /*
             * Se alla scadenza si rinnova da solo.
             *
             * 🚨 **`true` di serie, e vale anche per le righe che esistono
             * gia'**: gli abbonamenti delle palestre non hanno Stripe e non
             * scadono (`ends_at` nullo). Mettere `false` li farebbe apparire
             * nell'app come «finisce il …», che sarebbe una bugia.
             *
             * 💡 Non decide se l'abbonamento e' attivo — quello lo dice
             * `ends_at` e basta. Serve **solo a dire la frase giusta**: «si
             * rinnova il 27 settembre» invece di «finisce il 27 settembre».
             */
            $table->boolean('rinnova')->default(true)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['stripe_subscription_id', 'stripe_customer_id', 'rinnova']);
        });
    }
};
