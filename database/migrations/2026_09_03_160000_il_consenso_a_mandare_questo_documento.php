<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il consenso a mandare **questo** documento all'AI — Parte K, K1-ter.
 *
 * ══ 📌 PERCHE' UN CONSENSO IN PIU' ═══════════════════════════════════════
 *
 * 📌 Il committente, il 03/09/2026: *«si deve richiedere il consenso specifico a
 * mandare quei dati all'AI, con l'avvertimento esplicito e rosso che se
 * sull'immagine o nel documento ci sono dati personali, sarebbe meglio
 * interrompere e nasconderli»*.
 *
 * ⚠️ **E' diverso da `ai_consent_at`.** Quello dice *«puoi usare l'AI»*; questo
 * dice *«puoi mandare **questo file**»*. 🚨 Si chiede **ogni volta**, non una
 * volta sola: il file e' diverso ogni volta, ed e' del file che si parla.
 *
 * ══ 🚨 COSA SI REGISTRA, E COSA NO ═══════════════════════════════════════
 *
 * Chi, quando, per quale importazione. ⛔ **Non il documento e non il suo
 * contenuto**: registrarli «per provare il consenso» sarebbe conservare
 * esattamente cio' che K1-bis toglie, e con la scusa migliore che ci sia.
 *
 * 💡 La colonna vive quanto la riga — sette giorni al massimo — e se ne va con
 * lei. ⚠️ E' una scelta: la prova del consenso non sopravvive al trattamento che
 * autorizzava, perche' dopo non c'e' piu' niente da autorizzare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importazioni_da_documento', function (Blueprint $table): void {
            /*
             * ⚠️ **Nullable**, e le righe che esistono restano a `null`: sono
             * state aperte prima che questo consenso esistesse, e scrivergli
             * dentro una data inventata vorrebbe dire fabbricare la prova di un
             * consenso che nessuno ha dato.
             */
            $table->timestamp('consenso_documento_il')->nullable()->after('dichiarato_il');
        });
    }

    public function down(): void
    {
        Schema::table('importazioni_da_documento', function (Blueprint $table): void {
            $table->dropColumn('consenso_documento_il');
        });
    }
};
