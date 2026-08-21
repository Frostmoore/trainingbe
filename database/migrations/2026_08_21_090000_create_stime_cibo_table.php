<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le stime del cibo che aspettano il loro turno — FASE 9.2.
 *
 * ── 🚨 Perche' questa tabella esiste ───────────────────────────────────────
 *
 * Perche' la stima smette di essere una risposta e diventa **un lavoro**: la
 * richiesta HTTP finisce in 50 ms e il modello viene chiamato dopo, da un
 * worker. Fra i due momenti serve un posto dove sta scritto «a che punto e'».
 *
 * ── ⚠️ E' una CACHE, non uno storico ───────────────────────────────────────
 *
 * Vale la regola gia' imparata su `ai_advices` (§46 dell'atlante): una riga qui
 * dentro contiene **un pasto di una persona**, cioe' un dato alimentare in
 * chiaro. Serve per i minuti che passano fra «ho scritto il piatto» e «l'ho
 * confermato»; dopo non serve piu' a nessuno.
 *
 * 🚨 Chi aggiunge una funzione che legge indietro nel tempo deve prima
 * cambiare il registro dei trattamenti, non solo la query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stime_cibo', function (Blueprint $tabella): void {
            $tabella->id();

            $tabella->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $tabella->foreignId('user_id')->constrained()->cascadeOnDelete();

            $tabella->string('stato', 20);

            /** `testo` o `foto`: dice quale quota e' stata impegnata. */
            $tabella->string('origine', 10);

            /*
             * 🚨 **Il pasto sopravvive alla cancellazione della richiesta**, e la
             * differenza va capita: `richiesta` contiene *cosa* ha mangiato una
             * persona ed è il dato personale; `pasto` dice solo **quando** —
             * `lunch`, `dinner` — ed è un'etichetta.
             *
             * ⚠️ Serve a chi riprende una stima dopo aver chiuso l'app (FASE
             * 9.7): senza, il foglio di conferma non saprebbe in quale pasto
             * scrivere, e bisognerebbe richiederlo a chi l'aveva già detto.
             */
            $tabella->string('pasto', 20)->nullable();

            /*
             * 🚨 Il testo del pasto, o il riferimento alla foto depositata.
             * ⚠️ **E' il dato personale di questa tabella**: si svuota appena la
             * stima e' pronta — vedi `StimaCibo::completa()`.
             */
            $tabella->json('richiesta')->nullable();

            /** La `FoodEstimate` piu' gli avvisi del validatore. */
            $tabella->json('risultato')->nullable();

            /*
             * ⚠️ Un **codice**, non una frase. Il testo lo compone l'app, che sa
             * in che lingua sta parlando: una frase italiana scritta qui e' una
             * frase che un domani non si traduce.
             */
            $tabella->string('errore', 60)->nullable();

            /*
             * 🚨 **La decisione del cancello si porta dietro**, non si
             * ricalcola. Fra l'accodamento e il turno del worker la quota
             * inclusa puo' essersi esaurita per altre chiamate: ricontrollarla
             * la' farebbe pagare con i gettoni una stima che era coperta.
             * Stessa ragione gia' scritta in `TrascriviPianoAlimentare`.
             */
            $tabella->boolean('paga_con_gettoni')->default(false);

            $tabella->timestamps();

            /*
             * 💡 L'indice e' su (utente, stato): la domanda che si fa davvero e'
             * «questa persona ha qualcosa in corso?», e la fa l'app a ogni
             * riavvio per ritrovare una stima lasciata a meta' (9.7).
             */
            $tabella->index(['user_id', 'stato']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stime_cibo');
    }
};
