<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🤖 **Chi l'ha sospesa, la macchina o una persona?** — 27/09/2026.
 *
 * ══ 📌 PERCHE' QUESTA COLONNA ESISTE ══════════════════════════════════════
 *
 * 📌 Il committente: *«per prima cosa ovviamente facciamo che chi non ha pagato
 * si sospende in automatico»*.
 *
 * 🚨 **Ma una sospensione automatica e una sospensione a mano NON sono la stessa
 * cosa, e il codice deve saperlo.** Una palestra sospesa da `/god` per un abuso
 * **non deve tornare attiva** perche' arriva un pagamento; una sospesa dalla
 * macchina perche' l'abbonamento era finito **deve**, e subito.
 *
 * ⛔ Senza questa colonna le due sono indistinguibili, e qualunque riattivazione
 * automatica sarebbe un modo per annullare una decisione presa da una persona.
 *
 * ── 💡 Perche' una data e non un booleano ──────────────────────────────────
 *
 * Un `bool` risponde *«e' automatica?»*. Una data risponde anche *«da
 * quando?»* — che e' la domanda che si fa quando un cliente telefona. ⚠️ E costa
 * lo stesso.
 *
 * ── ⚠️ Perche' NON e' `fillable` ───────────────────────────────────────────
 *
 * ⛔ Si scrive solo da `LaSospensioneAutomatica`, con `forceFill`. Una colonna
 * che dice *«questa l'ha fatta la macchina»* e che un modulo puo' impostare
 * sarebbe una bugia raggiungibile dall'esterno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('sospensione_automatica_dal')
                ->nullable()
                ->after('trial_ends_at');

            /*
             * 💡 L'indice serve al giro notturno, che cerca **le sole righe
             * sospese dalla macchina** per vedere se qualcuna ha ripagato.
             * ⚠️ Su una tabella di clienti sono poche righe su molte: e'
             * esattamente il caso in cui un indice paga.
             */
            $table->index('sospensione_automatica_dal');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['sospensione_automatica_dal']);
            $table->dropColumn('sospensione_automatica_dal');
        });
    }
};
