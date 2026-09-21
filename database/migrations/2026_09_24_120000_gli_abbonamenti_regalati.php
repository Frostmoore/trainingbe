<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎀 **Abbonamenti regalati, upgrade e posti extra** — 24/09/2026, P4 di
 * `plan_promozioni.md`.
 *
 * - `plan_subscriptions.promozione_assegnazione_id`: da quale promozione viene
 *   una riga regalata (abbonamento o upgrade). Senza, un abbonamento senza
 *   Stripe sarebbe indistinguibile da un «Regala abbonamento» fatto a mano.
 * - `tenants.posti_extra` · `posti_trainer_extra` · `posti_extra_fino_al`: i
 *   posti in più **stanno sullo spazio**, non sull'abbonamento.
 *   ⚠️ Il piano (§5.7) li metteva su `plan_subscriptions`: ⛔ ma una palestra che
 *   cambia piano chiude la riga vecchia e ne apre una nuova, e i posti regalati
 *   sparirebbero al primo cambio. Sullo spazio restano finché scadono.
 * - `promozione_premi.posti_di`: `iscritti` (o allievi) \| `trainer`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $tabella): void {
            $tabella->foreignId('promozione_assegnazione_id')->nullable()
                ->constrained('promozione_assegnazioni')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->unsignedSmallInteger('posti_extra')->default(0);
            $tabella->unsignedSmallInteger('posti_trainer_extra')->default(0);
            $tabella->timestamp('posti_extra_fino_al')->nullable();
        });

        Schema::table('promozione_premi', function (Blueprint $tabella): void {
            $tabella->string('posti_di', 20)->nullable()->after('posti');
        });
    }

    public function down(): void
    {
        Schema::table('promozione_premi', function (Blueprint $tabella): void {
            $tabella->dropColumn('posti_di');
        });

        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->dropColumn(['posti_extra', 'posti_trainer_extra', 'posti_extra_fino_al']);
        });

        Schema::table('plan_subscriptions', function (Blueprint $tabella): void {
            $tabella->dropConstrainedForeignId('promozione_assegnazione_id');
        });
    }
};
