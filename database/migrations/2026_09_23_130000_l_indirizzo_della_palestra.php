<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📍 **L'indirizzo preciso della palestra** — 23/09/2026, fase F6.2 (A3).
 *
 * 📌 Il committente, sulla scheda pubblica: *«Dove sei … serve anche
 * l'indirizzo preciso (con cap provincia e tutto)»*.
 *
 * ══ 💡 COSA SERVE DAVVERO, E COSA C'È GIÀ ════════════════════════════════
 *
 * | Pezzo | Da dove |
 * |---|---|
 * | città, provincia, regione, paese | ✅ **già** da `comune_id` — non si riscrivono |
 * | via e numero | 🆕 `indirizzo` |
 * | CAP | 🆕 `cap` — ⚠️ vedi sotto |
 *
 * 🚨 **Il CAP non si prende dal comune**, anche se `comuni.cap` esiste. ⛔ Quello
 * è il **primo** CAP del comune, non l'unico: Roma ne ha più di cento, Milano
 * una sessantina. Scrivere sulla scheda di una palestra di Ostia il CAP del
 * centro di Roma sarebbe un indirizzo sbagliato presentato come giusto.
 *
 * ⚠️ `varchar(12)` e non `char(5)`: i CAP nel mondo non sono cinque cifre
 * (`SW1A 1AA`, `10115`, `100-0001`), e il paese della palestra ormai può
 * essere qualunque.
 *
 * ── ⚠️ Non è un dato personale ─────────────────────────────────────────────
 *
 * È l'indirizzo di un **esercizio commerciale**, fatto per essere trovato.
 * 💡 Per un trainer indipendente sarebbe diverso — spesso l'indirizzo è casa
 * sua — e infatti per lui il campo resta facoltativo e l'app non lo mostra se
 * è vuoto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profili_pubblici', function (Blueprint $tabella): void {
            $tabella->string('indirizzo', 200)->nullable()->after('comune_id');
            $tabella->string('cap', 12)->nullable()->after('indirizzo');
        });
    }

    public function down(): void
    {
        Schema::table('profili_pubblici', function (Blueprint $tabella): void {
            $tabella->dropColumn(['indirizzo', 'cap']);
        });
    }
};
