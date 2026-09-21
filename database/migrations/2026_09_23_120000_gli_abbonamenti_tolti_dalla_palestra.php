<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ✂️ **Quando una palestra toglie l'abbonamento a un iscritto** — 23/09/2026, F5.
 *
 * 📌 Il committente, sull'elenco degli iscritti: *«mi serve il tasto per
 * togliere l'abbonamento all'allievo»* e *«mi serve la revenue totale
 * dall'allievo (quindi prezzo fatto all'allievo meno costo dello slot tutto per
 * tutti i rinnovi dell'abbonamento pagati dall'allievo)»*.
 *
 * ══ 🚨 PERCHÉ SI REGISTRA IL «TOLTO» E NON IL «DATO» ═════════════════════
 *
 * In questo impianto un iscritto è abbonato **perché sta in una palestra
 * abbonata**: nessuna riga lo dice, è la conseguenza del suo tenant.
 *
 * ⛔ Una tabella delle **coperture** («da quando a quando la palestra gli paga
 * l'abbonamento») avrebbe voluto dire scrivere una riga **a ogni ingresso** in
 * palestra — invito, registrazione con invito, creazione dal pannello,
 * accesso social, trasloco da un trainer. 🚨 Ne basta uno dimenticato perché un
 * iscritto nuovo risulti **senza abbonamento** fin dal primo giorno, e senza
 * nessun errore da nessuna parte.
 *
 * ✅ Qui si registra **l'eccezione**: il periodo in cui la palestra glielo ha
 * tolto. Nessuna riga = coperto, com'è sempre stato. 💡 Non c'è nessun
 * ingresso da ricordare, e le palestre di oggi non cambiano di un millimetro.
 *
 * ══ ⚠️ COSA SUCCEDE A CHI NON È PIÙ COPERTO ═════════════════════════════
 *
 * | | Coperto | Tolto |
 * |---|---|---|
 * | Resta nella palestra | ✅ | ✅ — chat, schede, trainer |
 * | È abbonato (`eAbbonato`) | ✅ | ⛔ ricade sul gratuito |
 * | Occupa un posto pagato | ✅ | ⛔ **no**: è il motivo per cui si toglie |
 *
 * 🚨 L'ultima riga **cambia quanto paga la palestra** su un piano a consumo:
 * `ContoDegliIscritti` smette di contarlo. È voluto — togliere l'abbonamento a
 * qualcuno che continua a costare non avrebbe senso — ed è scritto qui perché
 * è la conseguenza più pesante di tutte.
 *
 * ── 💡 Un periodo, non un interruttore ────────────────────────────────────
 *
 * `dal` / `al`, e non un booleano: la revenue si calcola sui **mesi in cui era
 * coperto**, e un interruttore dice solo com'è adesso. ⛔ Con un booleano,
 * togliere e ridare l'abbonamento cancellerebbe la storia di quanto quella
 * persona ha reso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abbonamenti_tolti', function (Blueprint $tabella): void {
            $tabella->id();

            // La palestra che l'ha tolto. ⚠️ Resta alla palestra se lui se ne va.
            $tabella->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $tabella->foreignId('user_id')->constrained()->cascadeOnDelete();

            $tabella->timestamp('dal');

            /** ⚠️ `null` = **tolto adesso**. Una data = glielo hanno ridato. */
            $tabella->timestamp('al')->nullable();

            // Chi l'ha tolto e chi l'ha ridato: sono soldi, e resta scritto chi.
            $tabella->foreignId('tolto_da_id')->nullable()->constrained('users')->nullOnDelete();
            $tabella->foreignId('ridato_da_id')->nullable()->constrained('users')->nullOnDelete();

            $tabella->timestamps();

            /*
             * 🚨 **L'indice è per `PianoAttivo::per()`**, che se lo chiede a ogni
             * chiamata all'AI: «questa persona, in questa palestra, ha un
             * periodo aperto?». Senza, sarebbe una scansione per ogni richiesta.
             */
            $tabella->index(['tenant_id', 'user_id', 'al'], 'abbonamenti_tolti_aperti_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abbonamenti_tolti');
    }
};
