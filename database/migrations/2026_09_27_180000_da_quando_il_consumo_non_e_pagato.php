<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💶 **Da quando la fattura del consumo non è pagata** — 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ══════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«quelli che tu chiami piano a consumo non è
 * nient'altro che un abbonamento dal prezzo variabile in base agli iscritti,
 * quindi può funzionare esattamente come l'abbonamento normale: quando fallisce
 * il pagamento si sospende, stessa cosa di tutti gli abbonamenti»*.
 *
 * 🚨 **E ha ragione: la distinzione che avevo fatto era sbagliata.** Avevo
 * lasciato il consumo fuori dalla sospensione automatica dicendo che «là non c'è
 * un abbonamento che finisce», e da quella premessa avevo dedotto che servisse
 * una decisione commerciale nuova. ⛔ Non serviva: la regola è la stessa, cambia
 * solo **da dove arriva il segnale**.
 *
 * ── ⚠️ Perché serviva una colonna nuova ────────────────────────────────────
 *
 * Per un canone il segnale c'è già: Stripe smette di riprovare, manda
 * `customer.subscription.deleted`, e `ends_at` diventa passato. 🔑 Per il consumo
 * **quell'evento non arriva**, perché la fattura del mese non è un ciclo di
 * abbonamento: è una fattura a sé (`Cassa::eUnAddebitoAConsumo()`).
 *
 * ⛔ Restava `stato = 'fallito'` e nient'altro — nessuna data. 💡 E senza una data
 * non si distingue *«la carta è stata rifiutata stamattina»* da *«non paga da due
 * mesi»*: la prima non deve spegnere niente, la seconda sì.
 *
 * ⚠️ **Si scrive alla PRIMA insolvenza e non si riscrive ai tentativi
 * successivi**: il tempo si conta da quando il pagamento ha cominciato a non
 * riuscire, altrimenti ogni ritentativo di Stripe rimanderebbe la sospensione in
 * avanti e non arriverebbe mai.
 *
 * 💡 Il nome ricalca `plan_subscriptions.pagamento_fallito_il`, che fa la stessa
 * cosa per i canoni: due colonne con lo stesso mestiere devono leggersi uguali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addebiti_mensili', function (Blueprint $table): void {
            $table->timestamp('fallito_il')->nullable()->after('errore');

            /* 💡 Il giro notturno cerca le sole righe insolventi: poche su molte. */
            $table->index('fallito_il');
        });
    }

    public function down(): void
    {
        Schema::table('addebiti_mensili', function (Blueprint $table): void {
            $table->dropIndex(['fallito_il']);
            $table->dropColumn('fallito_il');
        });
    }
};
