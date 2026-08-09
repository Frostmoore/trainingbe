<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I campioni di sonno dall'orologio — B9.1.
 *
 * 🚨 **Il token di ingest e' PER UTENTE, non globale.**
 * Nell'app storica era uno solo per tutti: chiunque lo avesse — e stava dentro
 * un'app installata su ogni telefono — poteva scrivere dati di sonno per
 * chiunque altro. Qui vive su `users.health_ingest_token`, e revocarlo per una
 * persona non tocca nessun altro.
 *
 * **Dall'orologio si prende SOLO il sonno.** Le kcal restano manuali o stimate:
 * la sincronizzazione del watch arriva con ore di ritardo, e un numero che
 * l'utente guarda in tempo reale non puo' dipendere da una sincronizzazione
 * notturna — comparirebbe e cambierebbe da solo il giorno dopo.
 *
 * `UNIQUE(user_id, source, started_at)` rende l'ingest idempotente: l'orologio
 * rimanda gli stessi campioni a ogni sincronizzazione, e senza il vincolo una
 * notte di sonno risulterebbe di trenta ore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_samples', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('source', 32)->default('health_connect');

            // La notte a cui il campione appartiene: e' la chiave con cui si
            // aggrega, e va calcolata all'ingest perche' un campione delle 02:00
            // appartiene alla notte del giorno **precedente**.
            $table->date('night');

            // ⚠️ `dateTime` e non `timestamp`: MariaDB assegna a una seconda
            // colonna TIMESTAMP NOT NULL il default implicito '0000-00-00', che
            // in modalita' strict e' rifiutato — «Invalid default value for
            // 'ended_at'». Con DATETIME il problema non esiste, e qui non serve
            // nessuna delle proprieta' di TIMESTAMP: i valori arrivano gia'
            // convertiti nel fuso dell'applicazione dal controller.
            $table->dateTime('started_at');
            $table->dateTime('ended_at');

            // 1 = sveglio, 2 = leggero, 3 = profondo, 4 = REM
            $table->unsignedTinyInteger('stage');

            $table->timestamps();

            $table->unique(['user_id', 'source', 'started_at'], 'health_samples_unique');
            $table->index(['user_id', 'night']);
            $table->index(['tenant_id', 'night']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // Nullable: la stragrande maggioranza degli utenti non collega un
            // orologio, e generare un token per tutti significherebbe avere in
            // giro migliaia di credenziali che nessuno usa.
            $table->string('health_ingest_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['health_ingest_token']);
            $table->dropColumn('health_ingest_token');
        });

        Schema::dropIfExists('health_samples');
    }
};
