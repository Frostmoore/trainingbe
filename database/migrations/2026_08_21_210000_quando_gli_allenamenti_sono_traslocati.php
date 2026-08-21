<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando questa persona ha portato i suoi allenamenti sul telefono — FASE 11.3.
 *
 * == 🚨 UNA DATA, NON UN BOOLEANO ==========================================
 *
 * ⚠️ Un `boolean` direbbe *se*, e non *quando*. 🚨 E il *quando* serve davvero:
 * la migrazione che lascia cadere le tabelle (11.6) deve poter dire «l'ultimo
 * ha confermato tre settimane fa, si puo' procedere» — con un booleano quella
 * frase non si puo' nemmeno formulare.
 *
 * 💡 `null` = non ha ancora migrato, ed e' lo stato di partenza di tutti.
 *
 * -- ⛔ Perche' non e' `fillable` ------------------------------------------
 *
 * Non deve poter arrivare da nessuna richiesta dell'utente: e' il server a
 * scriverla, e **solo dopo** aver verificato i conteggi
 * (`MigrazioneAllenamentiController::fatta()`). Una richiesta che se la scrive
 * da sola sarebbe un modo per farsi cancellare i propri dati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('workouts_migrated_at')->nullable()->after('last_login_at');

            /*
             * 💡 L'indice serve a **una** domanda, che pero' e' quella
             * pericolosa: «esiste ancora qualcuno che non ha migrato?». La
             * migrazione di 11.6 la fa prima di lasciar cadere le tabelle, e
             * senza indice sarebbe una scansione completa della tabella utenti
             * dentro una migrazione — cioe' proprio dove non si vuole.
             */
            $table->index('workouts_migrated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['workouts_migrated_at']);
            $table->dropColumn('workouts_migrated_at');
        });
    }
};
