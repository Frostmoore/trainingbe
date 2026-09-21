<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧾 Le fatture — fase F8, 23/09/2026.
 *
 * ══ 🗄️ DOVE VIVE · VA NEL BACKUP · QUANDO MUORE ══════════════════════════
 *
 * - **Dove vive**: tutta qui, **anche l'XML** (`xml`, testo). ⛔ Non su disco:
 *   sul server il backup è il dump del database, e un file accanto non ci
 *   finirebbe. Una fattura persa non si rifà.
 * - **Nel backup**: sì, col database.
 * - **Quando muore**: **mai da sola**. Si conserva dieci anni (art. 2220 c.c.)
 *   anche se la palestra se ne va: per questo `tenant_id` diventa `null` invece
 *   di cancellare la riga, e il cliente è **fotografato** in `cessionario`.
 *
 * ══ 🚨 PERCHÉ IL CLIENTE È UNA FOTOGRAFIA ═════════════════════════════════
 *
 * Una fattura emessa **non cambia più**. Se domani la palestra corregge la
 * ragione sociale, la fattura di ieri deve continuare a dire quella di ieri:
 * leggerla dalla tabella `tenants` la riscriverebbe in silenzio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fatture', function (Blueprint $table): void {
            $table->id();

            // ⚠️ nullOnDelete, non cascade: la fattura sopravvive alla palestra.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            // Il pagamento da cui nasce. 🚨 unique: un incasso, una fattura —
            // i webhook arrivano due volte.
            $table->foreignId('pagamento_incassato_id')->nullable()->unique()
                ->constrained('pagamenti_incassati')->nullOnDelete();

            /*
             * `in_attesa`: il pagamento c'è, ma alla palestra mancano i dati
             * per fatturare. Niente numero finché non si emette: un numero
             * assegnato a una fattura che non esiste lascerebbe un buco nella
             * serie.
             */
            $table->string('stato', 16)->default('in_attesa')->index();

            $table->unsignedSmallInteger('anno')->nullable();
            $table->unsignedInteger('numero')->nullable();
            $table->date('data')->nullable();
            $table->string('sezionale', 10)->nullable();

            // 🚨 La serie non ha doppioni: il vincolo sta nel database, non
            // nella speranza che due emissioni non si incrocino.
            $table->unique(['anno', 'sezionale', 'numero']);

            $table->string('descrizione', 500);
            $table->unsignedInteger('imponibile_cent');
            $table->unsignedInteger('bollo_cent')->default(0);
            $table->unsignedInteger('totale_cent');

            // Il cliente, com'era al momento dell'emissione.
            $table->json('cessionario')->nullable();

            // L'XML emesso, così com'è. `longText` per non dover mai pensare
            // a una riga descrittiva troppo lunga.
            $table->longText('xml')->nullable();
            $table->string('progressivo_invio', 10)->nullable()->unique();

            $table->timestamp('emessa_il')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stato']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatture');
    }
};
