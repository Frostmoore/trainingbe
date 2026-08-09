<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La libreria esercizi — B4.1.
 *
 * 🚨 **`tenant_id` nullable: NULL vuol dire «della piattaforma».**
 * Ogni palestra vede i propri esercizi **piu'** quelli globali, e per questo il
 * modello usa `BelongsToTenantOrGlobal`. La motivazione e' economica prima che
 * tecnica: nessuna palestra vuole ricreare «Panca piana» al primo accesso, e
 * senza una base condivisa l'onboarding di un cliente comincia con due ore di
 * data entry.
 *
 * `is_custom` sembra ridondante rispetto a `tenant_id IS NOT NULL`, e non lo e':
 * un esercizio creato da `ExerciseMatcher` durante un import PDF (B7.3) e'
 * tecnicamente della palestra ma **non l'ha scelto nessuno** — e' un nome che
 * non e' stato riconosciuto. Distinguere i due casi permette di proporre al
 * trainer la pulizia periodica della propria libreria.
 *
 * `slug_normalized` e' il nome ridotto a forma canonica (minuscolo, senza
 * accenti, senza punteggiatura). Non e' una comodita': e' la colonna su cui il
 * matcher cerca, e senza un indice su quella forma la riconciliazione di un PDF
 * da cinquanta esercizi fa cinquanta scansioni complete della tabella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 160);
            $table->string('slug_normalized', 160);

            $table->string('muscle_group', 32)->nullable();
            $table->string('equipment', 64)->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_custom')->default(false);

            // Il valore MET dell'esercizio, quando lo si conosce: e' quello che
            // usa WorkoutCalorieService (B4.3) al posto della costante generica.
            $table->decimal('met', 4, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'muscle_group']);

            // Il matcher cerca **prima** fra i globali e poi fra quelli della
            // palestra: due indici distinti servono a rendere entrambe le
            // ricerche una lettura di indice e non una scansione.
            $table->index(['slug_normalized', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
