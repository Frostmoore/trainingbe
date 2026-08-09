<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli import di schede da PDF — B7.1.
 *
 * 🚨 **Il PDF diventa una bozza, non una scheda.** `parsed_payload` conserva il
 * risultato del modello finche' una persona non lo guarda: nessun import va in
 * mano a un iscritto senza revisione. E' la regola piu' importante di questa
 * fase, e sta nello schema — `workout_plan_id` resta null finche' non si
 * pubblica — proprio perche' una regola scritta solo nel codice si aggira con un
 * percorso nuovo che qualcuno aggiunge fra sei mesi.
 *
 * `model_used` c'e' perche' esiste l'escalation (B7.5): quando un import viene
 * male bisogna sapere se e' stato letto dal modello economico o da quello caro,
 * altrimenti non si puo' decidere se abbassare la soglia o cambiare approccio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plan_imports', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();

            // Il PDF sta in medialibrary: qui solo il riferimento.
            $table->unsignedBigInteger('media_id')->nullable();

            $table->string('status', 16)->default('queued');

            $table->json('parsed_payload')->nullable();
            $table->string('model_used', 64)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('escalated')->default(false);

            $table->text('error')->nullable();

            $table->foreignId('workout_plan_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_plan_imports');
    }
};
