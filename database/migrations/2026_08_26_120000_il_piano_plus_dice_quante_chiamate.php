<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il piano `plus` dice quante chiamate concede — 3b-H, 26/08/2026.
 *
 * ── 🚨 PERCHE' SERVE UNA MIGRATION E NON BASTA IL SEEDER ──────────────────
 *
 * Perche' i piani nel database **non li ha messi il seeder**: li inserisce la
 * migration `2026_08_13_210000_crea_i_piani_e_gli_abbonamenti`, e quella non
 * scrive `ai_monthly_calls_per_member`. ⛔ Risultato: sul database vero quel
 * campo e' `null`, `QuotaAi::capFor()` scende fino al default di sistema, e il
 * numero che comandava era quello — **non** quello del piano.
 *
 * ⚠️ Cambiare solo `PlanSeeder` avrebbe corretto una copia che nessuno legge.
 *
 * 💡 `update` e non `updateOrCreate`: se il piano non c'e', non lo si inventa
 * qui — vorrebbe dire che la migration dei piani non e' passata, e crearlo di
 * straforo nasconderebbe un problema piu' grande.
 */
return new class extends Migration
{
    private const CHIAMATE = 150;

    private const CHIAMATE_FOTO = 15;

    public function up(): void
    {
        DB::table('plans')->where('code', 'plus')->update([
            'ai_monthly_calls_per_member' => self::CHIAMATE,
            'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
            'updated_at' => now(),
        ]);
    }

    /**
     * ⚠️ Si torna a `null`, che e' com'era: rimettere 400 vorrebbe dire
     * inventare un valore che in quella colonna non c'e' mai stato.
     */
    public function down(): void
    {
        DB::table('plans')->where('code', 'plus')->update([
            'ai_monthly_calls_per_member' => null,
            'ai_monthly_photo_calls_per_member' => null,
            'updated_at' => now(),
        ]);
    }
};
