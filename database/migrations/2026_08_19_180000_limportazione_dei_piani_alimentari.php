<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'importazione di un piano alimentare da PDF — N20.2.
 *
 * ── 🚨 Cosa NON resta qui dentro ───────────────────────────────────────────
 *
 * Il **piano confermato** non finisce su questo server. Una dieta legata a una
 * persona sui nostri sistemi sarebbe un dato dell'art. 9 con un nome sopra, ed
 * e' esattamente cio' che la decisione D9-bis evita: peso, misure e
 * composizione stanno sul telefono, e il fabbisogno e' figlio di quelli.
 *
 * 💡 Qui resta solo il **lavoro in corso**: il PDF che deve leggere l'AI e la
 * bozza che ne esce, il tempo di farla confermare. Confermata, il telefono se
 * la porta via e questa riga si cancella.
 *
 * ── ⚠️ E quindi ha una scadenza ───────────────────────────────────────────
 *
 * Un'importazione che nessuno conferma non deve restare per sempre: e' un PDF
 * con dentro la dieta di qualcuno, cioe' la cosa piu' delicata che passi da
 * qui. Sette giorni sono larghi per chi ci mette qualche giorno a controllare,
 * e stretti abbastanza da non diventare un archivio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importazioni_piani', function (Blueprint $table): void {
            $table->id();

            /*
             * 🚨 `user_id` e non `member_id`: chi importa e' **la persona
             * stessa**, non un trainer che importa per conto suo.
             *
             * ⚠️ Il piano e' suo e di nessun altro (N20.6): nessun trainer deve
             * poterlo vedere o modificare, e non esiste nessuna rotta che lo
             * permetta.
             */
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 💡 Serve al conteggio dei gettoni, che e' per tenant.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Il token del file, come per gli allegati della chat: il PDF vive
             * su disco privato, non in tabella.
             */
            $table->string('token', 64)->unique();
            $table->string('nome_file', 255);
            $table->unsignedInteger('byte_totali');

            $table->string('stato', 16)->default('in_coda');

            /*
             * 🚨 **La decisione del cancello, non il suo ricalcolo.**
             *
             * Quota inclusa o gettoni si decide **una volta sola**, quando si
             * carica il PDF (`CancelloDeiGettoni::apri()`). Fra quel momento e
             * la chiamata al modello c'e' una coda: ricontrollare la quota nel
             * job vorrebbe dire guardare uno stato che nel frattempo e'
             * cambiato — e far pagare 50 gettoni una chiamata che era coperta.
             *
             * ⚠️ Per questo la decisione viaggia su una colonna: e' l'unico
             * modo che ha, per attraversare una coda.
             */
            $table->boolean('paga_con_gettoni')->default(false);

            /*
             * 🚨 La bozza, e **solo** la bozza.
             *
             * ⚠️ Non e' il piano: e' quello che l'AI **crede** di aver letto, e
             * fino alla conferma riga per riga (N20.3) non vale niente. Il nome
             * della colonna lo dice apposta.
             */
            $table->json('bozza')->nullable();
            $table->string('modello_usato', 64)->nullable();

            $table->text('errore')->nullable();

            /*
             * 🚨 **La dichiarazione, con la data** — N20.5.
             *
             * Chi importa dichiara che il piano l'ha redatto un professionista
             * abilitato, e che l'importazione e' sotto la sua responsabilita'.
             * ⚠️ La data conta piu' del booleano: se un giorno qualcuno
             * contestasse, la domanda sara' «cosa aveva dichiarato, e quando».
             */
            $table->timestamp('dichiarato_il')->nullable();

            /*
             * ⚠️ Un'importazione mai confermata non resta per sempre.
             *
             * 🚨 **`dateTime` e non `timestamp`, e non e' pignoleria: con
             * `timestamp` questa migrazione MUORE su MariaDB.**
             *
             * Con `explicit_defaults_for_timestamp = OFF`, solo la **prima**
             * colonna `TIMESTAMP NOT NULL` di una tabella riceve il default
             * implicito `CURRENT_TIMESTAMP`. Qui sopra c'e' gia'
             * `dichiarato_il`, che quel posto lo occupa: `scade_il` resta
             * senza default e in modalita' strict il server risponde
             * *«Invalid default value for 'scade_il'»*.
             *
             * ⚠️ Nella tabella degli allegati (`2026_08_19_100000`) lo stesso
             * `timestamp('scade_il')` funziona **solo perche' li' e' il primo**.
             * E' una regola implicita che dipende dall'ORDINE DELLE COLONNE:
             * bastava aggiungere un campo sopra per rompere una migrazione che
             * girava da settimane. `dateTime` non ha nessuna di queste magie.
             *
             * 💡 Per Laravel non cambia niente: entrambe tornano un Carbon.
             */
            $table->dateTime('scade_il')->index();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importazioni_piani');
    }
};
