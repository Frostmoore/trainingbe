<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎟️ **Lo slot sospeso** — 3b-AH.1-ter.6 (18/09/2026).
 *
 * ══ 📌 LA REGOLA, DETTATA DAL COMMITTENTE IL 17/09 ════════════════════════
 *
 * > *«Si deve chiedere: se il trainer decide di mantenere lo slot occupato,
 * > l'allievo passa alla palestra, poi se si dissocia dalla palestra torna al
 * > trainer. Se il trainer decide di liberare lo slot, l'allievo passa alla
 * > palestra e se si dissocia da essa non torna al trainer.»*
 *
 * ══ 🚨 COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * ⛔ **Il posto lo pagavano tutti e due, e non l'aveva deciso nessuno.**
 * `trainer_member` sta fra le tabelle che **restano indietro** quando si entra
 * in una palestra: la riga restava attiva, quindi lo slot restava contato
 * contro il piano del trainer.
 *
 * ⚠️ E quel posto **non dava niente in più**: dal momento in cui quella persona
 * è iscritta alla palestra dove lui allena, la vede comunque. Il legame
 * personale non aggiungeva visibilità, aggiungeva solo un addebito.
 *
 * 💡 **Adesso si paga per prenotarsi il ritorno**, ed è la parte elegante della
 * regola: lo slot occupato non è una tassa, è un'**opzione**.
 *
 * ══ ⚠️ PERCHE' TRE COLONNE E NON UN «SOSPESO» BOOLEANO ════════════════════
 *
 * Perché servono tre risposte diverse, e un booleano ne dà una sola:
 *
 * | Domanda | Colonna |
 * |---|---|
 * | *Questo rapporto è fermo perché è entrata in una palestra?* | `sospeso_il` |
 * | *In quale palestra?* — serve per riattivare **solo** uscendo da **quella** | `sospeso_per_tenant_id` |
 * | *Il trainer ha già risposto?* | `slot_deciso_il` |
 *
 * 🚨 **La terza è quella che nessuno si aspetta.** Senza, non si distingue
 * «ha scelto di tenere lo slot» da «non gliel'abbiamo ancora chiesto» — e sono
 * la stessa riga, con lo stesso aspetto, ma nella seconda al trainer **va
 * ancora fatta una domanda**.
 *
 * ══ 💡 E LA DOMANDA NON HA UNA TABELLA SUA ════════════════════════════════
 *
 * ⛔ Una `domande_in_sospeso` sarebbe uno stato **duplicato**: si può
 * disallineare dalla riga che descrive, e il giorno che si disallinea si chiede
 * due volte la stessa cosa o non si chiede affatto.
 *
 * 💡 Le domande da fare **sono** le righe con `sospeso_il IS NOT NULL AND
 * slot_deciso_il IS NULL AND disattivato_il IS NULL`. Un solo posto in cui la
 * verità vive.
 *
 * ══ ⛔ COSA NON CAMBIA, ED E' VOLUTO ══════════════════════════════════════
 *
 * 🚨 **Una riga sospesa conta ancora lo slot.** `postiRimasti()` filtra su
 * `disattivato_il IS NULL`, e una sospensione **non** valorizza quella colonna:
 * è esattamente il senso di «mantenere lo slot occupato». ⚠️ Chi libera lo slot
 * invece chiude il rapporto, e quello sì che smette di contare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            /*
             * ⚠️ **Sospeso ≠ disattivato**, ed è la distinzione che il piano
             * chiedeva per nome: *«lo stato "sospeso perché è entrato in
             * palestra", distinto da "chiuso"»*.
             */
            $table->timestamp('sospeso_il')->nullable()->after('disattivato_il');

            /*
             * 🚨 **Quale palestra l'ha causata.** Senza, uscendo da una
             * qualunque palestra si riattiverebbero rapporti sospesi da
             * un'altra — e si riaprirebbe un legame che nessuno ha chiesto di
             * riaprire.
             *
             * ⚠️ `nullOnDelete` e non `cascade`: se la palestra sparisce, la
             * riga del rapporto **non** deve sparire con lei.
             */
            $table->foreignId('sospeso_per_tenant_id')
                ->nullable()
                ->after('sospeso_il')
                ->constrained('tenants')
                ->nullOnDelete();

            /** Quando il trainer ha risposto. `null` = glielo si deve ancora chiedere. */
            $table->timestamp('slot_deciso_il')->nullable()->after('sospeso_per_tenant_id');

            /*
             * 💡 L'indice serve alla domanda che si fa a ogni apertura dell'app
             * di un trainer: *«ho decisioni in sospeso?»*.
             */
            $table->index(['trainer_id', 'sospeso_il', 'slot_deciso_il'], 'tm_decisioni_in_sospeso');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            $table->dropIndex('tm_decisioni_in_sospeso');
            $table->dropConstrainedForeignId('sospeso_per_tenant_id');
            $table->dropColumn(['sospeso_il', 'slot_deciso_il']);
        });
    }
};
