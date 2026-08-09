<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cache del consiglio del giorno — B6.6.
 *
 * 🚨 **`context_hash` e' il meccanismo, e va mantenuto.**
 * L'hash e' l'md5 del contesto che si manda al modello, e **la data ne fa
 * parte**. Da questo discendono due cose, gratis:
 *  - a mezzanotte l'hash cambia, quindi il consiglio si rigenera **senza nessun
 *    cron dedicato**;
 *  - a ogni pasto o allenamento nuovo l'hash cambia, quindi il consiglio si
 *    aggiorna **quando ha senso aggiornarlo** e non a intervalli fissi.
 *
 * L'alternativa — un job notturno che rigenera tutto — costerebbe una chiamata
 * per ogni utente ogni notte, comprese quelle di chi non apre l'app da un mese.
 *
 * UNIQUE su (utente, data, tipo, hash): la stessa giornata puo' avere piu' righe
 * se il contesto e' cambiato, e questo e' voluto — permette di vedere come il
 * consiglio si e' evoluto durante la giornata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_advices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('kind', 32)->default('daily');
            $table->string('context_hash', 32);

            $table->text('body');
            $table->string('model', 64)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'date', 'kind', 'context_hash'], 'ai_advices_unique');
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_advices');
    }
};
