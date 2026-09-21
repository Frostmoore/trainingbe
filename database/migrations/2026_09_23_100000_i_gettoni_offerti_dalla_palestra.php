<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 **I gettoni che la palestra compra per un suo iscritto** — 23/09/2026, F5.8.
 *
 * 📌 Il committente (**R1**): *«voglio che la palestra possa acquistare dei
 * token da dare all'allievo … Quelli acquistati in questo modo devono andare
 * direttamente all'allievo»*.
 *
 * ══ 🚨 LA REGOLA CHE NON SI PIEGA ═══════════════════════════════════════
 *
 * 📌 *«ovvio che i gettoni sono soltanto di chi li compra»* (3b-AH.8) — e qui
 * chi compra **non è** chi li riceve. ⛔ Il portafoglio resta **personale**:
 * l'accredito va sul saldo dell'iscritto, e da quel momento **sono suoi**. La
 * palestra non può toglierli, non li vede nel proprio saldo, e se l'iscritto
 * se ne va se li porta via.
 *
 * ⚠️ **Ma da dove vengono deve restare scritto**, ed è il motivo di questa
 * colonna: alla domanda *«e questi da dove escono?»* — che si fa guardando un
 * saldo cresciuto senza un pagamento della persona — senza `pagato_da_tenant_id`
 * l'unica risposta sarebbe «non si sa».
 *
 * 💡 ✅ **A5**: *«Mah secondo me non serve che si scriva agli allievi»* — i
 * gettoni arrivano **in silenzio**, come un accredito qualunque. Questa colonna
 * serve a noi e alla palestra, non a mandare una notifica.
 *
 * ── ⚠️ Perché nullable, e perché `nullOnDelete` ───────────────────────────
 *
 * `null` è il caso normale: quasi tutti i movimenti li paga la persona stessa.
 * ⛔ E se una palestra viene cancellata, il movimento **resta**: è la storia di
 * un portafoglio che non è suo, e cancellarlo falserebbe il saldo di qualcun
 * altro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_credit_movements', function (Blueprint $tabella): void {
            $tabella->foreignId('pagato_da_tenant_id')
                ->nullable()
                ->after('operatore_id')
                ->constrained('tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_movements', function (Blueprint $tabella): void {
            $tabella->dropConstrainedForeignId('pagato_da_tenant_id');
        });
    }
};
