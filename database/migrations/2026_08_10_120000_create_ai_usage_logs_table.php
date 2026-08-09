<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il contatore dei token — B6.4.
 *
 * 🚨 **Ogni chiamata AI scrive qui, riuscita o fallita.** Una richiesta rifiutata
 * dopo aver consumato token di input e' comunque costata: registrare solo i
 * successi produce un totale che non corrisponde alla fattura, e a quel punto
 * nessuno si fida piu' del contatore.
 *
 * `cost_millicents` e' **un intero**, in millesimi di centesimo. Non e' pedanteria:
 * questa colonna viene sommata su milioni di righe, e in virgola mobile la somma
 * di importi piccolissimi cambia a seconda dell'ordine degli addendi — il totale
 * del mese risulterebbe leggermente diverso a ogni ricalcolo, senza nessun errore
 * da nessuna parte.
 *
 * Il costo si **congela** al momento della chiamata invece di ricalcolarlo dai
 * listini: i prezzi cambiano, e un costo storico che si muove da solo quando si
 * aggiorna `config/ai.php` non e' un costo storico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('feature', 32);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);

            $table->unsignedBigInteger('cost_millicents')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);

            $table->boolean('success')->default(true);
            $table->string('error_code', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            // Le tre domande vere: quanto ha consumato questa palestra questo
            // mese, quanto costa questa funzione, e chi sta consumando troppo.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'feature']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
