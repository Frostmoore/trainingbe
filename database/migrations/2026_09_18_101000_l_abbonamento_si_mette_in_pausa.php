<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⏸️ **L'abbonamento si mette in pausa** — 3b-AH, regola 8.1.6 (18/09/2026).
 *
 * ══ 🚨 IL BUCO CHE CHIUDE, ED E' UN BUCO DI SOLDI VERI ════════════════════
 *
 * 📌 Il committente, 18/09: *«Assicurati che l'abbonamento dell'allievo venga
 * sospeso se si iscrive a una palestra, se era abbonato, e che riprenda quando
 * l'allievo torna libero.»*
 *
 * ⛔ **Oggi non succede niente di tutto questo.** `plan_subscriptions` sta in
 * `UnisciAUnaPalestra::RESTANO_INDIETRO`: entrando in palestra l'abbonamento
 * personale resta su un tenant che viene **sospeso e cancellato**.
 *
 * 🚨 E **nessuno tocca Stripe** — `UnisciAUnaPalestra` non lo nomina mai, e
 * `pause_collection` non esisteva in tutta la codebase. Quindi quella persona
 * **continuava a pagare 7,99 al mese** mentre era già coperta dalla palestra.
 * ⚠️ Nessun errore da nessuna parte: il pagamento riesce, il servizio c'è, e
 * l'unico modo di accorgersene è guardare l'estratto conto.
 *
 * ⛔ E uscendo non tornava niente: `EsciDaUnaPalestra` crea un tenant **nuovo**,
 * quindi l'abbonamento vecchio restava su quello morto.
 *
 * ══ 📌 I GIORNI GIA' PAGATI SI RICONOSCONO — decisione del 18/09 ══════════
 *
 * > *«Si riconoscono alla ripresa.»*
 *
 * Chi paga il 1° ed entra in palestra il 10 ha pagato **venti giorni** che non
 * userà. 💡 Alla ripresa se li ritrova.
 *
 * ⚠️ **Per questo si salva `restavano_giorni` e non solo «era attivo»**: alla
 * ripresa la nuova scadenza è *adesso + quello che restava*, e quel numero
 * dopo non è più ricostruibile — il tenant è cambiato, la data di fine pure.
 * 🚨 È la stessa ragione per cui `addebiti_mensili` **congela** il conteggio:
 * un numero che serve dopo va scritto **quando lo si conosce**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            /*
             * ⚠️ **In pausa ≠ finito.** `ends_at` valorizzato vuol dire
             * «scaduto»; questa colonna vuol dire «fermo, e riprenderà».
             * ⛔ Riusare `ends_at` avrebbe fatto sembrare disdetto un
             * abbonamento che nessuno ha disdetto.
             */
            $table->timestamp('in_pausa_dal')->nullable()->after('ends_at');

            /**
             * Quale palestra ha causato la pausa: si riprende uscendo da
             * **quella**, non da una qualunque.
             */
            $table->foreignId('in_pausa_per_tenant_id')
                ->nullable()
                ->after('in_pausa_dal')
                ->constrained('tenants')
                ->nullOnDelete();

            /*
             * 🚨 **Quanto restava, in giorni, al momento della pausa.**
             *
             * ⛔ Senza questo numero la ripresa non ha da cosa ripartire: il
             * tenant è cambiato e `ends_at` è di un'altra epoca. 💡 Si salva
             * **quando lo si conosce**, che è l'unico momento in cui è vero.
             *
             * ⚠️ `null` su un abbonamento senza scadenza (`ends_at IS NULL`,
             * la convenzione «non scade») — lì non c'è niente da riconoscere,
             * e va distinto da `0`, che vuol dire «era l'ultimo giorno».
             */
            $table->unsignedSmallInteger('restavano_giorni')->nullable()->after('in_pausa_per_tenant_id');

            $table->index(['in_pausa_dal', 'in_pausa_per_tenant_id'], 'ps_in_pausa');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('ps_in_pausa');
            $table->dropConstrainedForeignId('in_pausa_per_tenant_id');
            $table->dropColumn(['in_pausa_dal', 'restavano_giorni']);
        });
    }
};
