<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * I giorni delle schede, e le alternative agli esercizi — G4.1, G4.3 (D2, D10).
 *
 * ── 🚨 Perche' una TABELLA e non una colonna `day_index` ──────────────────
 *
 * Perche' un giorno ha **identita'**: un nome («Giorno A — spinta»), le sue
 * note, e soprattutto — D2 — **puo' essere l'alternativa di un altro giorno**.
 * Un indice numerico non puo' essere l'alternativa di niente.
 *
 * ── 🚨 D2 — un'alternativa e' una riga dello stesso tipo ──────────────────
 *
 *     GIORNO 1  ←── GIORNO 1-bis   (alternativa_di_id → GIORNO 1)
 *       └ Panca piana ←── Panca con manubri
 *
 * Non un JSON e non una tabella polimorfica: **la stessa tabella**, con un
 * riferimento a se' stessa. Cosi' un'alternativa ha esattamente i campi della
 * cosa che sostituisce — che e' l'unico modo perche' sia usabile.
 *
 * ── 🚨 G4.3 — il backfill, che e' la parte che puo' rompere i dati veri ───
 *
 * Ogni scheda esistente riceve **un** giorno, e i suoi esercizi ci vengono
 * attaccati. ⚠️ Senza, le righe gia' scritte restano orfane: non vengono
 * cancellate, **smettono di essere raggiungibili** dalla nuova gerarchia. E'
 * il difetto contro cui `UnisciAUnaPalestra` e' stata scritta — non cancellati,
 * invisibili.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plan_days', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('workout_plan_id')->constrained()->cascadeOnDelete();

            /*
             * 🚨 D2 — l'alternativa e' un giorno, con i suoi esercizi dentro,
             * che sa di sostituirne un altro.
             *
             * ⚠️ `cascadeOnDelete` su se' stessa: cancellare il giorno
             * principale porta via le sue alternative. Il contrario — lasciarle
             * orfane con un `alternativa_di_id` che punta al nulla — le
             * farebbe comparire come giorni veri, cioe' raddoppierebbe la
             * scheda senza che nessuno capisca perche'.
             */
            $table->foreignId('alternativa_di_id')->nullable()
                ->constrained('workout_plan_days')->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            // «Giorno A — spinta». ⚠️ `null` e' legittimo: una scheda a un solo
            // giorno non deve mostrare un'intestazione che nessuno ha scritto.
            $table->string('name', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['workout_plan_id', 'position']);
            $table->index('alternativa_di_id');
        });

        Schema::table('plan_exercises', function (Blueprint $table): void {
            // ⚠️ Nullable **adesso**, popolata dal backfill, resa obbligatoria in fondo.
            $table->foreignId('workout_plan_day_id')->nullable()
                ->constrained()->cascadeOnDelete()->after('workout_plan_id');

            // D10 — «panca piana **oppure** panca con manubri».
            $table->foreignId('alternativa_di_id')->nullable()
                ->constrained('plan_exercises')->cascadeOnDelete();

            $table->index('alternativa_di_id');
        });

        $this->portaDietroLeSchede();

        Schema::table('plan_exercises', function (Blueprint $table): void {
            /*
             * 🚨 **Obbligatoria da qui in avanti.** Un esercizio senza giorno e'
             * un esercizio che nessuna schermata mostra: lasciare la colonna
             * nullable vorrebbe dire permettere di scriverne altri cosi', e
             * scoprirlo solo quando un trainer dice «la scheda e' vuota».
             */
            $table->foreignId('workout_plan_day_id')->nullable(false)->change();
        });
    }

    /**
     * Un giorno per ogni scheda esistente, e gli esercizi ci finiscono dentro.
     *
     * ⚠️ **Anche le schede soft-deleted.** Hanno i loro esercizi, e una
     * `deleted_at` si puo' togliere: una scheda ripristinata che tornasse senza
     * esercizi sarebbe peggio di una scheda cancellata.
     */
    private function portaDietroLeSchede(): void
    {
        $schede = DB::table('workout_plans')->pluck('id');
        $adesso = now();
        $esercizi = 0;

        foreach ($schede as $schedaId) {
            $giornoId = DB::table('workout_plan_days')->insertGetId([
                'workout_plan_id' => $schedaId,
                'alternativa_di_id' => null,
                'position' => 0,
                // ⚠️ `null` e non «Giorno 1»: una scheda che il trainer non ha
                // mai pensato come multi-giorno non deve improvvisamente
                // mostrare un'intestazione che lui non ha scritto.
                'name' => null,
                'notes' => null,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ]);

            $esercizi += DB::table('plan_exercises')
                ->where('workout_plan_id', $schedaId)
                ->update(['workout_plan_day_id' => $giornoId]);
        }

        Log::info('G4.3 — giorni creati per le schede esistenti', [
            'schede' => $schede->count(),
            'esercizi_riattaccati' => $esercizi,
        ]);

        /*
         * 🚨 **La verifica, dentro la migrazione stessa.** Se resta anche un
         * solo esercizio senza giorno, la `NOT NULL` che segue fallirebbe con un
         * errore di database incomprensibile — e a meta' migrazione. Meglio un
         * messaggio che dice cosa e' successo.
         */
        $orfani = DB::table('plan_exercises')->whereNull('workout_plan_day_id')->count();

        if ($orfani > 0) {
            throw new RuntimeException(
                "G4.3: {$orfani} plan_exercises sono rimasti senza giorno. "
                .'Hanno un workout_plan_id che non esiste in workout_plans?',
            );
        }
    }

    public function down(): void
    {
        Schema::table('plan_exercises', function (Blueprint $table): void {
            $table->dropForeign(['alternativa_di_id']);
            $table->dropForeign(['workout_plan_day_id']);
            $table->dropColumn(['alternativa_di_id', 'workout_plan_day_id']);
        });

        Schema::dropIfExists('workout_plan_days');
    }
};
