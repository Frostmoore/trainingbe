<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📣 **Gli avvisi in-app** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«io (god) devo poter mandare notifiche a chi
 * voglio io — gruppi di pubblico o utenti individuali, usiamo gli stessi che
 * possono essere destinatari di una promozione — facendo apparire una modale
 * in-app con un tasto per approvare (testo che deciderò di volta in volta) e uno
 * per rifiutare. Il tasto per approvare deve poter fare una serie di cose:
 * dismiss, link, promo, e logga. Rifiuta chiude sempre la modale e logga il
 * rifiuto»*.
 *
 * ══ 🚨 IL PUBBLICO NON STA QUI ══════════════════════════════════════════════
 *
 * ⛔ Niente bersagli né condizioni in questa tabella: stanno in
 * `promozione_bersagli` e `promozione_condizioni`, che dal 24/09 sono
 * **generiche** (morph `pubblico`). 💡 *«Gli stessi che possono essere
 * destinatari di una promozione»* non era una cosa da costruire: era una cosa da
 * **puntare**.
 *
 * ══ ⚠️ DUE TABELLE, E LA SECONDA E' META' DEL LAVORO ════════════════════════
 *
 * | Tabella | A cosa serve |
 * |---|---|
 * | `avvisi` | cosa dice, cosa fanno i due tasti, quando vale |
 * | `avvisi_decisioni` | **chi l'ha visto e cosa ha risposto** |
 *
 * 🚨 La seconda non è un registro «per le statistiche»: è **la memoria** che
 * impedisce di richiedere la stessa cosa due volte, ed è la difesa il giorno in
 * cui qualcuno dice *«non ho mai accettato»*. ⚠️ Sta **sul server** e non nelle
 * preferenze del telefono, o chi entra dal secondo dispositivo se la ripiglia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avvisi', function (Blueprint $t): void {
            $t->id();

            $t->string('titolo', 120);
            $t->text('testo');

            /*
             * 🏷️ **Le etichette dei due tasti le scrive il committente ogni
             * volta** — *«testo che deciderò di volta in volta»*. ⚠️ Con un
             * valore di serie, perché un avviso senza etichette è una modale
             * con due pulsanti muti.
             */
            $t->string('tasto_approva', 40)->default('Va bene');
            $t->string('tasto_rifiuta', 40)->default('No, grazie');

            /*
             * 🎯 Cosa fa «approva»: `dismiss` | `link` | `promo`.
             *
             * ⛔ **L'azione `promo` si esegue sul SERVER**, dentro la risposta:
             * farla eseguire all'app vorrebbe dire lasciare che sia il telefono
             * a dire «assegnami questa promozione».
             */
            $t->string('azione', 20)->default('dismiss');

            /** Per `link`: dove va. */
            $t->string('url', 500)->nullable();

            /** Per `promo`: quale. ⚠️ `nullOnDelete`: se sparisce la promozione, l'avviso resta ma non regala più niente. */
            $t->foreignId('promozione_id')->nullable()->constrained('promozioni')->nullOnDelete();

            /*
             * 📅 La finestra di validità. 💡 Un avviso scaduto non si mostra
             * più: *«la palestra è chiusa domani»* letto una settimana dopo non
             * è un avviso, è rumore.
             */
            $t->timestamp('inizia_il')->nullable();
            $t->timestamp('scade_il')->nullable();

            /*
             * 🚨 **La spunta che scavalca il tetto di frequenza** — decisione
             * mia del 24/09, scritta in `memory/decisioni.md`.
             *
             * ⚠️ Un avviso di servizio — *«oggi non si riesce ad accedere»* —
             * non può essere buttato via da un limite pensato per le
             * promozioni. ⛔ Ma si vede nei numeri: un'eccezione invisibile
             * diventa la regola.
             */
            $t->boolean('urgente')->default(false);

            /** Si manda anche la push? 📌 Committente, 24/09: *«sì»*. */
            $t->boolean('anche_push')->default(true);

            $t->boolean('attivo')->default(false);

            $t->timestamps();

            $t->index(['attivo', 'inizia_il', 'scade_il']);
        });

        Schema::create('avvisi_decisioni', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('avviso_id')->constrained('avvisi')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * 👀 **Quando gliel'abbiamo mostrato.** 💡 È il momento che conta
             * per il tetto di frequenza: un avviso mai visto non ha dato
             * fastidio a nessuno, e non deve rubare il posto a uno che servirà.
             */
            $t->timestamp('mostrato_il')->nullable();

            /** `approvato` | `rifiutato`, e quando. ⚠️ `null` = ancora da decidere. */
            $t->string('decisione', 20)->nullable();
            $t->timestamp('deciso_il')->nullable();

            $t->timestamps();

            // ⛔ Una riga per persona × avviso: la decisione è una sola.
            $t->unique(['avviso_id', 'user_id']);

            // 🔎 «Cosa devo mostrare a questa persona?» è la domanda di ogni
            //    apertura dell'app: senza indice è una scansione a ogni avvio.
            $t->index(['user_id', 'deciso_il']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avvisi_decisioni');
        Schema::dropIfExists('avvisi');
    }
};
