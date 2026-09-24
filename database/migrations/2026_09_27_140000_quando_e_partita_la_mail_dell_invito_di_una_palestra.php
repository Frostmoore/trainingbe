<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📧 **Quando è partita la mail dell'invito di una palestra** — blocco B2,
 * 24/09/2026.
 *
 * 🔗 È la **gemella esatta** della migrazione di B1 su `trainer_invites`, e il
 * perché è lo stesso: `email` dice **a chi** si voleva mandarlo, non **se è
 * partito**. `InvitiInPalestra::mandaIlLink()` può fallire — trasporto giù,
 * indirizzo rifiutato — e in quel caso l'invito **resta valido di proposito**,
 * perché il link esiste e chi invita ce l'ha in mano.
 *
 * ══ 🚨 PERCHE' NON BASTAVA QUELLA DI B1 ═════════════════════════════════════
 *
 * ⛔ Sono **due tabelle diverse** (`trainer_invites` e `tenant_invites`), e
 * lasciare la colonna solo sulla prima vorrebbe dire una schermata che sa dire
 * la verità agli inviti di un trainer e non a quelli di una palestra — cioè la
 * stessa pagina che mente **a metà**, che è il modo peggiore di mentire.
 *
 * ⚠️ Gli inviti già esistenti restano a `null`: non sappiamo se la loro mail
 * sia partita, e **inventare un valore sarebbe peggio che non averlo**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_invites', function (Blueprint $table): void {
            $table->timestamp('email_inviata_il')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invites', function (Blueprint $table): void {
            $table->dropColumn('email_inviata_il');
        });
    }
};
