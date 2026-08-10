<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le misure dell'orologio diverse dal sonno — D3.
 *
 * 🚨 **Separata da `health_samples` e non un suo campo in più.** Quella tabella
 * modella *intervalli di fase del sonno* (`night`, `stage`); qui ci sono *punti
 * nel tempo con un valore*. Metterli insieme vorrebbe dire metà colonne sempre
 * nulle e uno `stage` privo di significato su metà delle righe — uno schema che
 * non si può leggere senza sapere in anticipo che tipo di riga si sta guardando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('source', 32)->default('health_connect');

            /** `hrv` · `resting_hr` · `hr` — vedi `App\Enums\HealthMetric`. */
            $table->string('metric', 24);

            $table->dateTime('measured_at');

            /**
             * Il giorno a cui la misura appartiene.
             *
             * ⚠️ Denormalizzato apposta: la dashboard chiede «il valore di
             * oggi» e «la media degli ultimi sette giorni», e senza questa
             * colonna ogni interrogazione dovrebbe ricavare la data da
             * `measured_at` in SQL — perdendo l'indice a ogni richiesta.
             */
            $table->date('day');

            $table->decimal('value', 8, 2);

            $table->timestamps();

            /*
             * 🚨 Un solo valore per (utente, sorgente, misura, istante).
             *
             * L'orologio rimanda gli stessi campioni a ogni sincronizzazione:
             * senza questo vincolo la stessa lettura entrerebbe dieci volte e
             * tirerebbe la media verso di sé. È lo stesso motivo per cui
             * `health_samples` ha il suo vincolo.
             */
            $table->unique(['user_id', 'source', 'metric', 'measured_at'], 'health_readings_unique');

            $table->index(['user_id', 'metric', 'day']);
            $table->index(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_readings');
    }
};
