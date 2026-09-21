<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✋ **Un piano di listino modificato dal pannello non si riscrive più** —
 * F9.1, 23/09/2026.
 *
 * 🚨 `PlanSeeder` gira a **ogni deploy** con `updateOrCreate` da
 * `config/listino.php`: senza questo segno, un prezzo cambiato dal pannello
 * tornerebbe indietro al deploy successivo, in silenzio. ⚠️ Lo stesso difetto
 * che F2 aveva chiuso per i gettoni (`pacchetti_gettoni.modificato_a_mano`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('modificato_a_mano')->default(false)->after('cucito_a_mano');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'modificato_a_mano')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('modificato_a_mano');
            });
        }
    }
};
