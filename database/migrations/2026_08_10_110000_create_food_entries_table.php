<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il diario alimentare — B5.1.
 *
 * 🚨 **`grams` e' la fonte di verita'; `qty` e `unit` sono presentazione.**
 * Ogni calcolo passa dai grammi. Tenere «2 cucchiai» come dato primario
 * significa rifare la conversione a ogni somma, con il risultato che due punti
 * del sistema che convertono in modo leggermente diverso danno due totali
 * diversi per lo stesso pasto — ed e' esattamente quello che succedeva nell'app
 * storica.
 *
 * **Perche' anche i valori `*_100`.** Gli assoluti (`kcal`, `protein`…) valgono
 * per la quantita' registrata; quelli per 100 g descrivono **l'alimento**.
 * Servono per ricalcolare quando l'utente corregge la quantita' senza rifare la
 * stima, e per riconoscere due voci come lo stesso cibo.
 *
 * `ai_raw` conserva la risposta grezza del modello: quando una stima e'
 * palesemente sbagliata, e' l'unico modo per capire se ha sbagliato il modello o
 * la nostra interpretazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('eaten_at');
            $table->string('meal', 24);

            $table->string('description', 255);

            $table->decimal('grams', 8, 2)->nullable();
            $table->decimal('qty', 8, 2)->nullable();
            $table->string('unit', 16)->nullable();

            $table->decimal('kcal', 8, 2)->nullable();
            $table->decimal('protein', 7, 2)->nullable();
            $table->decimal('carbs', 7, 2)->nullable();
            $table->decimal('fat', 7, 2)->nullable();

            $table->decimal('kcal_100', 7, 2)->nullable();
            $table->decimal('protein_100', 6, 2)->nullable();
            $table->decimal('carbs_100', 6, 2)->nullable();
            $table->decimal('fat_100', 6, 2)->nullable();

            $table->string('source', 16)->default('manual');
            $table->json('ai_raw')->nullable();

            // Se la voce viene da un piano, si sa da quale: e' cio' che rende
            // calcolabile l'aderenza.
            $table->foreignId('nutrition_plan_id')->nullable();

            $table->timestamps();

            // La query del diario: «tutto quello che ho mangiato oggi».
            $table->index(['user_id', 'eaten_at']);
            $table->index(['tenant_id', 'eaten_at']);
            $table->index(['user_id', 'meal', 'eaten_at']);
        });

        Schema::create('food_favorites', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('description', 255);

            // Un preferito puo' essere un singolo alimento oppure un pasto
            // intero (`is_meal`), che al richiamo produce piu' voci. Senza,
            // «la mia colazione» andrebbe ricomposta a mano ogni mattina — cioe'
            // la cosa che fa smettere di usare un diario alimentare.
            $table->boolean('is_meal')->default(false);
            $table->json('items')->nullable();

            $table->decimal('grams', 8, 2)->nullable();
            $table->decimal('qty', 8, 2)->nullable();
            $table->string('unit', 16)->nullable();

            $table->decimal('kcal', 8, 2)->nullable();
            $table->decimal('protein', 7, 2)->nullable();
            $table->decimal('carbs', 7, 2)->nullable();
            $table->decimal('fat', 7, 2)->nullable();

            $table->decimal('kcal_100', 7, 2)->nullable();
            $table->decimal('protein_100', 6, 2)->nullable();
            $table->decimal('carbs_100', 6, 2)->nullable();
            $table->decimal('fat_100', 6, 2)->nullable();

            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_meal']);
            $table->index(['user_id', 'times_used']);
        });

        Schema::create('daily_burns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->unsignedInteger('kcal');

            $table->timestamps();

            // Una riga al giorno: e' l'override manuale del giorno, non una
            // sequenza di eventi. Il vincolo rende il salvataggio un UPSERT.
            $table->unique(['user_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_burns');
        Schema::dropIfExists('food_favorites');
        Schema::dropIfExists('food_entries');
    }
};
