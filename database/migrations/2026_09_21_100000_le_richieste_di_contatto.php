<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✉️ **Le richieste di contatto dal sito** — 21/09/2026.
 *
 * 📌 Il committente, parlando del piano su misura: *«tanto c'è scrivici.
 * Mettici un modulo di contatto così mi scrivono»*.
 *
 * ── ⛔ Cosa c'era prima ────────────────────────────────────────────────────
 *
 * Un `mailto:info@smp-digital.it`. 🚨 Tre modi di perdere il cliente in un
 * link solo: chi legge la posta sul web non ha un programma di posta
 * configurato e non succede niente; chi ce l'ha si trova una finestra vuota e
 * deve inventarsi cosa scrivere; e comunque **noi non sappiamo che è
 * successo** — nessuna riga, nessun conteggio, nessun modo di accorgersi che
 * dieci palestre hanno provato a scrivere e nessuna ha finito.
 *
 * ── 🚨 Perché una tabella e non una mail ──────────────────────────────────
 *
 * Perché `MAIL_MAILER` in produzione è ancora `log`: una richiesta mandata per
 * posta oggi finirebbe in un file. ⚠️ **La riga è il canale**, la mail semmai
 * si aggiunge dopo. E una riga si ritrova, si cerca, si segna come chiusa —
 * cosa che una casella di posta condivisa non fa.
 *
 * ── 🔒 Cosa NON c'è dentro, di proposito ──────────────────────────────────
 *
 * ⛔ Niente `ip`, niente `user_agent`. Sulle liberatorie ci sono perché lì sono
 * **la prova dell'atto**; qui sarebbero solo profilazione di chi ci scrive, e
 * l'abuso lo ferma `throttle`, non un indirizzo scritto in tabella.
 *
 * ⚠️ Sono comunque dati personali (nome, email, telefono): stanno
 * nell'informativa §3.12 e si conservano **24 mesi**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('richieste_di_contatto', function (Blueprint $tabella): void {
            $tabella->id();

            /**
             * Chi scrive: `palestra`, `trainer` o `altro`.
             *
             * 💡 Non è una decorazione: una palestra con 300 iscritti e un
             * curioso che chiede se l'app è gratis non si leggono con la stessa
             * fretta, e l'elenco del pannello si filtra per questo.
             */
            $tabella->string('tipo', 20)->default('altro');

            $tabella->string('nome', 255);
            $tabella->string('email', 255);
            $tabella->string('telefono', 40)->nullable();

            /** Il nome della palestra o dell'attività, se ce l'ha. */
            $tabella->string('organizzazione', 255)->nullable();

            /*
             * 🚨 Quanti iscritti e quanti trainer servono: sono **le due
             * domande da cui dipende il prezzo**. Chiederle nel modulo evita il
             * giro di mail che le chiede dopo, e fa arrivare la richiesta già
             * preventivabile.
             *
             * ⚠️ `nullable`: chi scrive per altro non le compila, e un `0`
             * vorrebbe dire «nessuno», che è una risposta diversa da «non l'ha
             * detto».
             */
            $tabella->unsignedInteger('iscritti')->nullable();
            $tabella->unsignedInteger('trainer')->nullable();

            $tabella->text('messaggio');

            /** `nuova`, `presa` (in carico) o `chiusa`. */
            $tabella->string('stato', 20)->default('nuova');

            /** Gli appunti di chi la lavora: non li vede chi ha scritto. */
            $tabella->text('note_interne')->nullable();

            $tabella->timestamp('presa_in_carico_il')->nullable();

            $tabella->timestamps();

            /*
             * 💡 L'elenco del pannello si apre su «le nuove, le più recenti
             * prima»: è quell'indice, ed è l'unica query che questa tabella
             * riceve davvero.
             */
            $tabella->index(['stato', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('richieste_di_contatto');
    }
};
