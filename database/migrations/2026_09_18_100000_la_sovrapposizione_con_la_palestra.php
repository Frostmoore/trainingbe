<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎟️ **La sovrapposizione fra un trainer e la palestra dove il suo allievo
 * entra** — 3b-AH.1-ter.6 (18/09/2026).
 *
 * ══ 🚨 COSA NON E', E VA DETTO PRIMA ══════════════════════════════════════
 *
 * ⛔ **Questo NON sospende il rapporto fra allievo e trainer.** Nessuna colonna
 * qui dentro lo interrompe: `assignedMembers()` e `assignedTrainers()` non le
 * guardano, quindi il trainer continua a vedere il suo allievo e l'allievo il
 * suo trainer, esattamente come prima.
 *
 * 📌 Il committente, 18/09, correggendo proprio questo fraintendimento:
 *
 * > *«L'allievo di un free_trainer che si iscrive in una palestra non deve
 * > avere il rapporto col free_trainer sospeso: un allievo deve poter essere
 * > iscritto a infiniti free_trainer e una sola palestra, anche
 * > contemporaneamente. L'unico caso limite è se un allievo di un free trainer
 * > si iscrive in una palestra in cui lavora quel free trainer: allora il free
 * > trainer deve ricevere la modale che gli chiede se vuole mantenere lo slot
 * > occupato o liberarlo.»*
 *
 * 💡 **L'unica cosa in ballo sono i soldi**: chi paga il posto di quella
 * persona. Il rapporto non c'entra e non si tocca.
 *
 * ══ ⚠️ IL CASO LIMITE, E SOLO QUELLO ══════════════════════════════════════
 *
 * Queste colonne si valorizzano **esclusivamente** quando accadono tutte e due
 * le cose insieme:
 *
 * 1. l'allievo entra in una palestra;
 * 2. **quel** trainer lavora in **quella** palestra.
 *
 * 🚨 Un trainer che non lavora lì non viene toccato: due cose separate, e
 * marcarlo vorrebbe dire fargli una domanda che non lo riguarda.
 *
 * ══ 🚨 PERCHE' TRE COLONNE E NON UN BOOLEANO ══════════════════════════════
 *
 * | Domanda | Colonna |
 * |---|---|
 * | *Questo allievo è entrato in una palestra dove lavoro anch'io?* | `sovrapposto_dal` |
 * | *In quale?* — serve per chiudere la sovrapposizione **solo** uscendo da **quella** | `sovrapposto_in_tenant_id` |
 * | *Il trainer ha già risposto?* | `slot_deciso_il` |
 *
 * 🚨 **La terza è quella che nessuno si aspetta.** Senza, non si distingue
 * «ha scelto di tenere lo slot» da «non gliel'abbiamo ancora chiesto» — stessa
 * riga, stesso aspetto, ma nella seconda al trainer **va ancora fatta una
 * domanda**.
 *
 * ══ 💡 E LA DOMANDA NON HA UNA TABELLA SUA ════════════════════════════════
 *
 * ⛔ Una `domande_in_sospeso` sarebbe uno stato **duplicato**: si disallinea
 * dalla riga che descrive, e allora si chiede due volte o non si chiede.
 * 💡 Le domande **sono** le righe con `sovrapposto_dal IS NOT NULL AND
 * slot_deciso_il IS NULL AND disattivato_il IS NULL`.
 *
 * ══ ⛔ COSA CAMBIA DAVVERO, CIOE' I SOLDI ═════════════════════════════════
 *
 * 🚨 **Una riga sovrapposta conta ancora lo slot**, ed è il senso di
 * «mantenere lo slot occupato»: `postiRimasti()` filtra su `disattivato_il`, e
 * la sovrapposizione non valorizza quella colonna. ⚠️ Chi **libera** lo slot
 * chiude il rapporto — e quello sì che smette di contare, e non torna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            /**
             * Da quando questo allievo è dentro una palestra dove lavora anche
             * questo trainer. ⛔ **Non** vuol dire «rapporto fermo».
             */
            $table->timestamp('sovrapposto_dal')->nullable()->after('disattivato_il');

            /*
             * 🚨 **Quale palestra.** Senza, uscendo da una qualunque palestra si
             * chiuderebbero sovrapposizioni nate altrove — e si rimetterebbe in
             * discussione uno slot che nessuno aveva toccato.
             *
             * ⚠️ `nullOnDelete` e non `cascade`: se la palestra sparisce, la
             * riga del rapporto **non** deve sparire con lei.
             */
            $table->foreignId('sovrapposto_in_tenant_id')
                ->nullable()
                ->after('sovrapposto_dal')
                ->constrained('tenants')
                ->nullOnDelete();

            /** Quando il trainer ha risposto. `null` = glielo si deve ancora chiedere. */
            $table->timestamp('slot_deciso_il')->nullable()->after('sovrapposto_in_tenant_id');

            /*
             * 💡 Serve alla domanda che si fa a ogni apertura dell'app di un
             * trainer: *«ho scelte in sospeso?»*.
             */
            $table->index(['trainer_id', 'sovrapposto_dal', 'slot_deciso_il'], 'tm_sovrapposizioni_aperte');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_member', function (Blueprint $table): void {
            $table->dropIndex('tm_sovrapposizioni_aperte');
            $table->dropConstrainedForeignId('sovrapposto_in_tenant_id');
            $table->dropColumn(['sovrapposto_dal', 'slot_deciso_il']);
        });
    }
};
