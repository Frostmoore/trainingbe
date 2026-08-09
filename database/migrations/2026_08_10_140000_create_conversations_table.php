<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La chat fra trainer e iscritto — B8.1.
 *
 * 🚨 **`UNIQUE(trainer_id, member_id)`: una sola conversazione per coppia.**
 * Senza il vincolo, due schermate aperte contemporaneamente — o due tocchi
 * ravvicinati su una rete lenta — creano due thread paralleli, e da quel momento
 * i due si scrivono in stanze diverse senza capire perche' l'altro non risponde.
 * E' un guasto che non da' nessun errore.
 *
 * `last_message_at` e' denormalizzato di proposito: l'elenco delle conversazioni
 * si ordina per ultimo messaggio, e ricavarlo con una sottoquery su `messages`
 * significa una scansione per ogni riga dell'elenco.
 *
 * `messages.read_at` sul singolo messaggio e non un contatore sulla
 * conversazione: «da dove riprendo a leggere» e' una domanda che ha senso solo
 * per messaggio, e un contatore si disallinea alla prima cancellazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->unique(['trainer_id', 'member_id']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');
            $table->unsignedBigInteger('media_id')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Il thread si legge dal fondo, a pagine: e' l'indice che serve.
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('token', 512);
            $table->string('platform', 16);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Lo stesso telefono non deve comparire due volte: le notifiche
            // arriverebbero doppie, che e' il modo piu' rapido per far
            // disattivare i permessi all'utente.
            $table->unique(['user_id', 'token'], 'device_tokens_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
