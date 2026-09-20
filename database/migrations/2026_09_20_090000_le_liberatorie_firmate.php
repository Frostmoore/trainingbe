<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✍️ **Le liberatorie firmate** — art. 28 GDPR, 20/09/2026.
 *
 * 📌 Il committente: *«Ruolo della palestra responsabile, dovrà firmare la
 * liberatoria specifica per le palestre (idem per i trainer)»*.
 *
 * 🚨 **Una riga per ogni firma, e non una colonna su `users`.** Il testo cambia
 * nel tempo: quello che conta non è «ha firmato», è **quale versione** ha
 * firmato e quando. Con una colonna sola, la prima revisione del testo
 * cancellerebbe la prova della firma precedente — cioè proprio la cosa che si
 * conserva per dimostrare l'atto.
 *
 * ⚠️ `ip` e `user_agent` stanno qui perché sono la **prova dell'atto**, non per
 * profilare: chi ha firmato, da dove, con cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liberatorie_firmate', function (Blueprint $tabella): void {
            $tabella->id();

            $tabella->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * 💡 Il tenant è **quello in cui si firmava**, e può essere nullo:
             * un trainer indipendente firma per sé, non per una palestra.
             * ⚠️ `nullOnDelete`: se la palestra sparisce, la firma resta — è un
             * atto avvenuto, e cancellarlo riscriverebbe la storia.
             */
            $tabella->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            /** `palestra` o `trainer`. */
            $tabella->string('tipo', 20);

            /** La versione del testo accettato: `palestra-v1`, `trainer-v1`… */
            $tabella->string('versione', 40);

            $tabella->timestamp('accettata_il');
            $tabella->string('ip', 45)->nullable();
            $tabella->string('user_agent', 255)->nullable();

            $tabella->timestamps();

            /*
             * 🚨 Una firma sola per persona e versione: ripremere il pulsante
             * due volte non deve creare due atti identici, che poi fanno
             * chiedere quale valga.
             */
            $tabella->unique(['user_id', 'tipo', 'versione']);
            $tabella->index(['user_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberatorie_firmate');
    }
};
