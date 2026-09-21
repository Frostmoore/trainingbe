<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📅 **Ogni quanto si paga un piano** — F9.2, 23/09/2026.
 *
 * 📌 Il committente, sulla pagina del piano: *«deve contenere la temporalità
 * (mese, settimana, giorno, anno...)»*.
 *
 * ⚠️ `mese` per tutti quelli che esistono: è quello che Stripe ha sempre
 * addebitato (`recurring.interval = month`, scritto fisso fino a oggi). Il
 * valore si legge in `Plan::intervalloStripe()` e `Plan::finePeriodo()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('intervallo', 10)->default('mese')->after('price_cents');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'intervallo')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('intervallo');
            });
        }
    }
};
