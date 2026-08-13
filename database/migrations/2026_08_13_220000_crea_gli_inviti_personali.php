<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trainer_invites` — F6.2 della Parte B, 13/08/2026.
 *
 * ── 🚨 Perché non si riusa il `join_code` ──────────────────────────────────
 *
 * `tenants.join_code` è il codice **della palestra**: chiunque lo conosca entra,
 * quante volte vuole, per sempre. È giusto così per una palestra — è scritto
 * sulla parete della reception.
 *
 * ⚠️ Un invito di un trainer a **una persona** è un'altra cosa, e le tre
 * differenze sono tutte di sicurezza:
 *
 * | | `join_code` | invito personale |
 * |---|---|---|
 * | Quante volte | infinite | **una sola** (`used_at`) |
 * | Per quanto | per sempre | **scade** (`expires_at`) |
 * | Revocabile | rigenerandolo per tutti | **uno per volta** (`revoked_at`) |
 *
 * 🚨 Il caso concreto che rende necessaria la monouso: **un link che finisce in
 * una chat di gruppo non deve far entrare venti persone** nello spazio di un
 * trainer che ne aveva invitata una.
 *
 * ── ⚠️ 18+ dal primo commit ───────────────────────────────────────────────
 *
 * §2.4 del piano: ogni porta d'ingresso nuova nasce già con lo sbarramento.
 * Questa è una porta nuova, e i consensi si pretendono al momento del riscatto —
 * non «poi, quando ci sarà tempo».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_invites', function (Blueprint $table): void {
            $table->id();

            /*
             * 🚨 Il tenant è quello **del trainer**, e la scelta è la stessa di
             * `trainer_member` (F6.1): è lui che «possiede» il rapporto, ed è
             * sotto il suo scope che deve vedere i propri inviti.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();

            /*
             * Il segreto che viaggia nel link.
             *
             * ⚠️ **Si conserva in chiaro, e va detto perché**: non è una
             * password ma un biglietto monouso a vita breve, e il riscatto deve
             * poterlo cercare per uguaglianza. Conservarne l'hash impedirebbe
             * l'indice unico e costringerebbe a una scansione a ogni tentativo.
             * 💡 La difesa è la **lunghezza** (32 caratteri casuali), la
             * scadenza e l'uso singolo.
             */
            $table->string('token', 64)->unique();

            // A chi era destinato, se lo si sa: serve a mostrare al trainer
            // «invito a mario@…» invece di un elenco di codici indistinguibili.
            $table->string('email')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Chi è entrato con questo invito. `nullOnDelete` e non `cascade`:
            // se quella persona cancella l'account, l'invito resta come traccia
            // del fatto che è stato usato.
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['trainer_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_invites');
    }
};
