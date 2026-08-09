<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il piano alimentare — B5.2.
 *
 * 🚨 **Perche' e' separato dal diario.** Il piano e' *prescrizione*, il diario
 * e' *consuntivo*. Metterli nella stessa tabella sembra economico e poi rende
 * impossibile la domanda che vale davvero per la palestra: «quanto ha aderito al
 * piano?». Con due tabelle, `food_entries.source = 'plan'` collega consuntivo a
 * prescrizione e l'aderenza diventa una join.
 *
 * Tre livelli — piano, pasti, alimenti — e non un JSON unico, perche' un
 * trainer modifica un singolo alimento dentro un singolo pasto, e su un JSON
 * quella modifica e' una riscrittura completa: due trainer che lavorano sullo
 * stesso piano si sovrascriverebbero a vicenda senza accorgersene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_plans', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 160);
            $table->text('notes')->nullable();

            $table->unsignedInteger('target_kcal')->nullable();
            $table->unsignedInteger('target_protein_g')->nullable();
            $table->unsignedInteger('target_carbs_g')->nullable();
            $table->unsignedInteger('target_fat_g')->nullable();

            $table->string('status', 16)->default('draft');

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'member_id', 'status']);
        });

        Schema::create('nutrition_plan_meals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('nutrition_plan_id')->constrained()->cascadeOnDelete();

            $table->string('meal', 24);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 160)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['nutrition_plan_id', 'position']);
        });

        Schema::create('nutrition_plan_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('nutrition_plan_meal_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description', 255);

            $table->decimal('qty', 8, 2)->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('grams', 8, 2)->nullable();

            $table->decimal('kcal', 8, 2)->nullable();
            $table->decimal('protein', 7, 2)->nullable();
            $table->decimal('carbs', 7, 2)->nullable();
            $table->decimal('fat', 7, 2)->nullable();

            // Alternative ammesse allo stesso alimento: «120 g di pollo oppure
            // 150 g di merluzzo». Senza, l'unico modo per esprimerlo e' la nota
            // libera, che nessun calcolo legge.
            $table->text('alternatives')->nullable();

            $table->timestamps();

            $table->index(['nutrition_plan_meal_id', 'position']);
        });

        // La chiave esterna su food_entries si aggiunge adesso, che la tabella
        // di destinazione esiste: la colonna era gia' stata creata nella
        // migration precedente per tenerla vicino al resto del diario.
        Schema::table('food_entries', function (Blueprint $table): void {
            $table->foreign('nutrition_plan_id')->references('id')->on('nutrition_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('food_entries', function (Blueprint $table): void {
            $table->dropForeign(['nutrition_plan_id']);
        });

        Schema::dropIfExists('nutrition_plan_items');
        Schema::dropIfExists('nutrition_plan_meals');
        Schema::dropIfExists('nutrition_plans');
    }
};
