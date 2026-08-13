<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Via i token e le alternative in JSON — G2.5 e la coda di G4.5.
 *
 * ── 🚨 Perche' si puo' fare adesso, e non e' ottimismo ────────────────────
 *
 * Entrambe le colonne erano rimaste per la stessa ragione: **se la conversione
 * avesse sbagliato, il dato vecchio era l'unico modo per rifarla**.
 *
 * ✅ Il 13/08/2026 lo staging e' stato **riletto**, e la risposta e' che non
 * c'era niente da convertire:
 *
 * | | Righe |
 * |---|---|
 * | `plans` con `ai_monthly_tokens_per_member` non null | **0** su 5 |
 * | `users` con `ai_monthly_token_cap` non null | **0** su 11 |
 * | `tenants` con `ai_monthly_tokens_per_member` non null | **0** su 3 |
 * | `nutrition_plan_items` con `alternatives` non vuoto | **0** su 0 |
 *
 * 💡 Quindi il dato vecchio **non esiste**: non c'e' niente da poter rifare, e
 * tenere le colonne non protegge da niente. Proteggeva da un rischio che i
 * numeri dicono non essersi mai materializzato.
 *
 * ── ⚠️ E perche' toglierle e' meglio che lasciarle ────────────────────────
 *
 * 🚨 Una colonna che **decideva la fatturazione** e non la decide piu', ancora
 * in tabella, e' il tipo di cosa che qualcuno rimette in una query «perche'
 * c'era». Il sintomo sarebbe un tetto applicato in token a una quota contata in
 * chiamate: numeri di due unita' diverse confrontati **senza dare errore**.
 *
 * ── 🚨 Cosa NON si tocca ──────────────────────────────────────────────────
 *
 * Le migrazioni `G1` e `G2` che facevano la conversione **restano come sono**,
 * con dentro la costante `1730` token/chiamata — che il 13/08 e' stata
 * rimisurata a **2.659** e quindi era sbagliata.
 *
 * ⚠️ Una migrazione gia' applicata e' **storia**: cambiarne una costante sarebbe
 * una bugia su cio' che ha girato davvero. E il numero sbagliato non ha
 * prodotto nessun dato sbagliato, perche' non ha convertito niente. Il numero
 * giusto sta in `STIMA-COSTI-AI.md` §4, dove serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * 🚨 **Il controllo prima di cancellare.** Il ragionamento qui sopra
         * poggia su una lettura fatta a mano su un ambiente: se in un altro —
         * un'installazione futura, un ripristino — quelle colonne fossero
         * popolate, cancellarle butterebbe via un dato che serve.
         *
         * 💡 Meglio una migrazione che si ferma con un messaggio leggibile che
         * una che porta via i tetti di qualcuno in silenzio.
         */
        $residui = DB::table('users')->whereNotNull('ai_monthly_token_cap')->count()
            + DB::table('tenants')->whereNotNull('ai_monthly_tokens_per_member')->count()
            + DB::table('plans')->whereNotNull('ai_monthly_tokens_per_member')->count();

        if ($residui > 0) {
            throw new RuntimeException(
                "G2.5: ci sono ancora {$residui} righe con un tetto in token. "
                .'Convertirle prima di togliere le colonne: il dato non e\' recuperabile dopo.',
            );
        }

        $alternative = DB::table('nutrition_plan_items')
            ->whereNotNull('alternatives')
            ->where('alternatives', '!=', '')
            ->where('alternatives', '!=', '[]')
            ->count();

        if ($alternative > 0) {
            throw new RuntimeException(
                "G4.5: ci sono ancora {$alternative} alimenti con alternative in JSON non convertite.",
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_token_cap');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_tokens_per_member');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_tokens_per_member');
        });

        Schema::table('nutrition_plan_items', function (Blueprint $table): void {
            $table->dropColumn('alternatives');
        });
    }

    /**
     * ⚠️ **Il `down()` ricrea le colonne vuote, e non puo' fare di meglio.**
     *
     * I valori non ci sono piu': tornare indietro rimette la forma, non il
     * contenuto. E' scritto qui perche' chi lancia un rollback sappia cosa
     * ottiene — una struttura, non uno stato.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_token_cap')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_tokens_per_member')->nullable();
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_tokens_per_member')->nullable();
        });

        Schema::table('nutrition_plan_items', function (Blueprint $table): void {
            $table->text('alternatives')->nullable();
        });
    }
};
