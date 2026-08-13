<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * I giorni dei piani alimentari, e le alternative a tre livelli — G4.2, G4.5.
 *
 * Stessa forma delle schede (D2): **un'alternativa e' una riga dello stesso
 * tipo**, con `alternativa_di_id`. Qui i livelli sono tre — giorno, pasto,
 * alimento — e la regola e' identica in tutti e tre.
 *
 * ── 🚨 G4.5 — le alternative da JSON a righe ─────────────────────────────
 *
 * `nutrition_plan_items.alternatives` e' oggi una colonna `text` con dentro
 * JSON. ⚠️ **Un'alternativa scritta cosi' non ha macro proprie**: nel momento in
 * cui la persona sceglie il merluzzo, il diario non sa cosa scrivere — e
 * importare nel diario e' esattamente cio' che il committente ha chiesto.
 *
 * 💡 Se un'alternativa deve avere gli stessi campi della cosa che sostituisce,
 * allora **e' la stessa tabella**.
 *
 * ⚠️ **In staging non c'e' niente da convertire**: `G0.2` ha contato zero piani
 * alimentari. La conversione va scritta lo stesso — un giorno ci saranno dati —
 * ma il suo banco di prova sono i test, non lo staging. Una migrazione che in
 * staging non fa niente **non dimostra niente**.
 */
return new class extends Migration
{
    /** D2 — il massimo di alternative per livello. */
    private const MAX_ALTERNATIVE = 3;

    public function up(): void
    {
        Schema::create('nutrition_plan_days', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('nutrition_plan_id')->constrained()->cascadeOnDelete();

            $table->foreignId('alternativa_di_id')->nullable()
                ->constrained('nutrition_plan_days')->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['nutrition_plan_id', 'position']);
            $table->index('alternativa_di_id');
        });

        Schema::table('nutrition_plan_meals', function (Blueprint $table): void {
            $table->foreignId('nutrition_plan_day_id')->nullable()
                ->constrained()->cascadeOnDelete()->after('nutrition_plan_id');

            $table->foreignId('alternativa_di_id')->nullable()
                ->constrained('nutrition_plan_meals')->cascadeOnDelete();

            $table->index('alternativa_di_id');
        });

        Schema::table('nutrition_plan_items', function (Blueprint $table): void {
            $table->foreignId('alternativa_di_id')->nullable()
                ->constrained('nutrition_plan_items')->cascadeOnDelete();

            /*
             * D13 — chi ha scritto questi valori.
             *
             * 💡 Serve **al trainer**, per vedere a colpo d'occhio cosa ha gia'
             * controllato — non a noi per discutere: la modifica a mano vince
             * sempre, perche' e' lui il professionista.
             *
             * ⚠️ Default `manual`: le righe gia' scritte le ha messe una persona,
             * non l'AI. Il contrario attribuirebbe all'AI un lavoro che non ha
             * fatto.
             */
            $table->string('origine_valori', 8)->default('manual');

            $table->index('alternativa_di_id');
        });

        $this->portaDietroIPiani();
        $this->convertiLeAlternative();

        Schema::table('nutrition_plan_meals', function (Blueprint $table): void {
            $table->foreignId('nutrition_plan_day_id')->nullable(false)->change();
        });
    }

    /** Un giorno per ogni piano esistente, e i pasti ci finiscono dentro. */
    private function portaDietroIPiani(): void
    {
        $piani = DB::table('nutrition_plans')->pluck('id');
        $adesso = now();
        $pasti = 0;

        foreach ($piani as $pianoId) {
            $giornoId = DB::table('nutrition_plan_days')->insertGetId([
                'nutrition_plan_id' => $pianoId,
                'alternativa_di_id' => null,
                'position' => 0,
                'name' => null,
                'notes' => null,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ]);

            $pasti += DB::table('nutrition_plan_meals')
                ->where('nutrition_plan_id', $pianoId)
                ->update(['nutrition_plan_day_id' => $giornoId]);
        }

        Log::info('G4.3 — giorni creati per i piani esistenti', [
            'piani' => $piani->count(),
            'pasti_riattaccati' => $pasti,
        ]);

        $orfani = DB::table('nutrition_plan_meals')->whereNull('nutrition_plan_day_id')->count();

        if ($orfani > 0) {
            throw new RuntimeException(
                "G4.3: {$orfani} nutrition_plan_meals sono rimasti senza giorno.",
            );
        }
    }

    /**
     * G4.5 — ogni voce del JSON diventa una riga alternativa.
     *
     * ⚠️ **Le voci oltre la terza si perdono, e vanno contate.** Se ce ne sono,
     * un trainer aveva scritto piu' di tre alternative: meglio saperlo dal log
     * che da lui.
     *
     * 🚨 La colonna `alternatives` **non si toglie**: si svuota. Toglierla
     * adesso renderebbe impossibile rifare la conversione se i conti non
     * tornassero — e i conti si guardano dopo, non durante.
     */
    private function convertiLeAlternative(): void
    {
        $conAlternative = DB::table('nutrition_plan_items')
            ->whereNotNull('alternatives')
            ->where('alternatives', '!=', '')
            ->where('alternatives', '!=', '[]')
            ->get();

        $adesso = now();
        $create = 0;
        $scartate = 0;
        $illeggibili = 0;

        foreach ($conAlternative as $item) {
            $voci = json_decode((string) $item->alternatives, true);

            if (! is_array($voci)) {
                // ⚠️ Non si butta e non si esplode: si conta e si lascia dov'e'.
                // La colonna resta, quindi il dato non e' perso — e il log dice
                // dove guardare.
                $illeggibili++;

                continue;
            }

            $posizione = 0;

            foreach ($voci as $voce) {
                if ($posizione >= self::MAX_ALTERNATIVE) {
                    $scartate++;

                    continue;
                }

                // Una voce puo' essere una stringa («150 g di merluzzo») o un
                // oggetto con le macro. Nel primo caso restano solo il testo.
                $dati = is_array($voce) ? $voce : ['description' => (string) $voce];

                DB::table('nutrition_plan_items')->insert([
                    'nutrition_plan_meal_id' => $item->nutrition_plan_meal_id,
                    'alternativa_di_id' => $item->id,
                    'position' => $posizione,
                    'description' => mb_substr((string) ($dati['description'] ?? '—'), 0, 255),
                    'qty' => $dati['qty'] ?? null,
                    'unit' => isset($dati['unit']) ? mb_substr((string) $dati['unit'], 0, 16) : null,
                    'grams' => $dati['grams'] ?? null,
                    'kcal' => $dati['kcal'] ?? null,
                    'protein' => $dati['protein'] ?? null,
                    'carbs' => $dati['carbs'] ?? null,
                    'fat' => $dati['fat'] ?? null,
                    'origine_valori' => 'manual',
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ]);

                $posizione++;
                $create++;
            }

            DB::table('nutrition_plan_items')->where('id', $item->id)->update(['alternatives' => null]);
        }

        Log::info('G4.5 — alternative convertite da JSON a righe', [
            'items_con_alternative' => $conAlternative->count(),
            'righe_create' => $create,
            'voci_scartate_oltre_la_terza' => $scartate,
            'json_illeggibili_lasciati_dove_sono' => $illeggibili,
        ]);
    }

    public function down(): void
    {
        Schema::table('nutrition_plan_items', function (Blueprint $table): void {
            $table->dropForeign(['alternativa_di_id']);
            $table->dropColumn(['alternativa_di_id', 'origine_valori']);
        });

        Schema::table('nutrition_plan_meals', function (Blueprint $table): void {
            $table->dropForeign(['alternativa_di_id']);
            $table->dropForeign(['nutrition_plan_day_id']);
            $table->dropColumn(['alternativa_di_id', 'nutrition_plan_day_id']);
        });

        Schema::dropIfExists('nutrition_plan_days');
    }
};
