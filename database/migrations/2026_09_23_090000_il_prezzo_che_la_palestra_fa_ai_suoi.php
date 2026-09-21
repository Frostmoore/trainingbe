<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💶 **Quanto fa pagare la palestra ai suoi iscritti** — 23/09/2026, fase F5.1.
 *
 * 📌 Il committente, alla domanda **D9** («dove prendiamo il prezzo per
 * allievo?»): *«lo scrive la palestra e noi non lo verifichiamo nemmeno. Sono
 * dati suoi e a noi non interessano»*.
 *
 * ══ 🚨 A COSA SERVE, E A COSA NON SERVE ══════════════════════════════════
 *
 * ✅ Serve **solo a loro**: è l'unico numero che manca per rispondere a
 * *«questo iscritto mi conviene?»*. Noi sappiamo quanto ci pagano (il canone
 * diviso gli iscritti); loro sanno quanto incassano. Senza questo campo il
 * cruscotto può mostrare **solo il costo**, cioè metà del conto — quella che
 * fa sembrare il servizio una spesa e basta.
 *
 * ⛔ **NON serve a noi, e non ci deve servire.** Non entra in nessuna fattura,
 * non cambia nessun prezzo, non si verifica in nessun modo. 🚨 Il giorno che
 * qualcuno volesse usarlo per fatturare — una percentuale sul fatturato, un
 * piano «a revenue share» — questo campo **non è una fonte attendibile**: è un
 * numero che il cliente scrive a mano su se stesso.
 *
 * ── 💡 Perché in centesimi ────────────────────────────────────────────────
 *
 * Come ogni altro importo del progetto. ⛔ Un `decimal` per i soldi qui sarebbe
 * l'unica eccezione, e le eccezioni sui soldi si pagano in differenze di un
 * centesimo che nessuno sa spiegare.
 *
 * ── ⚠️ Perché nullable e non zero ─────────────────────────────────────────
 *
 * `null` vuol dire **«non l'ha ancora scritto»**, e il cruscotto lo dice.
 * ⛔ Uno zero vorrebbe dire «li alleno gratis», che è una risposta — e
 * mostrare un margine negativo a chi non ha ancora compilato il campo lo
 * farebbe telefonare per un guasto che non c'è.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->unsignedInteger('prezzo_per_allievo_cent')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->dropColumn('prezzo_per_allievo_cent');
        });
    }
};
