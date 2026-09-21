<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏙️ **Le città grandi si cercano prima** — 23/09/2026, fase F6.1.
 *
 * ══ 🚨 IL PROBLEMA, MISURATO ═════════════════════════════════════════════
 *
 * Con 232.000 città, «sa» comincia **settemila** nomi solo nella colonna
 * principale. ⛔ Per mostrare i quindici più grandi il database li deve
 * ordinare **tutti** per popolazione: 220-680 ms a ogni tasto premuto, sui
 * prefissi di due-tre lettere — cioè esattamente quelli che si scrivono per
 * primi.
 *
 * ══ ✅ LA SOLUZIONE: DUE GIRI, E IL PRIMO È PICCOLO ══════════════════════
 *
 * `rilevante` = popolazione ≥ 15.000: **~34.000 città** su 232.000. Il primo
 * giro cerca solo fra quelle, con un indice che comincia da `rilevante`: «sa»
 * diventa qualche centinaio di righe invece di settemila. 💡 Il secondo giro,
 * sui paesi piccoli di tutto il mondo, parte **solo se il primo non basta** —
 * e a quel punto chi scrive ha già digitato abbastanza lettere da renderlo
 * piccolo lui.
 *
 * ⚠️ **Il paese di chi cerca non passa da qui**: si cerca sempre per intero,
 * grande o piccolo che sia il comune. Per l'Italia sono ottomila righe, e
 * nessuno deve faticare a trovare un paese di trecento abitanti a casa sua.
 *
 * ── 🔒 Perché una colonna GENERATA ─────────────────────────────────────────
 *
 * 🚨 La calcola il database da `popolazione`, e **nessun importatore può
 * dimenticarsi di riempirla**. ⛔ Con una colonna normale, `comuni:importa` e
 * `comuni:importa-mondo` dovrebbero ricordarsene tutti e due — e il giorno che
 * uno se ne dimentica, le sue città spariscono dal primo giro senza nessun
 * errore.
 *
 * ⚠️ `STORED` e non `VIRTUAL`: una colonna virtuale si ricalcola a ogni lettura
 * e su MariaDB non tutte le versioni la indicizzano. Stored si scrive una volta,
 * all'import, e l'indice è un indice normale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comuni', function (Blueprint $tabella): void {
            $tabella->boolean('rilevante')
                ->storedAs('COALESCE(`popolazione`, 0) >= 15000')
                ->after('popolazione');

            $tabella->index(['rilevante', 'attivo', 'chiave'], 'comuni_rilevanti_chiave_index');
            $tabella->index(['rilevante', 'attivo', 'chiave_locale'], 'comuni_rilevanti_locale_index');
        });
    }

    public function down(): void
    {
        Schema::table('comuni', function (Blueprint $tabella): void {
            $tabella->dropIndex('comuni_rilevanti_locale_index');
            $tabella->dropIndex('comuni_rilevanti_chiave_index');
            $tabella->dropColumn('rilevante');
        });
    }
};
