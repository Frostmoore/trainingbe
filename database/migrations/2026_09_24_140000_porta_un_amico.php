<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🤝 **Porta un amico** — 24/09/2026, P6 di `plan_promozioni.md` (opzione 27).
 *
 * - `users.codice_amico`: il codice del link da condividere. Nasce alla prima
 *   richiesta (`User::codiceAmico()`), non per tutti: un codice che nessuno ha
 *   chiesto è solo una colonna in più da proteggere.
 * - `users.invitato_da_id`: chi l'ha portato. ⚠️ `nullOnDelete`: se chi ha
 *   invitato cancella l'account, l'amico resta — senza padrino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            $tabella->string('codice_amico', 16)->nullable()->unique();
            $tabella->foreignId('invitato_da_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            $tabella->dropConstrainedForeignId('invitato_da_id');
            $tabella->dropUnique(['codice_amico']);
            $tabella->dropColumn('codice_amico');
        });
    }
};
