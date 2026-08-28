<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un esercizio puo' rinviare a un altro — 3b-O, 28/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«vorrei che facessi in modo che gli esercizi che ho io nelle schede siano
 * quelli che abbiamo nel database. Senza farmi perdere nulla, naturalmente»*.
 *
 * ══ 🚨 PERCHE' NON SI CANCELLA E BASTA ════════════════════════════════════
 *
 * Fondere due esercizi vuol dire ripuntare le schede dal vecchio al nuovo. La
 * cosa ovvia sarebbe poi **cancellare** il vecchio. ⛔ E sarebbe il modo di
 * perdere quello che il committente ha chiesto di non perdere.
 *
 * 🚨 **Lo storico degli allenamenti sta sul telefono** (FASE 11), in
 * `SerieDelleSedute.esercizioId`, e usa **l'id vecchio**. Un telefono che non
 * si sincronizza da settimane — o che si riaccende fra sei mesi — deve poter
 * ancora capire dove sono finite le sue serie. ⚠️ Con la riga cancellata non
 * c'e' piu' niente da seguire: le serie restano, e diventano **orfane**. Non
 * cancellate: peggio, perche' nessuno se ne accorge.
 *
 * 💡 La riga resta, e porta scritto dove e' andato il suo contenuto. Il rinvio
 * e' permanente per costruzione, e non ha una scadenza da ricordarsi.
 *
 * ── ⚠️ Perche' `nullOnDelete` e non `cascade` ─────────────────────────────
 *
 * Se un giorno sparisse l'esercizio **di destinazione**, il rinvio diventa
 * `null` e la riga vecchia torna a valere per conto suo. ⛔ Con `cascade`
 * sparirebbe anche lei, portandosi dietro la sola traccia che collegava quelle
 * serie a qualcosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->foreignId('sostituito_da_id')
                ->nullable()
                ->after('is_custom')
                ->constrained('exercises')
                ->nullOnDelete();

            /*
             * 💡 L'indice serve a **una** domanda, ed e' quella che l'app fa a
             * ogni avvio: «quali rinvii esistono?». Sono pochissime righe su
             * un catalogo grande, ed e' esattamente il caso in cui un indice
             * paga.
             */
            $table->index('sostituito_da_id');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->dropForeign(['sostituito_da_id']);
            $table->dropIndex(['sostituito_da_id']);
            $table->dropColumn('sostituito_da_id');
        });
    }
};
