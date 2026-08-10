<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La quota AI passa da «per palestra» a «per iscritto» — C20.
 *
 * 🚨 **Il tetto per palestra era un pozzo comune, e i pozzi comuni si
 * prosciugano.** Con 2.000.000 di token per palestra e un utente medio che ne
 * consuma **551.000 al mese** (conti in `STIMA-COSTI-AI.md`), la quota bastava
 * per **tre o quattro persone**: la quarta che apriva l'app a meta' mese
 * trovava le funzioni AI spente, per colpa del consumo di qualcun altro. E chi
 * consumava di piu' non pagava nulla di piu': pagavano gli altri, restando
 * senza.
 *
 * Adesso il tetto e' **di ciascuno**, e si risolve in tre passi:
 *
 *   1. `users.ai_monthly_token_cap` — l'eccezione per una persona;
 *   2. `tenants.ai_monthly_tokens_per_member` — quanto la palestra da' a
 *      ognuno dei suoi;
 *   3. `ai.quota.default_monthly_tokens_per_user` — il default di sistema.
 *
 * In tutti e tre, **`0` vale «illimitato»** e `null` vale «non impostato, usa
 * il livello successivo». Sono due cose diverse e vanno restate tali: senza la
 * distinzione non si puo' sbloccare qualcuno lasciando il default a tutti gli
 * altri.
 *
 * ⚠️ **La colonna vecchia si rinomina, non si affianca.** Lasciare
 * `ai_monthly_token_cap` accanto alla nuova avrebbe creato una colonna che non
 * fa piu' niente — esattamente la trappola di `profiles.meal_hours` (C1.3), che
 * era rimasta li' a farsi salvare da un modulo mentre nessuna riga di codice la
 * leggeva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_token_cap')
                ->nullable()
                ->after('is_active');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('ai_monthly_token_cap', 'ai_monthly_tokens_per_member');
        });

        /*
         * 🚨 I valori vecchi si azzerano a `null`, e non e' pigrizia.
         *
         * Un «2.000.000 per la palestra» diventerebbe «2.000.000 **a testa**»:
         * lo stesso numero con un significato diverso, cioe' un tetto
         * cinquanta volte piu' alto di quello che qualcuno aveva scelto.
         * Meglio riportare tutti al default nuovo e lasciare che chi vuole un
         * valore proprio lo reimposti sapendo cosa significa.
         */
        DB::table('tenants')->update(['ai_monthly_tokens_per_member' => null]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('ai_monthly_tokens_per_member', 'ai_monthly_token_cap');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_token_cap');
        });
    }
};
