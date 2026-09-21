<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 🔒 **I documenti importati dal pannello, spostati sul disco privato** —
 * 23/09/2026, fase F7.
 *
 * ══ 🚨 IL DIFETTO ════════════════════════════════════════════════════════
 *
 * Il PDF che una palestra carica per importare una scheda finiva sul disco
 * **pubblico** (`storage/app/public`, servito da `/storage`). ⛔ Il commento
 * che lo accompagnava diceva il contrario, e nessuno se n'era accorto:
 * medialibrary senza `useDisk()` usa proprio `public`.
 *
 * 🚨 Raggiungibile da chiunque all'indirizzo `/storage/{id}/{nome}.pdf`, con
 * `id` sequenziali. Dentro c'è la scheda di una persona, spesso con
 * l'intestazione dello studio e il nome del paziente.
 *
 * ══ ✅ COSA FA ═══════════════════════════════════════════════════════════
 *
 * Per ogni documento di `WorkoutPlanImport` ancora su `public`: copia il file
 * su `local`, **verifica che la copia ci sia**, aggiorna la riga, e solo dopo
 * cancella l'originale.
 *
 * ⚠️ **In quest'ordine, e non per pignoleria**: se si cancellasse prima e la
 * copia fallisse, il documento sparirebbe per sempre — e con lui l'unico modo
 * di confrontare una scheda importata con la sua fonte.
 *
 * 💡 Un file che non c'è più (cancellato a mano, disco rifatto) non ferma la
 * migrazione: si scrive nel log e si va avanti. ⛔ Fermarsi vorrebbe dire
 * bloccare il deploy per un file che comunque non si può recuperare.
 *
 * ── ⛔ Il `down()` non lo rimette pubblico ─────────────────────────────────
 *
 * Tornare indietro vorrebbe dire **riaprire** il buco. Se servisse davvero, la
 * strada è a mano e consapevole, non un `migrate:rollback` distratto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $righe = DB::table('media')
            ->where('model_type', 'App\\Models\\WorkoutPlanImport')
            ->where('disk', 'public')
            ->get(['id', 'file_name', 'conversions_disk']);

        foreach ($righe as $riga) {
            /*
             * 📁 Il percorso di medialibrary col generatore di serie:
             * `{id}/{file_name}`. ⚠️ Se un giorno si cambiasse il
             * `path_generator`, questa riga andrebbe cambiata insieme.
             */
            $percorso = $riga->id.'/'.$riga->file_name;

            $pubblico = Storage::disk('public');
            $privato = Storage::disk('local');

            if (! $pubblico->exists($percorso)) {
                Log::warning('Documento importato non trovato sul disco pubblico: lo segno privato e basta.', [
                    'media' => $riga->id,
                ]);

                DB::table('media')->where('id', $riga->id)->update(['disk' => 'local', 'conversions_disk' => 'local']);

                continue;
            }

            $privato->put($percorso, $pubblico->get($percorso));

            // 🚨 Si cancella l'originale SOLO se la copia c'è davvero.
            if (! $privato->exists($percorso)) {
                throw new RuntimeException("Copia sul disco privato non riuscita per il documento {$riga->id}: mi fermo.");
            }

            DB::table('media')->where('id', $riga->id)->update(['disk' => 'local', 'conversions_disk' => 'local']);

            $pubblico->deleteDirectory((string) $riga->id);
        }
    }

    public function down(): void
    {
        // ⛔ Di proposito: vedi il blocco in testa.
    }
};
