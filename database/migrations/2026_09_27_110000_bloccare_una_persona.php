<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🚫 **Bloccare una persona** — blocco B3, 24/09/2026.
 *
 * 📌 Il committente, decisione 53.3: *«max 3 messaggi e si possono bloccare le
 * persone»*.
 *
 * ══ ⛔ NON ESISTEVA, E SI SCOPRE SOLO CERCANDOLO ════════════════════════════
 *
 * 🚨 In tutto il backend la parola «blocca» compariva **solo nei commenti** sul
 * limite dei tre messaggi (`CancelloDellaChat`, `LimiteDeiTreMessaggi`). Il
 * limite difende dalla **quantità** — tre messaggi e basta — ma non dalla
 * **persona**: chi ti ha scritto tre volte resta lì, e ogni volta che riapri la
 * chat lo rivedi.
 *
 * 💡 Serve adesso e non prima perché dal 24/09 il nome utente rende le persone
 * **cercabili**: prima per scriverti bisognava già conoscerti.
 *
 * ── 🚨 Due effetti, non uno ─────────────────────────────────────────────────
 *
 * | Effetto | Perché |
 * |---|---|
 * | **non può scriverti** | è quello che uno si aspetta bloccando |
 * | **non ti trova più** | ⛔ senza, basterebbe cercarti di nuovo e riaprire una conversazione: il blocco sarebbe una porta chiusa accanto a una aperta |
 *
 * ── ⚠️ Perché `bloccante`/`bloccato` e non una coppia ordinata ──────────────
 *
 * 🚨 Il blocco **non è reciproco**: se A blocca B, B non ha bloccato A. La
 * direzione conta, e va letta in tutte e due i versi — «chi ho bloccato» per
 * l'elenco, «chi mi ha bloccato» per il cancello. Per questo l'indice unico è
 * sulla coppia **ordinata**, e ci sono due indici di lettura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocchi', function (Blueprint $table): void {
            $table->id();

            /*
             * ⚠️ `cascadeOnDelete` su tutti e due: un blocco verso un account
             * che non esiste più non protegge da niente, e resterebbe a
             * raccontare un rapporto fra due persone di cui una è sparita.
             */
            $table->foreignId('bloccante_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bloccato_id')->constrained('users')->cascadeOnDelete();

            /*
             * 💡 Facoltativo, e **non si mostra a chi è stato bloccato**: serve
             * a noi il giorno in cui qualcuno segnala un abuso, non a far
             * sapere all'altro cosa ha sbagliato.
             */
            $table->string('motivo', 255)->nullable();

            $table->timestamps();

            // ⛔ Bloccare due volte la stessa persona non è un secondo blocco.
            $table->unique(['bloccante_id', 'bloccato_id']);

            // 🔎 «Chi mi ha bloccato?» è la domanda del cancello, e si fa a ogni
            //    ricerca e a ogni messaggio: senza indice è una scansione.
            $table->index('bloccato_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocchi');
    }
};
