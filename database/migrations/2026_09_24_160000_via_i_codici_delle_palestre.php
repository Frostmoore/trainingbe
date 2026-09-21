<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ **Via i codici delle palestre** — F11 di `plan_UX_pannello.md`, 24/09/2026.
 *
 * 📌 Il committente: *«Il codice palestra non ha più senso di esistere, gli
 * inviti si fanno con il link di invito. Togliamo proprio tutto il discorso dei
 * codici delle palestre»*; e poi *«è da rimuovere da tutto, non esiste più»*.
 *
 * 🚨 La colonna **si toglie**, non si svuota: un codice che resta in tabella è
 * un codice che qualcuno un giorno rilegge. `IlCodicePalestraNonTornaTest`
 * impedisce di riaprirla.
 *
 * ⚠️ `down()` rimette la colonna **vuota e nullable**: i codici di prima non
 * sono scritti da nessuna parte, e inventarne di nuovi non ridarebbe quelli che
 * le palestre avevano distribuito.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'join_code')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['join_code']);
            $table->dropColumn('join_code');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'join_code')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('join_code', 12)->nullable()->unique()->after('slug');
        });
    }
};
