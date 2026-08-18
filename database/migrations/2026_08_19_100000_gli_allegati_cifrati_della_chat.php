<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli allegati cifrati della chat — N14.1.
 *
 * ── 🚨 Cosa c'e' dentro, e cosa il server NON ha ───────────────────────────
 *
 * Il file su disco e' una foto cifrata con `secretstream`, e la chiave viaggia
 * **dentro il messaggio**, che e' gia' una busta `crypto_box` fra le due
 * persone. Il server tiene quindi un blob che non sa aprire **e** una chiave
 * che non sa leggere: stessa garanzia del testo, nessuna eccezione.
 *
 * ── ⚠️ Perche' i byte NON stanno qui, ma su disco ──────────────────────────
 *
 * Una foto da 1080x1080 cifrata sono 250-450 KB. Dentro la tabella
 * gonfierebbero il database e **tutti i suoi backup**, per file che vivono al
 * massimo ventiquattro ore. Su disco si cancellano davvero, e il database resta
 * un indice.
 *
 * ── 🚨 Niente `tenant_id`, come `messages` ─────────────────────────────────
 *
 * Si raggiunge solo attraverso la conversazione, che ce l'ha. La regola —
 * «queste righe si raggiungono solo attraverso il padre, mai per id» — e'
 * scritta per esteso in `PlanExercise` e vale identica qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegati_cifrati', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            /*
             * 🚨 **L'identificatore pubblico e' questo, non `id`.**
             *
             * ⚠️ Un id progressivo si indovina: chi ne conosce uno conosce
             * anche il precedente e il successivo, e potrebbe provarli a
             * migliaia. La policy li fermerebbe comunque — ma un identificatore
             * che non si puo' enumerare toglie il tentativo, invece di doverlo
             * respingere.
             */
            $table->string('token', 64)->unique();

            /*
             * 💡 Il peso serve a due cose: dirlo a chi scarica prima che
             * cominci, e poter misurare quanto spazio stanno occupando gli
             * allegati senza andare a contare i file sul disco.
             */
            $table->unsignedInteger('byte_totali');

            /*
             * 🚨 **La scadenza e' una colonna, non un calcolo.**
             *
             * ⚠️ Ricavandola da `created_at + 24h` dentro le query, cambiare la
             * durata domani vorrebbe dire cambiarla in ogni punto che la
             * calcola — e dimenticarne uno. Cosi' invece la regola sta in un
             * posto solo (`AllegatoCifrato::DURATA`) e la colonna e' un fatto.
             */
            $table->timestamp('scade_il')->index();

            $table->timestamps();

            // Il comando di pulizia scorre per scadenza; il resto per token.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegati_cifrati');
    }
};
