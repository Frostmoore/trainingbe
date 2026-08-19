<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando i consensi sono stati **chiesti** — FASE 2-bis, 19/08/2026.
 *
 * ── 🚨 «Chiesti» non è «dati», ed è tutta la ragione di questa colonna ─────
 *
 * Le tre colonne `*_consent_at` dicono **quando qualcuno ha detto di sì**.
 * Nessuna dice se gliel'abbiamo **chiesto**, e senza quella informazione le due
 * situazioni sono indistinguibili:
 *
 * | | `health/ai/sleep_ai` | Cosa fare |
 * |---|---|---|
 * | Non gliel'abbiamo mai chiesto | tutti `null` | **chiedere** |
 * | Gliel'abbiamo chiesto e ha detto no a tutto | tutti `null` | **non chiedere più** |
 *
 * ⚠️ **Il difetto che questa colonna chiude**: chi rifiuta tutto si vede
 * riproporre la stessa domanda a ogni reinstallazione — e la seconda volta la
 * risposta è «no» più in fretta, perché è diventata un fastidio.
 *
 * ── 🚨 Perché sul SERVER e non nell'app ───────────────────────────────────
 *
 * Perché il segnale deve **sopravvivere alla reinstallazione**, e un flag nelle
 * preferenze locali muore con l'app. 💡 È esattamente quello che è successo la
 * sera del 19/08: due reinstallazioni, e ogni volta l'app ricominciava da capo
 * come se non avesse mai visto quella persona.
 *
 * ── ⚠️ E non è un dato personale in più ───────────────────────────────────
 *
 * Dice «a questa persona è stata mostrata la schermata dei consensi», che è un
 * fatto sulla **nostra** interfaccia, non su di lei. 💡 Anzi: serve a **non**
 * chiederle di nuovo qualcosa a cui ha già risposto, che è il verso giusto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * 💡 Una data e non un booleano, come per i consensi veri: se un
             * giorno la schermata cambiasse in modo sostanziale, sapere **quando**
             * è stata mostrata dice a chi va richiesta di nuovo. Con un booleano
             * quell'informazione non c'è, e non si può ricostruire.
             */
            $table->dateTime('consensi_chiesti_il')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('consensi_chiesti_il');
        });
    }
};
