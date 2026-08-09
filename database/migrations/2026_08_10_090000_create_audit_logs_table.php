<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro delle azioni che, se avvenissero di nascosto, non si potrebbero
 * piu' ricostruire.
 *
 * Non e' un log applicativo: nei log di sistema si scrive tutto e non si cerca
 * niente. Qui finiscono **soltanto** le azioni dell'elenco in `AuditAction`,
 * quelle per cui un giorno qualcuno chiedera' «chi e' entrato nel mio account?»
 * o «chi ha sospeso la palestra?» e la risposta deve esistere.
 *
 * Scelte che sembrano dettagli e non lo sono:
 *
 * - **`tenant_id` nullable con `nullOnDelete`**: se la palestra viene
 *   cancellata la traccia resta. Un registro che sparisce insieme all'oggetto
 *   di cui documenta la cancellazione non e' un registro.
 * - **`actor_id` nullable con `nullOnDelete`**: idem per chi ha agito. Restano
 *   `actor_label` e `actor_email`, copiati al momento del fatto: servono
 *   proprio quando l'utente non c'e' piu'.
 * - **nessun `updated_at`**: una riga di audit non si modifica. Non avere la
 *   colonna e' piu' forte di avere la regola di non toccarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Copie al momento del fatto: sopravvivono alla cancellazione
            // dell'utente e rendono leggibile il registro senza join.
            $table->string('actor_label')->nullable();
            $table->string('actor_email')->nullable();

            $table->string('action', 64);

            // Su cosa si e' agito. Morph nullable: alcune azioni non hanno un
            // oggetto (per esempio un accesso amministrativo generico).
            $table->nullableMorphs('auditable');

            $table->json('payload')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable()->index();

            // Le due domande vere: «cosa e' successo in questa palestra» e
            // «tutte le volte che e' successa questa cosa».
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
