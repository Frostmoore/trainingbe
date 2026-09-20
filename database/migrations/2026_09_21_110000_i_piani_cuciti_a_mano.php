<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✂️ **I piani cuciti a mano** — 21/09/2026.
 *
 * 📌 Il committente: *«creami la possibilità di creare nuovi abbonamenti per
 * palestre con il numero di trainers che voglio io»*.
 *
 * ── 🚨 Perché una colonna e non «quelli non pubblici» ─────────────────────
 *
 * Perché `is_public = false` **non** vuol dire «cucito a mano»: ce l'hanno
 * anche i piani **ritirati** (`trainer_pro`, `gym`, `gym_5`…), che stanno in
 * tabella solo perché qualcuno ce li ha ancora. Confondere le due cose
 * vorrebbe dire aprire alla modifica dal pannello dei piani che `PlanSeeder`
 * riscrive a ogni deploy: le modifiche sparirebbero, in silenzio, e la volta
 * dopo qualcuno le rifarebbe.
 *
 * ⚠️ **È questa colonna che dice chi comanda su una riga**:
 *
 * | `cucito_a_mano` | Chi la scrive | Il pannello |
 * |---|---|---|
 * | `false` | `PlanSeeder`, da `config/listino.php` | sola lettura |
 * | `true` | una persona, dal pannello `/god` | si modifica |
 *
 * 💡 Il default è `false` proprio perché le righe che esistono oggi vengono
 * tutte dal seeder: un default `true` le renderebbe modificabili, cioè
 * modificabili **e** sovrascritte al deploy successivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->boolean('cucito_a_mano')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->dropColumn('cucito_a_mano');
        });
    }
};
