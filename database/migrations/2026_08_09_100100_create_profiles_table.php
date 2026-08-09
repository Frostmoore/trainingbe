<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I dati antropometrici e gli obiettivi dell'iscritto.
 *
 * Tabella separata da `users` e non colonne in piu' li': questi campi servono
 * solo agli iscritti (un super admin non ha un peso obiettivo), cambiano nel
 * tempo con logiche proprie, e tenerli fuori evita di caricarli su OGNI query
 * di autenticazione.
 *
 * Porta `tenant_id` anche se sarebbe derivabile da `user_id`: il global scope
 * filtra sulla tabella che interroghi, e senza la colonna una query diretta su
 * `profiles` non sarebbe limitata. La ridondanza qui e' una misura di sicurezza,
 * non una svista di normalizzazione (ADR-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->enum('sex', ['m', 'f'])->nullable();
            $table->date('birthdate')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();

            // sedentary | light | moderate | active | very_active
            $table->string('activity_level', 20)->nullable();
            // lose_weight | maintain | gain_muscle
            $table->string('goal', 20)->nullable();
            $table->decimal('target_weight_kg', 5, 2)->nullable();

            // Orari abituali dei pasti, per gli avvisi e per l'AI.
            // Sta in json perche' non finisce mai in una WHERE.
            $table->json('meal_hours')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
