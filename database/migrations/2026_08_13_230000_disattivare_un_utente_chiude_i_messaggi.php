<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trainer_member.disattivato_il` — F6.4 della Parte B, decisione D5.
 *
 * ── 🚨 «Disattivare» chiude SOLO i messaggi ────────────────────────────────
 *
 * Il legame resta, la storia si conserva, il canale si chiude. **Reversibile.**
 *
 * ⚠️ Le due alternative sono state scartate, e il motivo va tenuto:
 *
 * | Alternativa | Perché no |
 * |---|---|
 * | Cancellare il legame | Si perde la storia, e riattivare vorrebbe dire ricominciare da zero — comprese le conversazioni |
 * | Disattivare l'**utente** (`users.is_active`) | Lo chiuderebbe fuori **dall'app**, non dal rapporto: una persona che paga un altro trainer si ritroverebbe l'account bloccato da un terzo |
 *
 * 💡 E copre più di quanto sembri: dopo D11/D13 i piani viaggiano *dentro la
 * chat*, quindi chiudere il canale **chiude anche la consegna di piani nuovi**.
 * Non serve una seconda regola.
 *
 * 🚨 **Ciò che questa colonna NON può fare, e va scritto perché qualcuno lo
 * chiederà**: revocare i piani **già ricevuti**. Vivono sul telefono, e il
 * server non può togliere ciò che non ha mai avuto. L'unica strada sarebbe
 * chiedere all'app di nasconderli — cioè fidarsi del client, che non si fa.
 *
 * ⚠️ Il nome della colonna è in italiano come il resto del dominio scritto da
 * noi; le colonne ereditate dallo schema originale (`assigned_at`,
 * `assigned_by`) restano in inglese, e mescolare è meno peggio che rinominare
 * colonne su cui gira già del codice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            $table->timestamp('disattivato_il')->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            $table->dropColumn('disattivato_il');
        });
    }
};
