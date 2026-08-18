<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le campagne pubblicitarie — 18/08/2026. **M5.1 e M5.2.**
 *
 * ── 🚨 Si paga a visualizzazione, e una visualizzazione e' UNA PERSONA AL
 *       GIORNO ───────────────────────────────────────────────────────────────
 *
 * E' la domanda che decide la fattura, quindi va risolta nello schema e non nel
 * codice che lo usa. ⚠️ Le due alternative erano peggiori, e per ragioni
 * diverse:
 *
 * - **«ogni volta che compare»** e' ingiusto e manipolabile: chi scorre
 *   l'elenco su e giu' genererebbe venti visualizzazioni, e un difetto nostro
 *   che ricarica in continuazione moltiplicherebbe la fattura di qualcuno senza
 *   che nessuno abbia fatto niente;
 * - **«quando entra davvero nello schermo»** dovrebbe chiederlo all'app, e l'app
 *   sta sul telefono di qualcun altro: 🚨 un conteggio che si puo' falsificare
 *   dal client non e' un conteggio su cui si emette una fattura.
 *
 * 💡 «Una persona al giorno» si conta **sul server**, non si puo' gonfiare, e
 * corrisponde a quello che l'inserzionista compra davvero: una persona raggiunta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagne', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * Di chi e' la campagna: la stessa coppia XOR di `profili_pubblici`.
             *
             * 💡 Non si aggancia alla **scheda** ma al suo proprietario: una
             * palestra che cancellasse e rifacesse la scheda perderebbe il conto
             * dello speso, e con esso la possibilita' di contestarlo.
             */
            $tabella->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $tabella->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            /*
             * 🚨 **L'interruttore, e parte spento.** Si accende e si spegne
             * quando si vuole — e' quello che il committente ha chiesto: *«lo
             * possono attivare e disattivare quando vogliono»*.
             */
            $tabella->boolean('attiva')->default(false);

            /*
             * 🚨 **Il tetto di spesa NON e' facoltativo.**
             *
             * ⚠️ Senza, un pagamento a evento e' un modo per mandare a qualcuno
             * una fattura da quattromila euro per un difetto nostro. Non e' un
             * rischio teorico: e' come vanno male **tutti** i sistemi a consumo,
             * e la prima volta che succede si perde il cliente e si ha torto.
             */
            $tabella->unsignedInteger('budget_mensile_cent');

            /** Quanto e' stato speso nel mese in corso. Si azzera al cambio mese. */
            $tabella->unsignedInteger('speso_mese_cent')->default(0);

            /*
             * 🚨 **Il prezzo fotografato all'attivazione**, non letto dalla
             * configurazione a ogni conteggio.
             *
             * ⚠️ Un aumento di listino non deve cambiare il prezzo di una
             * campagna gia' in corso: chi l'ha accesa ha accettato **quella**
             * cifra, e vedersela cambiare a meta' mese e' il genere di cosa per
             * cui si perde un cliente e si ha torto una seconda volta.
             */
            $tabella->unsignedInteger('costo_visualizzazione_cent');

            /*
             * Quale mese sta contando `speso_mese_cent`, come `2026-08`.
             *
             * 💡 Serve ad azzerare **pigramente**: non c'e' nessun job che gira
             * il primo del mese: alla prima visualizzazione di settembre si vede
             * che il mese e' cambiato e si riparte da zero. ⚠️ Un job
             * mensile avrebbe voluto dire che se non gira — server fermo,
             * scheduler rotto — le campagne restano spente per un mese intero
             * senza che nessuno se ne accorga.
             */
            $tabella->string('mese', 7)->nullable();

            /*
             * Quando si e' spenta da sola per budget esaurito.
             *
             * 💡 Non e' cronaca: e' la risposta a «perche' non compaio piu'?»,
             * ed e' l'unico modo per cui quella domanda si chiude guardando una
             * data invece di litigare.
             */
            $tabella->timestamp('esaurita_il')->nullable();

            $tabella->timestamps();

            /** ⚠️ Una campagna per soggetto: due sarebbero due conti sullo stesso budget. */
            $tabella->unique('tenant_id');
            $tabella->unique('user_id');

            $tabella->index('attiva');
        });

        Schema::table('profili_pubblici', function (Blueprint $tabella): void {
            /*
             * 💡 La colonna c'era gia' (M2.1), senza chiave esterna perche'
             * `campagne` non esisteva. Adesso esiste.
             *
             * ⚠️ `nullOnDelete`: cancellare una campagna **non** deve cancellare
             * la scheda. La scheda e' l'esistenza nel catalogo, la campagna e'
             * solo il fatto che si paga per stare in cima.
             */
            $tabella->foreign('campagna_id')->references('id')->on('campagne')->nullOnDelete();
        });

        /*
         * 🚨 Il dettaglio: **chi** ha visto **cosa**, **che giorno**.
         *
         * ── ⚠️ Perche' una riga per persona e non un contatore ──────────────
         *
         * Perche' «una persona al giorno» non e' verificabile con un numero: per
         * sapere se questa persona ha gia' visto questa campagna oggi bisogna
         * **averlo scritto**. Il vincolo unico e' il conteggio.
         *
         * ── 🚨 Solo persone identificate, e le anonime sono GRATIS ──────────
         *
         * Il catalogo e' aperto anche a chi non ha un account, e per quelle
         * visite non si conta niente. ⚠️ L'alternativa sarebbe contare per
         * indirizzo IP: gonfiabile da chiunque, e vorrebbe dire conservare gli
         * IP di chi consulta un elenco — un dato personale in piu', raccolto per
         * fatturare.
         *
         * 💡 Quindi le visite anonime sono **un regalo all'inserzionista**, non
         * una perdita per noi: compare e non paga.
         */
        Schema::create('visualizzazioni', function (Blueprint $tabella): void {
            $tabella->id();

            $tabella->foreignId('campagna_id')->constrained('campagne')->cascadeOnDelete();

            /*
             * ⚠️ `cascadeOnDelete`: se una persona cancella l'account, le sue
             * righe se ne vanno. Il conto del mese e' gia' scritto in
             * `campagne.speso_mese_cent` e non cambia — cioe' la fattura resta
             * corretta senza dover conservare chi era.
             */
            $tabella->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /** 💡 Il giorno **locale di chi guarda**, non l'istante UTC. */
            $tabella->date('giorno');

            $tabella->timestamps();

            /*
             * 🚨 **Il vincolo che rende il conteggio non gonfiabile.**
             *
             * Provare a inserire la seconda volta fallisce, e quel fallimento
             * **e'** la regola «una persona al giorno». ⚠️ Farlo con un
             * `SELECT` prima dell'`INSERT` lascerebbe la finestra fra i due, e
             * due richieste simultanee — che sull'apertura di una schermata
             * capitano — conterebbero due volte.
             */
            $tabella->unique(['campagna_id', 'giorno', 'user_id']);

            /** Per i conti del pannello: quante oggi, quante questo mese. */
            $tabella->index(['campagna_id', 'giorno']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visualizzazioni');

        Schema::table('profili_pubblici', function (Blueprint $tabella): void {
            $tabella->dropForeign(['campagna_id']);
        });

        Schema::dropIfExists('campagne');
    }
};
