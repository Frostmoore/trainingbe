<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ **Niente codici sconto** — 24/09/2026, dopo P8 di `plan_promozioni.md`.
 *
 * 📌 Il committente: *«il codice sconto non deve esistere, le promo si attivano
 * solo in automatico o con il link di attivazione»*.
 *
 * Le promozioni che si attivavano «con un codice» diventano «con un link»: il
 * codice resta nella riga (è quello che il link porta dentro), ma nessuno lo
 * scrive più a mano. E le assegnazioni nate da un codice si leggono «link».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('promozioni')->where('attivazione', 'codice')->update(['attivazione' => 'link']);
        DB::table('promozione_assegnazioni')->where('origine', 'codice')->update(['origine' => 'link']);
    }

    /** ⚠️ Non si torna indietro: quali erano «codice» non è più scritto da nessuna parte. */
    public function down(): void {}
};
