<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il consiglio del giorno si rigenera **tre volte al giorno** — 3b-AB.
 *
 * ══ 🚨 COSA CAMBIA, E PERCHE' L'INDICE NON PUO' RESTARE ═══════════════════
 *
 * L'indice unico era `(user_id, date, kind, context_hash)`, e la migration che
 * l'ha creato lo spiegava cosi': *«a ogni pasto o allenamento nuovo l'hash
 * cambia, quindi il consiglio si aggiorna quando ha senso aggiornarlo»*.
 *
 * ⛔ **Ed e' proprio la riga che costava.** «Quando ha senso» voleva dire *a
 * ogni pasto*: colazione, spuntino, pranzo, merenda, cena e allenamento sono
 * sei contesti diversi, cioe' **sei chiamate al modello in un giorno**, tutte
 * automatiche e nessuna chiesta da nessuno.
 *
 * 📌 Il committente, il 30/08/2026: *«il consiglio del giorno si rigeneri in
 * automatico solo 3 volte al giorno (9:00, 14:00 e 22:00)»*.
 *
 * 💡 Quindi la chiave diventa la **fascia**: dentro una fascia il consiglio e'
 * uno, qualunque cosa succeda al contesto.
 *
 * ══ ⚠️ `context_hash` RESTA, MA NON DECIDE PIU' NIENTE ════════════════════
 *
 * Non si toglie perche' e' l'unico modo, guardando una riga, di sapere **con
 * che contesto** e' nato quel testo. 🚨 Ma non entra piu' in nessun indice e
 * nessuna query lo cerca: se un domani qualcuno lo rimette in una `where`,
 * rimette in piedi la spesa che questa migration toglie.
 *
 * ══ 🗑️ PERCHE' LE RIGHE VECCHIE SI BUTTANO ════════════════════════════════
 *
 * Perche' non hanno una fascia, e inventargliela vorrebbe dire scrivere in
 * colonna un valore che nessuno ha calcolato. ⚠️ Due righe dello stesso giorno
 * con hash diversi diventerebbero la stessa fascia, e l'indice unico
 * rifiuterebbe la migration a meta'.
 *
 * 💡 **E non si perde niente**: questa tabella e' una cache che `AiAdvice::pota()`
 * gia' svuota a ogni scrittura di un giorno nuovo. Chi ha un consiglio a
 * schermo ce l'ha anche sul telefono (`consiglio.ultimo`), che e' il posto da
 * cui l'app lo rilegge quando il server non ce l'ha.
 *
 * 🚨 Ed e' il testo piu' intimo che teniamo sul server: buttarne un giorno non
 * e' un costo, e' esattamente quello che la potatura fa ogni giorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🗑️ Vedi la nota in testa: e' una cache, e le righe vecchie non hanno
        // una fascia da mettere in colonna.
        DB::table('ai_advices')->delete();

        Schema::table('ai_advices', function (Blueprint $table): void {
            /*
             * `2026-08-30T09` — giorno della fascia piu' ora del confine.
             *
             * 🚨 **Il giorno della FASCIA, non quello in cui si scrive.** Un
             * consiglio generato alle 07:00 appartiene alla fascia delle 22 di
             * *ieri*, e porta la data di ieri. Vedi `FasciaDelConsiglio`.
             */
            $table->string('fascia', 16)->after('date');
        });

        /*
         * ══ 🚨 PRIMA SI CREA IL NUOVO, POI SI TOGLIE IL VECCHIO ════════════
         *
         * ⛔ **L'ordine inverso non funziona, e l'errore non e' ovvio**:
         *
         *   SQLSTATE[HY000] 1553: Cannot drop index 'ai_advices_unique':
         *   needed in a foreign key constraint
         *
         * `ai_advices_unique` comincia per `user_id`, e MySQL lo sta usando
         * come indice della **foreign key** `user_id`. Toglierlo per primo
         * lascerebbe la chiave esterna scoperta, e MySQL si rifiuta.
         *
         * 💡 Anche il nuovo indice comincia per `user_id`: creato **prima**, la
         * foreign key ha gia' su cosa appoggiarsi quando il vecchio se ne va.
         *
         * ⚠️ Il nome dev'essere diverso finche' convivono — da qui
         * `ai_advices_fascia_unique`, che resta il nome definitivo: rinominarlo
         * dopo vorrebbe dire un terzo giro di `ALTER TABLE` per niente.
         */
        Schema::table('ai_advices', function (Blueprint $table): void {
            /*
             * ⛔ **Niente `date` e niente `context_hash` nella chiave.** La
             * fascia contiene gia' il giorno, e l'hash e' proprio la cosa che
             * faceva rigenerare a ogni pasto.
             */
            $table->unique(['user_id', 'fascia', 'kind'], 'ai_advices_fascia_unique');
        });

        Schema::table('ai_advices', function (Blueprint $table): void {
            $table->dropUnique('ai_advices_unique');
        });
    }

    public function down(): void
    {
        DB::table('ai_advices')->delete();

        // ⚠️ Stesso ordine, al contrario: prima l'indice che reggera' la
        // foreign key, poi via quello che la regge adesso.
        Schema::table('ai_advices', function (Blueprint $table): void {
            $table->unique(['user_id', 'date', 'kind', 'context_hash'], 'ai_advices_unique');
        });

        Schema::table('ai_advices', function (Blueprint $table): void {
            $table->dropUnique('ai_advices_fascia_unique');
        });

        Schema::table('ai_advices', function (Blueprint $table): void {
            $table->dropColumn('fascia');
        });
    }
};
