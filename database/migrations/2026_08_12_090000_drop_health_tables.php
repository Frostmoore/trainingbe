<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I dati del sensore escono dal server — S1.6 di `plan_security_and_retention.md`.
 *
 * 🚨 **Sonno, HRV e battito restano sul telefono di chi li produce** (decisione
 * D9 di `todo-2026-08-11.md`). Il server non li riceve piu', non li conserva e
 * non li manda a nessun modello.
 *
 * Il guadagno principale non e' lo spazio: e' che **il trasferimento di dati
 * sanitari verso gli Stati Uniti sparisce alla radice**. Il consiglio del giorno
 * li spediva ad Anthropic o OpenAI a ogni chiamata; adesso non ce li ha.
 *
 * ⚠️ **Il `down()` ricrea le tabelle VUOTE, mai i dati.** Un rollback che
 * resuscita dati sanitari e' peggio di un rollback che fallisce: e' il motivo
 * per cui questa migration non fa nessun backup prima di cancellare.
 *
 * 📸 Sullo staging, al momento dell'esecuzione: `health_readings` = 0 righe,
 * `health_samples` = 0 righe. Il ponte non e' mai esistito, quindi **non si sta
 * perdendo nessun dato reale** — si sta togliendo la possibilita' di
 * accumularne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('health_samples');
        Schema::dropIfExists('health_readings');

        Schema::table('users', function (Blueprint $table): void {
            /*
             * ⚠️ `dropUnique` PRIMA di `dropColumn`.
             *
             * Su MariaDB 10.4 togliere una colonna che porta un indice univoco
             * senza toglierlo prima fallisce, e il messaggio non dice quale sia
             * il problema: parla di una chiave che non trova.
             */
            $table->dropUnique(['health_ingest_token']);
            $table->dropColumn('health_ingest_token');
        });
    }

    public function down(): void
    {
        /*
         * 🚨 Le tabelle tornano VUOTE, e la colonna torna a `null` per tutti.
         *
         * Questo `down()` serve a poter tornare indietro con lo schema, non con
         * i dati. Se qualcuno avesse bisogno dei dati, la risposta e' che non
         * ci sono piu' — ed e' esattamente il punto di questa migration.
         */
        Schema::create('health_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->default('health_connect');
            $table->string('metric', 24);
            $table->dateTime('measured_at');
            $table->date('day');
            $table->decimal('value', 8, 2);
            $table->timestamps();

            $table->unique(['user_id', 'source', 'metric', 'measured_at'], 'health_readings_unique');
            $table->index(['user_id', 'metric', 'day']);
            $table->index(['tenant_id', 'day']);
        });

        Schema::create('health_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->default('health_connect');
            $table->date('night');
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedTinyInteger('stage');
            $table->timestamps();

            $table->unique(['user_id', 'source', 'started_at'], 'health_samples_unique');
            $table->index(['user_id', 'night']);
            $table->index(['tenant_id', 'night']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('health_ingest_token', 64)->nullable()->unique()->after('remember_token');
        });
    }
};
