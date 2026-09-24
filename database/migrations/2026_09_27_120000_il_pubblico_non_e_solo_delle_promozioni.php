<?php

declare(strict_types=1);

use App\Models\Promozione;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 **Il pubblico smette di essere solo delle promozioni** — blocco B4, 24/09/2026.
 *
 * 📌 La voce 54 di `DA-FARE.md` la chiamava *«la sola vera decisione tecnica»*:
 * per mandare un **avviso in-app** allo stesso pubblico di una promozione, o si
 * rendono generiche `promozione_bersagli` / `promozione_condizioni`, **oppure**
 * l'avviso punta a una promozione che porta il suo pubblico.
 *
 * ══ ✅ SCELTA: TABELLE GENERICHE. E IL PERCHE' ══════════════════════════════
 *
 * ⛔ Far puntare l'avviso a una promozione vorrebbe dire **creare una promozione
 * finta** ogni volta che si manda un avviso che non regala niente: l'elenco
 * delle promozioni si riempirebbe di righe che promozioni non sono, e **ogni
 * numero letto lì dentro sarebbe falso** — quante attive, quante hanno reso,
 * quante ne ha prese una persona.
 *
 * 💡 Le due tabelle non sanno niente di sconti: sanno dire *«chi»*. Quella
 * domanda serve a tutti e due, e serve **identica** — che è la definizione di
 * una cosa da condividere, non da duplicare.
 *
 * ⚠️ È una decisione **mia**, non del committente: è tecnica, non di prodotto.
 * Sta scritta qui e in `memory/plan_blocco_B.md` §5 perché chi la trova sappia
 * che è stata presa apposta.
 *
 * ══ 🚨 COME SI FA SU UNA TABELLA VIVA: COLONNA NUOVA PRIMA ══════════════════
 *
 * ⛔ **Non si rinomina e non si droppa niente adesso.** Si aggiunge il morph, si
 * riempie dalle righe che ci sono, e `promozione_id` **resta** (nullable) finché
 * non è sicuro che nessuno la legge più. 🚨 Una migrazione che toglie una
 * colonna e sbaglia il riempimento **perde il pubblico di ogni promozione
 * attiva**, e non c'è modo di ricostruirlo.
 */
return new class extends Migration
{
    /** Le due tabelle hanno la stessa struttura di aggancio. */
    private const TABELLE = ['promozione_bersagli', 'promozione_condizioni'];

    public function up(): void
    {
        foreach (self::TABELLE as $tabella) {
            Schema::table($tabella, function (Blueprint $t) use ($tabella): void {
                /*
                 * 💡 `pubblico` e non `bersagliabile`: il nome dice **cosa
                 * rappresenta** questa riga — il pubblico di qualcosa — invece
                 * di descrivere il meccanismo di Eloquent che la collega.
                 */
                $t->nullableMorphs('pubblico');
                $t->index(['pubblico_type', 'pubblico_id', 'id'], $tabella.'_pubblico_ordine');
            });

            // 🚨 Le righe che ci sono già sono tutte di promozioni: si riempiono
            //    **prima** che qualcuno legga il morph e trovi `null`.
            DB::table($tabella)
                ->whereNull('pubblico_id')
                ->update([
                    'pubblico_type' => Promozione::class,
                    'pubblico_id' => DB::raw('promozione_id'),
                ]);

            /*
             * ⚠️ `promozione_id` diventa **nullable** e resta: una riga di
             * avviso non ha nessuna promozione a cui appendersi. ⛔ Toglierla
             * adesso vorrebbe dire fidarsi del riempimento qui sopra senza
             * averlo visto funzionare nemmeno una volta.
             */
            Schema::table($tabella, function (Blueprint $t): void {
                $t->unsignedBigInteger('promozione_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLE as $tabella) {
            Schema::table($tabella, function (Blueprint $t) use ($tabella): void {
                $t->dropIndex($tabella.'_pubblico_ordine');
                $t->dropMorphs('pubblico');
            });
        }
    }
};
