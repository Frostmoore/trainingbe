<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🪙 **I gettoni regalati** — 24/09/2026, fase P2 di `plan_promozioni.md`.
 *
 * ══ 🚨 PERCHÉ I LOTTI (§4.5 del piano) ═══════════════════════════════════
 *
 * Il portafoglio è **un numero** (`users.ai_credits`): non sa quali gettoni
 * sono regalati. ⛔ Togliere a scadenza «i gettoni regalati» dal saldo vorrebbe
 * dire togliere anche quelli comprati, se la persona ha speso prima i suoi.
 * 💡 Un lotto esiste **solo per i gettoni che scadono**: `consuma()` scala
 * prima da lì (non si perde niente che si poteva usare), e a scadenza si toglie
 * solo il residuo.
 *
 * ══ ⚠️ `acquisto_in_sospeso` ══════════════════════════════════════════════
 *
 * Una promozione può regalare subito **e** scontare (o dare un bonus su) un
 * acquisto che verrà. Con il massimo per persona a 1, l'assegnazione del
 * regalo chiuderebbe la porta allo sconto. Questo segno dice «il premio
 * d'acquisto di questa assegnazione non è ancora stato usato»: il pagamento lo
 * consuma **sulla stessa assegnazione**, invece di chiederne una seconda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotti_di_gettoni', function (Blueprint $tabella): void {
            $tabella->id();
            $tabella->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $tabella->foreignId('promozione_assegnazione_id')->nullable()
                ->constrained('promozione_assegnazioni')->nullOnDelete();
            $tabella->unsignedInteger('gettoni_iniziali');
            $tabella->unsignedInteger('gettoni_restanti');
            $tabella->timestamp('scade_il');
            $tabella->timestamp('chiuso_il')->nullable();
            $tabella->timestamps();

            $tabella->index(['user_id', 'chiuso_il', 'scade_il']);
        });

        Schema::table('promozione_assegnazioni', function (Blueprint $tabella): void {
            $tabella->boolean('acquisto_in_sospeso')->default(false)->after('scontato_cent');
        });
    }

    public function down(): void
    {
        Schema::table('promozione_assegnazioni', function (Blueprint $tabella): void {
            $tabella->dropColumn('acquisto_in_sospeso');
        });

        Schema::dropIfExists('lotti_di_gettoni');
    }
};
