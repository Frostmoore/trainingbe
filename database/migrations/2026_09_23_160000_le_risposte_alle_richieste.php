<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✉️ Le risposte alle richieste dal sito — F9.3, 23/09/2026.
 *
 * 📌 Il committente: *«deve permettermi di rispondere direttamente dal
 * pannello»*.
 *
 * ══ 🗄️ DOVE VIVE · VA NEL BACKUP · QUANDO MUORE ══════════════════════════
 *
 * - **Dove**: qui, una riga per risposta mandata (la mail parte, e resta la
 *   traccia di cosa si è scritto e quando).
 * - **Backup**: col database.
 * - **Quando muore**: 🚨 **con la sua richiesta** (`cascadeOnDelete`). La
 *   richiesta si pota dopo 24 mesi (`registri:pota`, promessa scritta sul
 *   modulo e nell'informativa §3.12): una risposta che sopravvivesse
 *   terrebbe in vita l'indirizzo a cui è stata mandata, e la promessa
 *   diventerebbe falsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risposte_alle_richieste', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('richiesta_di_contatto_id')->constrained('richieste_di_contatto')->cascadeOnDelete();

            // Chi ha risposto (il super admin). nullOnDelete: la risposta resta anche se l'account no.
            $table->foreignId('scritta_da')->nullable()->constrained('users')->nullOnDelete();

            $table->string('oggetto', 200);
            $table->text('testo');

            // ⚠️ `false` se la mail non è partita (o è finita nel log): si dice, non si nasconde.
            $table->boolean('mandata')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risposte_alle_richieste');
    }
};
