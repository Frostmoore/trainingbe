<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le serie di un esercizio, riga per riga — 3b-D.10, 25/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«ogni serie deve avere Ripetizioni, Peso (o niente o Iso.) e Recupero»* ·
 * *«queste modifiche devono riguardare anche l'editor del trainer e quello del
 * server»*.
 *
 * ⛔ Fino a oggi un esercizio aveva **una** prescrizione (`sets` + `reps`), **un**
 * peso e **un** recupero validi per tutte le serie. E' un modello che non sa
 * dire la cosa piu' normale di una scheda vera: *«12 a 40 kg, 10 a 45, 8 a 50»*.
 *
 * ══ 🚨 UNA COLONNA JSON E NON UNA TABELLA, E PERCHE' ══════════════════════
 *
 * 💡 Le righe di una serie **non si interrogano mai**: nessuna query chiede «gli
 * esercizi con almeno una serie sopra i 40 kg». Si leggono e si riscrivono
 * sempre **tutte insieme**, con l'esercizio che le contiene.
 *
 * ⛔ Una tabella `plan_exercise_sets` avrebbe aggiunto una relazione da caricare
 * (e da ricordarsi di caricare, o sono N+1 silenziosi), una cascata da tenere
 * allineata e una transazione piu' larga a ogni salvataggio — per un dato che
 * vive e muore con la sua riga.
 *
 * ✅ E il JSON e' gia' idioma di casa: `exercises.secondary_muscles`,
 * `profiles.meal_hours`, `food_entries.items`.
 *
 * ⚠️ **Se un giorno servisse interrogarle** — statistiche sui carichi
 * prescritti, per dire — allora la tabella diventa giusta e questa colonna e'
 * la sorgente da cui migrarla. Il giorno non e' oggi.
 *
 * ══ ⚠️ NIENTE BACKFILL, E NON E' PIGRIZIA ═════════════════════════════════
 *
 * 🚨 Le righe vecchie restano con `serie = null`, e `PlanExercise::serieRighe()`
 * le **deriva** da `sets` + `reps` + `target_weight` + `rest_sec`.
 *
 * 💡 E' la stessa scelta fatta nell'app (3b-D.1): deriva chi legge, non migra
 * chi scrive. Cosi' funziona anche per le righe che continueranno a nascere
 * senza `serie` — l'importatore di PDF, il pannello di chi non ha ancora
 * aggiornato le sue abitudini, un `create()` in un test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_exercises', function (Blueprint $table): void {
            /*
             * `[{"reps":12,"weight":40,"iso_sec":null,"rest_sec":90}, ...]`
             *
             * 🚨 **La stessa identica forma che l'app scrive gia' sul telefono**
             * (`serie_prevista.dart`). ⛔ Due formati per la stessa cosa
             * avrebbero voluto dire una conversione a ogni confine, e una
             * conversione e' un posto dove perdere un campo in silenzio.
             */
            $table->json('serie')->nullable()->after('target_weight');

            /*
             * Con che cosa si carica: `peso` | `niente` | `iso`.
             *
             * ⚠️ **Non e' deducibile dalle righe**, ed e' per questo che e' una
             * colonna: «peso con i campi vuoti» e «a corpo libero» hanno le
             * stesse righe e vogliono dire due cose diverse.
             *
             * 💡 Sta sull'esercizio e non sulla riga perche' descrive il
             * **movimento**: nessuno fa la prima serie con i manubri e la
             * seconda in isometria.
             */
            $table->string('carico', 8)->default('peso');
        });
    }

    public function down(): void
    {
        Schema::table('plan_exercises', function (Blueprint $table): void {
            $table->dropColumn(['serie', 'carico']);
        });
    }
};
