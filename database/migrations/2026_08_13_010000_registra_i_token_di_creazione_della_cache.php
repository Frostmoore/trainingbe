<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_usage_logs.cache_creation_tokens` — 13/08/2026.
 *
 * ── 🚨 Il buco che questa colonna chiude ──────────────────────────────────
 *
 * Anthropic divide i token in ingresso in **tre** voci, e ne registravamo due:
 *
 * | Voce | Costo | La registravamo? |
 * |---|---|---|
 * | `input_tokens` | 1× | ✅ |
 * | `cache_read_input_tokens` | 0,1× | ✅ |
 * | `cache_creation_input_tokens` | **1,25×** | ❌ |
 *
 * ⚠️ Ed e' la piu' cara delle tre. Guardando i log del 12/08/2026 si vedeva una
 * riga con `input_tokens = 12` e `cache_read_tokens = 0` per una chiamata con un
 * prompt da cinquemila token: quei cinquemila erano finiti nella voce che non
 * guardavamo, e per il contatore quella chiamata era costata **niente**.
 *
 * 🚨 **Il totale era sistematicamente ottimista**, e un contatore ottimista e'
 * esattamente quello di cui poi nessuno si fida — e che non protegge la fattura
 * proprio quando servirebbe.
 *
 * 💡 Il valore di serie e' zero: le righe storiche restano leggibili e il loro
 * costo resta quello che gia' dicevano. Non si inventa a posteriori un numero
 * che nessuno ha misurato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->unsignedInteger('cache_creation_tokens')
                ->default(0)
                ->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropColumn('cache_creation_tokens');
        });
    }
};
