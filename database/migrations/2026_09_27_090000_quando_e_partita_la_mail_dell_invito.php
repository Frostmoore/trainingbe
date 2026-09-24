<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📧 **Quando è partita la mail di un invito** — blocco B1, 24/09/2026.
 *
 * ══ 🚨 PERCHE' SERVE UNA COLONNA E NON BASTA `email` ════════════════════════
 *
 * ⛔ `trainer_invites.email` dice **a chi** si voleva mandarlo, non **se è
 * partito**. E la differenza è tutta: `InvitiDelTrainer::mandaIlLink()` può
 * fallire — trasporto giù, indirizzo rifiutato — e in quel caso **l'invito
 * resta valido di proposito** (il link esiste e chi invita ce l'ha), ma nessuno
 * ha ricevuto niente.
 *
 * 🚨 Senza questa colonna l'app non può dire la verità nella sua schermata: o
 * scrive *«mandata»* sempre (e mente quando la mail non è partita), o non lo
 * scrive mai (e chi invita non sa se deve rimandarla).
 *
 * 💡 È una **data** e non un booleano: «mandata il 24/09 alle 14:03» è una
 * risposta, «mandata: sì» è metà risposta — e quando qualcuno rimanda l'invito
 * a un altro indirizzo, la data dice **quale** invio è l'ultimo.
 *
 * ⚠️ Gli inviti già esistenti restano a `null`: non sappiamo se la loro mail sia
 * partita, e **inventare un valore sarebbe peggio che non averlo**. L'app
 * mostrerà «solo link» per quelli, che è esattamente ciò che sappiamo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_invites', function (Blueprint $table): void {
            $table->timestamp('email_inviata_il')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_invites', function (Blueprint $table): void {
            $table->dropColumn('email_inviata_il');
        });
    }
};
