<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💳 **Il pagamento che non è andato** — §9.4, 18/09/2026.
 *
 * ══ 🚨 COSA SUCCEDEVA ═════════════════════════════════════════════════════
 *
 * ⛔ `invoice.payment_failed` **non era gestito**. Un rinnovo con la carta
 * scaduta: Stripe riprova per qualche giorno, e nel frattempo **da noi non
 * cambiava niente** — nessuna traccia, e nessun modo per chi paga di saperlo
 * prima che il servizio si spegnesse.
 *
 * 💡 Si segna **quando** è fallito, e si toglie al primo pagamento riuscito. Chi
 * paga lo vede nell'app e nel pannello, e ha il tempo di cambiare carta mentre
 * Stripe riprova — invece di scoprirlo a servizio già spento.
 *
 * ⚠️ **Non spegne niente da solo**, ed è voluto: Stripe riprova, e la decisione
 * finale — disdire, o tenere in sospeso — arriva con `customer.subscription.updated`
 * o `.deleted`, che sono già gestiti. Spegnere qui al primo rifiuto toglierebbe il
 * servizio a chi ha solo la carta temporaneamente vuota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->timestamp('pagamento_fallito_il')->nullable()->after('restavano_giorni');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('pagamento_fallito_il');
        });
    }
};
