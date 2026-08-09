<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nome utente, alternativo all'email per accedere.
 *
 * 🚨 **Unico su TUTTA la piattaforma, non per palestra** — al contrario
 * dell'email.
 *
 * Il motivo è che al login dei pannelli non c'è nessun `join_code` che dica di
 * quale palestra si tratta: l'utente digita un identificativo e basta. Se lo
 * stesso nome esistesse in due palestre, il sistema dovrebbe indovinare — e
 * `attempt()` ne pescherebbe uno qualsiasi, in silenzio.
 *
 * L'email resta unica per palestra perché è un dato che la persona **ha già**,
 * e la stessa persona deve poter frequentare due palestre. Il nome utente
 * invece **si sceglie**, quindi si può chiedere che sia libero.
 *
 * Nullable: gli utenti esistenti non ne hanno uno, e per gli iscritti resta
 * facoltativo — dall'app entrano col codice palestra e l'email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
