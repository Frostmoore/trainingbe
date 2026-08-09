<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chi segue chi.
 *
 * E' la tabella su cui poggia lo scoping del trainer: un trainer vede e modifica
 * SOLO le schede e i piani degli iscritti che compaiono qui. Senza questa
 * relazione, «trainer» significherebbe «puo' toccare tutti gli iscritti della
 * palestra», che non e' quello che serve.
 *
 * Molti-a-molti di proposito: un iscritto puo' avere un trainer per la sala pesi
 * e un altro per la nutrizione, e un trainer segue piu' iscritti.
 *
 * `assigned_by` e' nullable con `nullOnDelete`: se l'amministratore che ha fatto
 * l'assegnazione viene cancellato, l'assegnazione deve sopravvivere — si perde
 * il "chi", non il "cosa". Cancellarla a cascata scollegherebbe un iscritto dal
 * suo trainer per un motivo che non c'entra nulla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_member', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Assegnare due volte la stessa coppia non e' un errore da gestire
            // nel codice: e' il database a doverlo impedire.
            $table->unique(['trainer_id', 'member_id']);

            // Le due direzioni della domanda: «i miei iscritti» e «i miei trainer».
            $table->index(['tenant_id', 'trainer_id']);
            $table->index(['tenant_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_member');
    }
};
