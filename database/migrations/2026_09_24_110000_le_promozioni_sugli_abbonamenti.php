<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💳 **Le promozioni sugli abbonamenti** — 24/09/2026, P3 di `plan_promozioni.md`.
 *
 * - `plan_subscriptions.prezzo_bloccato`: il premio «prezzo bloccato» (§4.7 del
 *   piano). ⚠️ Oggi non cambia niente di visibile — Stripe rinnova già al prezzo
 *   del giorno dell'abbonamento — ma segna le righe da lasciar fuori il giorno in
 *   cui si porteranno i vecchi abbonati al listino nuovo.
 * - `addebiti_mensili.sconto_cent` e `promozioni`: lo sconto sul piano a consumo,
 *   scritto accanto all'importo. ⛔ Senza, la riga direbbe un importo più basso
 *   del conto `iscritti × prezzo` e nessuno saprebbe perché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $tabella): void {
            $tabella->boolean('prezzo_bloccato')->default(false);
        });

        Schema::table('addebiti_mensili', function (Blueprint $tabella): void {
            $tabella->unsignedInteger('sconto_cent')->default(0)->after('importo_cent');
            $tabella->string('promozioni', 255)->nullable()->after('sconto_cent');
        });
    }

    public function down(): void
    {
        Schema::table('addebiti_mensili', function (Blueprint $tabella): void {
            $tabella->dropColumn(['sconto_cent', 'promozioni']);
        });

        Schema::table('plan_subscriptions', function (Blueprint $tabella): void {
            $tabella->dropColumn('prezzo_bloccato');
        });
    }
};
