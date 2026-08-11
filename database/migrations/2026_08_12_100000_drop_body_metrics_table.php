<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peso e misure escono dal server — S5.6 di `plan_security_and_retention.md`.
 *
 * 🚨 **Decisione D9-bis**: *«tutti i dati sensibili devono sparire dal
 * server»*. Peso, massa grassa e circonferenze sono dati del corpo, e da qui in
 * avanti vivono **solo** nell'archivio locale dell'app (`misure_corpo` in
 * `ArchivioSalute`).
 *
 * ⚠️ **Questa migration cancella dati veri.** Al momento della scrittura lo
 * staging conteneva **3 righe** — i pesi registrati durante le prove. Non e' un
 * effetto collaterale: e' lo scopo.
 *
 * 🚨 **Le FOTO non si cancellano qui.** Sono file gestiti da
 * spatie/medialibrary, e vanno tolte con `Media::delete()` del modello — mai
 * con una `DELETE` sulla tabella, che lascerebbe le immagini sul disco senza
 * piu' niente che ne ricordi l'esistenza. Se ne occupa il comando di S5.6.
 *
 * ⚠️ Il `down()` ricrea la tabella **vuota**, mai i dati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('body_metrics');
    }

    public function down(): void
    {
        Schema::create('body_metrics', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('date');

            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('body_fat_pct', 4, 1)->nullable();
            $table->decimal('waist_cm', 5, 1)->nullable();
            $table->decimal('chest_cm', 5, 1)->nullable();
            $table->decimal('arm_cm', 4, 1)->nullable();
            $table->decimal('thigh_cm', 4, 1)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }
};
