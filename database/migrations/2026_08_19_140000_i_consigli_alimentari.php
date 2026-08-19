<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consigli alimentari e piani veri, distinti — N19.1.
 *
 * ── 🚨 Perche' una colonna e non il ruolo di chi l'ha scritto ──────────────
 *
 * Sarebbe bastato guardare `creator->role`. ⚠️ Ma un ruolo **cambia**: il
 * giorno che un trainer diventa anche nutrizionista, o smette di esserlo, tutti
 * i documenti che ha scritto cambierebbero natura **retroattivamente** — e un
 * elenco di consigli diventerebbe una dieta senza che nessuno abbia toccato
 * niente.
 *
 * 💡 Cosa **e'** un documento si decide quando nasce, e non si muove piu'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_plans', function (Blueprint $table): void {
            /*
             * ⚠️ Il default e' `piano`, non `consigli`, ed e' voluto: le righe
             * che esistono gia' sono state scritte quando i piani con i grammi
             * erano l'unica cosa che c'era. Chiamarle «consigli» sarebbe
             * riscrivere la storia — e per giunta nel verso che ci fa comodo.
             */
            $table->string('tipo', 16)->default('piano')->after('name');
        });

        /*
         * 🚨 **Nessuna conversione dei dati esistenti.**
         *
         * Non ci sono utenti in produzione, quindi non c'e' niente da
         * convertire. ⚠️ Se un giorno ce ne fossero, la domanda non sarebbe
         * tecnica: sarebbe se quei piani possano restare in mano a chi li ha
         * scritti — e la risposta la darebbe un legale, non una migrazione.
         */
        DB::table('nutrition_plans')->whereNull('tipo')->update(['tipo' => 'piano']);
    }

    public function down(): void
    {
        Schema::table('nutrition_plans', function (Blueprint $table): void {
            $table->dropColumn('tipo');
        });
    }
};
