<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔑 **La reimpostazione della password** — 22/09/2026, fase F4.
 *
 * 📌 Il committente: *«Eh sì va creata la pagina di reimpostazione password»*,
 * e prima ancora *«mi serve un tasto per mandare il reset password»* — quattro
 * volte, su quattro pagine diverse.
 *
 * ══ 🚨 PERCHÉ NON SI USA `password_reset_tokens` DI LARAVEL ══════════════
 *
 * Perché quella tabella è **indicizzata per email**, e qui l'email **non
 * identifica una persona**: l'unicità è per palestra (`users_tenant_id_email_
 * unique`), ed è voluta — è il caso di chi iscrive il figlio col proprio
 * indirizzo.
 *
 * ⛔ Con due account sullo stesso indirizzo, il broker di Laravel ne pesca
 * **uno** e cambia la password a quello: una volta su due la cambia alla
 * persona sbagliata, e nessuno se ne accorge finché l'altra non riesce più a
 * entrare.
 *
 * 💡 Qui il token è legato a `user_id`: si sa **sempre** di chi è.
 *
 * ── 🔒 Il token non si salva in chiaro ────────────────────────────────────
 *
 * 🚨 Un token in chiaro in tabella è una password temporanea leggibile da
 * chiunque abbia accesso al database — compreso un backup finito nel posto
 * sbagliato. Si salva l'**impronta**, e l'originale vive solo nel link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimpostazioni_password', function (Blueprint $tabella): void {
            $tabella->id();

            $tabella->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** 🔒 `hash('sha256', $token)`: in chiaro vive solo nel link. */
            $tabella->string('impronta', 64)->unique();

            /**
             * ⚠️ **Scade, e presto.** Un link di reset è una chiave: più resta
             * valido, più è probabile che arrivi a qualcun altro — inoltrato,
             * in una cronologia, in una casella condivisa.
             */
            $tabella->timestamp('scade_il');

            /**
             * 🚨 **Si usa una volta sola.** ⛔ Senza questa colonna, chi ha il
             * link può rientrare quando vuole: diventa una seconda password,
             * che la persona non sa nemmeno di avere.
             */
            $tabella->timestamp('usato_il')->nullable();

            /** Chi l'ha chiesto: la persona, o chi l'ha aiutata dal pannello. */
            $tabella->foreignId('chiesto_da_id')->nullable()->constrained('users')->nullOnDelete();

            $tabella->timestamps();

            $tabella->index(['user_id', 'usato_il']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimpostazioni_password');
    }
};
