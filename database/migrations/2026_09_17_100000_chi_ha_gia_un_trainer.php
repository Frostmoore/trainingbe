<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 👥 **Chi ha già un trainer lo sa, e risponde una volta sola** — 3b-AH.1-ter.5,
 * 17/09/2026.
 *
 * ══ 📌 COSA MANCAVA ═══════════════════════════════════════════════════════
 *
 * 📌 Dal piano: *«la modale della sovrapposizione, con "Lascia com'è" in
 * evidenza»* e *«ricordare la risposta, per non richiederla ogni volta»*.
 *
 * ⛔ Prima il secondo rapporto nasceva **in silenzio**: una persona che seguiva
 * Anna accettava l'invito di Marco e si ritrovava due trainer senza che nessuno
 * glielo avesse detto. 🚨 E non è un dettaglio di cortesia: tutti e due vedono i
 * suoi dati, tutti e due le scrivono, e tutti e due le occupano uno slot.
 *
 * ══ 💡 PERCHE' UNA DATA E NON UN BOOLEANO ═════════════════════════════════
 *
 * Perché la domanda che ci si farà è *«quando l'ha accettato?»*, non *«l'ha
 * accettato?»*: il giorno che qualcuno contesta un secondo trainer, un `true`
 * non dice niente. ⚠️ È la stessa scelta già fatta per `age_confirmed_at`,
 * `terms_accepted_at` e `albo_dichiarato_il`.
 *
 * ⛔ **Fuori da `$fillable`**, come tutte le altre: è una dichiarazione della
 * persona, e una dichiarazione non si assegna in massa da una richiesta HTTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('affiancamento_accettato_il')
                ->nullable()
                ->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('affiancamento_accettato_il');
        });
    }
};
