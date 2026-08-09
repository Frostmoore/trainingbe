<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli allenamenti fatti e le misure del corpo — B4.2.
 *
 * 🚨 **`kcal_source` accanto a `kcal_burned`, sempre.** E' la coppia che tiene
 * in piedi la regola «il manuale batte la stima»: senza la seconda colonna, un
 * ricalcolo automatico non ha modo di sapere se sta sovrascrivendo una stima o
 * una correzione dell'utente, e lo scopre solo l'utente, quando il suo numero
 * sparisce.
 *
 * **`plan_id` nullable**: ci si allena anche senza scheda. Se lo si rendesse
 * obbligatorio, la prima palestra che non usa schede non potrebbe registrare
 * niente.
 *
 * `restrictOnDelete` su `plan_id`: una scheda con allenamenti gia' fatti non si
 * cancella, si archivia (`PlanStatus::Archived`). Il vincolo lo impone il
 * database e non solo il codice, perche' una `DELETE` scritta a mano in
 * manutenzione non passa dal codice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_plan_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->unsignedInteger('kcal_burned')->nullable();
            $table->string('kcal_source', 16)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Il diario dell'utente e le aggregazioni per giorno.
            $table->index(['tenant_id', 'user_id', 'started_at']);
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('session_sets', function (Blueprint $table): void {
            $table->id();

            // Come per plan_exercises: nessun tenant_id, la riga vive solo
            // dentro la sessione.
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('set_number');

            $table->unsignedSmallInteger('reps')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->unsignedSmallInteger('duration_sec')->nullable();
            $table->unsignedSmallInteger('rest_sec')->nullable();

            $table->timestamp('done_at')->nullable();

            $table->timestamps();

            // Una serie per numero, per esercizio, per sessione: l'app puo'
            // rimandare lo stesso salvataggio (rete mobile) e non deve
            // duplicare. E' un UPSERT, e il vincolo e' cio' che lo rende tale.
            $table->unique(['workout_session_id', 'exercise_id', 'set_number'], 'session_sets_unique');
        });

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

            // Una misura al giorno per persona: la seconda dello stesso giorno
            // e' una correzione, non un dato nuovo.
            $table->unique(['user_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_metrics');
        Schema::dropIfExists('session_sets');
        Schema::dropIfExists('workout_sessions');
    }
};
