<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il sonno nel contesto del consiglio del giorno — 16/08/2026.
 *
 * ── 🚨 Questa colonna riapre una porta che era stata chiusa apposta ───────
 *
 * `sleep` e `vitals` erano nel contesto del consiglio, e sono stati **tolti in
 * S1.5** per la decisione D9: i dati del corpo non escono dal telefono di chi li
 * produce. Il commento in `AiController::contestoConsiglio()` diceva, testuale:
 * *«chi volesse rimetterli deve prima leggere §C12, dove c'e' la ragione legale
 * per cui non ci sono»*.
 *
 * ✅ **§C12 e' stata riletta prima di scrivere questa migrazione**, ed e' il
 * motivo per cui la colonna e' un **consenso** e non un interruttore qualsiasi.
 *
 * ── Cosa dice §C12, e perche' questa strada regge ─────────────────────────
 *
 * | | |
 * |---|---|
 * | Il sonno **e'** dato dell'art. 9 | test del WP29 (2015) + Corte UE C-184/20: basta la **possibilita' di dedurre** |
 * | Il transito **e'** trattamento | art. 4(2), «la comunicazione mediante trasmissione» — «non lo conserviamo» non basta |
 * | Il trasferimento extra-UE e' **gia' coperto** | il DPA di Anthropic con le SCC e' incorporato nei termini commerciali |
 * | Cosa serve davvero | 🎯 **una casella separata e revocabile.** Nessuna istituzione, nessuna notifica |
 *
 * 💡 Quindi la base giuridica e' l'**art. 9(2)(a)**, consenso esplicito, e
 * questa colonna e' la prova che l'art. 7(1) chiede di poter esibire: **una data,
 * non un booleano**. Un `true` senza data non dimostra niente — non dice quando,
 * e quindi non dice sotto quale informativa.
 *
 * ── ⚠️ Perche' NON basta `ai_consent_at` ──────────────────────────────────
 *
 * Perche' sarebbe un consenso «a pacchetto», e l'art. 7 lo vieta: chi accetta
 * che una frase sul pranzo vada a un modello non ha con cio' accettato che ci
 * vada quanto e come dorme. 🚨 Sono due cose di intimita' diversa, e la seconda
 * si puo' rifiutare tenendo la prima.
 *
 * ── ⏸ Una cosa che resta aperta, e va scritta ─────────────────────────────
 *
 * §C12 segnalava di **verificare quali modelli ricadano fra i «covered models»**
 * per la cancellazione entro 30 giorni: da giugno 2026 lo zero-data-retention
 * non copre tutto il traffico. ⚠️ Vale per il cibo come per il sonno, e la
 * risposta non c'e' ancora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            /*
             * ⚠️ `nullable` **senza default**, come gli altri due consensi:
             * `null` vuol dire «non ha acconsentito», e non esiste un valore
             * che voglia dire «non gliel'abbiamo ancora chiesto».
             *
             * 🚨 Nessun backfill, ed e' la cosa piu' importante di questa
             * migrazione: dare per acconsentito chi non ha mai visto la casella
             * sarebbe **un consenso inventato**. Tutti partono da `null`.
             */
            $tabella->timestamp('sleep_ai_consent_at')
                ->nullable()
                ->after('ai_consent_at');

            /*
             * L'interruttore del consiglio automatico — richiesta del
             * committente, 16/08.
             *
             * 💡 **Non e' un consenso**: e' una preferenza. Sta qui accanto
             * perche' riguarda la stessa funzione, ma `null` vale «acceso» —
             * il consiglio e' una funzione del prodotto, non un trattamento in
             * piu'.
             *
             * ⚠️ Percio' e' `boolean` e non `timestamp`: di una preferenza non
             * serve sapere **quando** e' stata cambiata, di un consenso si'.
             */
            $tabella->boolean('consiglio_automatico')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            $tabella->dropColumn(['sleep_ai_consent_at', 'consiglio_automatico']);
        });
    }
};
