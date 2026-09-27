<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⏳ **La memoria di «gliel'abbiamo detto»** — voce 62, 27/09/2026.
 *
 * ══ 📌 PERCHE' SERVE UNA COLONNA E NON BASTA UNA DATA ═════════════════════
 *
 * L'avviso «l'abbonamento sta per scadere» lo manda un giro notturno. ⛔ Senza
 * memoria, il giro lo rimanderebbe **ogni notte** per tutti i giorni in cui
 * l'abbonamento rientra nella finestra: sette notifiche identiche, cioè il modo
 * più rapido di far spegnere le notifiche a qualcuno.
 *
 * 🚨 E l'alternativa apparente — mandarlo **solo** il giorno in cui mancano
 * esattamente sette giorni — è peggio: una notte in cui il programmatore non
 * gira (server spento, coda ferma, `withoutOverlapping` che salta il turno)
 * significa che quell'avviso **non parte mai**. 💡 Una colonna rende il giro
 * *recuperabile*: chi non è stato avvisato lo è la notte dopo, e chi lo è già
 * stato non lo è due volte.
 *
 * 🔑 È la stessa forma di `plan_subscriptions.pagamento_fallito_il`, che esiste
 * per la stessa ragione — Stripe riprova per giorni, e l'avviso va mandato una
 * volta sola.
 *
 * ⚠️ **Si azzera al rinnovo**, non solo alla scadenza: un abbonamento che
 * ricomincia deve poter essere avvisato di nuovo il mese dopo. ⛔ Lasciandola
 * piena, la seconda scadenza passerebbe in silenzio — e sarebbe un difetto
 * visibile una volta al mese, quindi difficilissimo da collegare a questa
 * colonna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->timestamp('scadenza_avvisata_il')
                ->nullable()
                ->after('pagamento_fallito_il');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('scadenza_avvisata_il');
        });
    }
};
