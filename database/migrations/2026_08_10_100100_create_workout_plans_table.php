<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le schede di allenamento e i loro esercizi — B4.1.
 *
 * **`member_id` nullable = modello riutilizzabile.** Un trainer costruisce
 * «Full body principianti» una volta e la assegna a venti persone: senza questa
 * distinzione, o si duplica a mano venti volte, o si assegna la stessa riga a
 * venti iscritti e la prima modifica personalizzata li cambia tutti.
 *
 * **`reps` e' una stringa, non un intero.** «8-12», «cedimento», «max» sono
 * prescrizioni legittime che un trainer scrive davvero. Forzarle in un intero
 * significa perderle o inventarsi un numero — e nell'app storica l'unico modo
 * per esprimere un range era metterlo nelle note, dove nessuna funzione lo
 * leggeva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plans', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // NULL = modello della palestra, non ancora di nessuno.
            $table->foreignId('member_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 160);
            $table->text('notes')->nullable();

            $table->string('status', 16)->default('draft');
            $table->string('source', 16)->default('manual');

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // La query dell'app: «le mie schede pubblicate».
            $table->index(['tenant_id', 'member_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('plan_exercises', function (Blueprint $table): void {
            $table->id();

            // Nessun `tenant_id` qui: la riga non e' raggiungibile se non
            // attraverso la scheda, che ce l'ha. Vedi la nota in PlanExercise
            // sul perche' questo e' accettabile **solo** finche' nessuno carica
            // queste righe per id diretto.
            $table->foreignId('workout_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->unsignedSmallInteger('sets')->nullable();
            $table->string('reps', 32)->nullable();
            $table->unsignedSmallInteger('rest_sec')->nullable();
            $table->decimal('target_weight', 6, 2)->nullable();

            // Serie a tempo (plank, corsa): alternative alle ripetizioni.
            $table->unsignedSmallInteger('duration_sec')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['workout_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_exercises');
        Schema::dropIfExists('workout_plans');
    }
};
