<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💰 **I piani a due tetti, e il piano a consumo** — 3b-AH.6 / 3b-Z (13/09/2026).
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * *«Ogni piano per le palestre ha un tot di slot per trainer e iscritti […] Se
 * una palestra sceglie il piano a consumo pagherà un tot per ogni iscritto a cui
 * regala l'abbonamento, per un minimo di 100.»*
 *
 * ══ 🆕 LE DUE COLONNE NUOVE ═══════════════════════════════════════════════
 *
 * Un piano a consumo **non ha un canone**: `price_cents` resta `0` ed è vero —
 * quello che si paga dipende da quanti iscritti sono stati abbonati quel mese.
 *
 * | Colonna | Cosa dice |
 * |---|---|
 * | `prezzo_iscritto_cent` | quanto costa **ogni iscritto abbonato**, al mese |
 * | `minimo_iscritti` | sotto questo numero si paga comunque questo numero |
 *
 * ⚠️ **Nullable tutte e due, e `null` vuol dire «non è un piano a consumo»**:
 * non è un valore mancante, è l'assenza di quel modo di pagare. ⛔ Uno zero
 * avrebbe voluto dire «a consumo, e gratis».
 *
 * ══ ⛔ E UNA COLONNA CHE SE NE VA: `max_members_per_trainer` ══════════════
 *
 * 🚨 **Descriveva un modello che non è più questo**, e nessuno la leggeva:
 * `Plan::tettoAllieviPerTrainer()` esisteva e non era chiamato da nessuna parte.
 * ⛔ Diceva «50 iscritti per ogni trainer» e non ne fermava nemmeno uno: un
 * numero che sembra una regola e non lo è — la cosa peggiore che si possa
 * lasciare in una tabella.
 *
 * 📌 E il committente l'ha sciolta: *«gli iscritti di una palestra sono
 * contemporaneamente di tutti i trainer della palestra»*. Non c'è niente da
 * ripartire fra i trainer, quindi non c'è niente da contare.
 *
 * 💡 **Si cancella invece di ritirarla** — al contrario dei *piani*, che si
 * ritirano sempre: un piano venduto è un contratto, una colonna che nessuno ha
 * mai letto non è niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->unsignedInteger('prezzo_iscritto_cent')->nullable()->after('price_cents');
            $tabella->unsignedInteger('minimo_iscritti')->nullable()->after('prezzo_iscritto_cent');
        });

        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->dropColumn('max_members_per_trainer');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->unsignedInteger('max_members_per_trainer')->nullable()->after('max_trainers');
        });

        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->dropColumn(['minimo_iscritti', 'prezzo_iscritto_cent']);
        });
    }
};
