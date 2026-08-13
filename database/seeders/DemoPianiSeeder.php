<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MealType;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\Exercise;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanDay;
use App\Models\NutritionPlanMeal;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Schede e piani alimentari di **Tommaso Trainer** — G1-G8, dati di prova.
 *
 * ── 🚨 Perche' serviva, e non e' solo comodita' ───────────────────────────
 *
 * `G0.2` ha misurato che in staging **non esisteva nemmeno un modello** di
 * scheda: tutte e quattro le `workout_plans` avevano `member_id` valorizzato.
 * Quindi `GET /workout-plans/templates` restituiva una lista vuota, e il tasto
 * «manda una scheda» in chat — che esiste da S7, funziona ed e' provato — **non
 * ha mai avuto niente da mandare**.
 *
 * ⚠️ Ogni cosa che questo seeder crea e' un **modello** (`member_id = null`):
 * e' l'unica forma che si puo' mandare a qualcuno.
 *
 * ── 💡 Cosa dimostra, oltre a riempire lo schermo ─────────────────────────
 *
 * I dati sono scelti per far vedere **le cose nuove che senza dati non si
 * vedono**: una scheda a piu' giorni, un'alternativa a un esercizio, un piano
 * con i pasti alternativi, un alimento con tre alternative, e il «Rif. Allievo»
 * che solo Tommaso vede.
 *
 * 🚨 **Idempotente**: la chiave e' `(created_by, name)`. Rilanciarlo non duplica
 * e non riscrive — chi ha corretto un piano a mano non se lo ritrova rifatto.
 */
class DemoPianiSeeder extends Seeder
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'palestra-demo')->first();

        if ($tenant === null) {
            $this->command?->warn('Palestra Demo non c\'e\': lancia prima DemoGymSeeder.');

            return;
        }

        $tommaso = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', 'trainer@palestra-demo.test')
            ->first();

        if ($tommaso === null) {
            $this->command?->warn('Tommaso Trainer non c\'e\': lancia prima DemoGymSeeder.');

            return;
        }

        $this->context->runAs($tenant, function () use ($tenant, $tommaso): void {
            $this->schede($tenant, $tommaso);
            $this->piani($tenant, $tommaso);
        });

        $this->command?->info('Schede e piani di Tommaso pronti.');
    }

    // ───────────────────────── le schede ─────────────────────────

    private function schede(Tenant $tenant, User $tommaso): void
    {
        /*
         * Una scheda a **due giorni**, con un'alternativa d'esercizio su ognuno.
         *
         * 💡 E' il caso che prima di G4 non si poteva nemmeno esprimere: una
         * scheda era un elenco piatto, e «panca piana oppure panca con manubri»
         * finiva nelle note, dove nessuna funzione la leggeva.
         */
        $this->scheda($tenant, $tommaso, 'Split A/B — intermedi', [
            'Giorno A — spinta' => [
                ['Panca piana', 4, '8-10', 90, 'Scapole strette, piedi a terra.', 'Panca piana con manubri'],
                ['Lento avanti', 3, '10', 75, null, 'Shoulder press a macchina'],
                ['Push down tricipiti', 3, '12-15', 60, null, null],
            ],
            'Giorno B — tirata' => [
                ['Trazioni', 4, 'max', 120, 'Se non arrivi a 6, usa le assistite.', 'Trazioni assistite'],
                ['Rematore con bilanciere', 4, '8-10', 90, null, null],
                ['Curl bicipiti', 3, '12', 60, null, 'Curl a martello'],
            ],
        ]);

        // Una a giorno unico: il caso più comune, e quello in cui il nome del
        // giorno resta **vuoto** di proposito.
        $this->scheda($tenant, $tommaso, 'Full body — principianti', [
            '' => [
                ['Squat', 3, '10', 90, 'Scendi finché resti con la schiena dritta.', 'Pressa'],
                ['Panca piana', 3, '10', 90, null, null],
                ['Lat machine', 3, '12', 75, null, null],
                ['Plank', 3, '30 sec', 45, null, null],
            ],
        ]);
    }

    /**
     * @param  array<string, list<array{0: string, 1: int, 2: string, 3: int, 4: ?string, 5: ?string}>>  $giorni
     */
    private function scheda(Tenant $tenant, User $tommaso, string $nome, array $giorni): void
    {
        $esistente = WorkoutPlan::withoutGlobalScopes()
            ->where('created_by', $tommaso->getKey())
            ->where('name', $nome)
            ->exists();

        if ($esistente) {
            return;
        }

        $piano = WorkoutPlan::create([
            'tenant_id' => $tenant->getKey(),
            // 🚨 `null` = **modello**. È l'unica forma che si può mandare in chat.
            'member_id' => null,
            'created_by' => $tommaso->getKey(),
            'name' => $nome,
            'rif_allievo' => 'esempio — lo vede solo Tommaso',
            'status' => PlanStatus::Published,
            'source' => PlanSource::Manual,
            'published_at' => now(),
        ]);

        $posizioneGiorno = 0;

        foreach ($giorni as $nomeGiorno => $righe) {
            $giorno = $piano->days()->create([
                'position' => $posizioneGiorno++,
                // ⚠️ Stringa vuota → `null`: una scheda a un giorno solo non
                // deve mostrare un'intestazione che nessuno ha scritto.
                'name' => $nomeGiorno === '' ? null : $nomeGiorno,
            ]);

            $posizione = 0;

            foreach ($righe as [$esercizio, $serie, $reps, $recupero, $note, $alternativa]) {
                $principale = $piano->exercisesConAlternative()->create([
                    'workout_plan_day_id' => $giorno->getKey(),
                    'exercise_id' => $this->esercizio($esercizio)->getKey(),
                    'position' => $posizione++,
                    'sets' => $serie,
                    'reps' => $reps,
                    'rest_sec' => $recupero,
                    'notes' => $note,
                ]);

                if ($alternativa === null) {
                    continue;
                }

                // D10 — «panca piana **oppure** panca con manubri».
                $piano->exercisesConAlternative()->create([
                    'workout_plan_day_id' => $giorno->getKey(),
                    'alternativa_di_id' => $principale->getKey(),
                    'exercise_id' => $this->esercizio($alternativa)->getKey(),
                    'position' => 0,
                    'sets' => $serie,
                    'reps' => $reps,
                    'rest_sec' => $recupero,
                ]);
            }
        }
    }

    /**
     * L'esercizio dal catalogo di piattaforma.
     *
     * ⚠️ Si cerca per forma canonica, come fa `ExerciseMatcher`: scriverne uno
     * nuovo qui vorrebbe dire sporcare il catalogo con doppioni che differiscono
     * per una maiuscola.
     */
    private function esercizio(string $nome): Exercise
    {
        return Exercise::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => null, 'slug_normalized' => Exercise::normalize($nome)],
            ['name' => $nome, 'muscle_group' => 'full_body', 'is_custom' => false],
        );
    }

    // ───────────────────────── i piani alimentari ─────────────────────────

    private function piani(Tenant $tenant, User $tommaso): void
    {
        $this->piano($tenant, $tommaso, 'Definizione — 1.800 kcal', 'M.R. spalla dx', 1800, [
            [
                'nome' => 'Giorno tipo',
                'pasti' => [
                    [
                        'tipo' => MealType::Breakfast,
                        'titolo' => 'Colazione',
                        'alimenti' => [
                            ['80 g di fiocchi d\'avena', 80, 'g', 304, 11, 54, 6, []],
                            ['200 ml di latte parzialmente scremato', 200, 'ml', 92, 7, 10, 3, [
                                ['200 g di yogurt greco 0%', 200, 'g', 114, 20, 6, 0],
                                ['200 ml di bevanda di soia', 200, 'ml', 78, 6, 4, 4],
                            ]],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Lunch,
                        'titolo' => 'Pranzo',
                        'alimenti' => [
                            ['120 g di petto di pollo', 120, 'g', 198, 37, 0, 4, [
                                // 🚨 Tre alternative: il massimo che D2 ammette.
                                ['150 g di merluzzo', 150, 'g', 123, 27, 0, 1],
                                ['130 g di tacchino', 130, 'g', 190, 38, 0, 3],
                                ['2 uova intere + 2 albumi', 4, 'unità', 220, 26, 1, 12],
                            ]],
                            ['80 g di riso basmati', 80, 'g', 280, 6, 62, 1, []],
                            ['200 g di verdure miste', 200, 'g', 60, 3, 8, 1, []],
                        ],
                        // D2 — un pranzo alternativo, intero.
                        'alternative' => [
                            [
                                'tipo' => MealType::Lunch,
                                'titolo' => 'Pranzo fuori casa',
                                'alimenti' => [
                                    ['Insalatona con tonno e legumi', null, null, 480, 38, 40, 14, []],
                                ],
                            ],
                        ],
                    ],
                    [
                        'tipo' => MealType::AfternoonSnack,
                        'titolo' => 'Merenda',
                        'alimenti' => [
                            ['1 mela', 1, 'unità', 80, 0, 21, 0, [
                                ['1 pera', 1, 'unità', 85, 0, 22, 0],
                            ]],
                            ['30 g di mandorle', 30, 'g', 174, 6, 6, 15, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Dinner,
                        'titolo' => 'Cena',
                        'alimenti' => [
                            ['150 g di salmone', 150, 'g', 310, 31, 0, 20, []],
                            ['250 g di patate', 250, 'g', 195, 5, 43, 0, []],
                            ['200 g di insalata mista con olio', 200, 'g', 110, 2, 6, 9, []],
                        ],
                        'alternative' => [],
                    ],
                ],
            ],
        ]);

        /*
         * 💡 **Il nome non promette un numero, e la ragione e' didattica.**
         *
         * I due giorni sono diversi di proposito — 2.337 kcal in allenamento,
         * 1.749 a riposo — ed e' esattamente la cosa che prima di G4 non si
         * poteva scrivere. Un nome tipo «Massa — 2.400 kcal» suggerirebbe che
         * ogni giorno vale 2.400, cioe' il contrario.
         *
         * ⚠️ `target_kcal` resta il **bersaglio del trainer**, non la somma di
         * cio' che ha scritto: sono due cose diverse, e il pannello le mostra
         * accanto proprio perche' si vedano entrambe.
         */
        $this->piano($tenant, $tommaso, 'Massa — allenamento e riposo', 'G.B. — off season', 2400, [
            [
                'nome' => 'Giorno di allenamento',
                'pasti' => [
                    [
                        'tipo' => MealType::Breakfast,
                        'titolo' => 'Colazione',
                        'alimenti' => [
                            ['100 g di fiocchi d\'avena', 100, 'g', 380, 13, 68, 7, []],
                            ['30 g di burro d\'arachidi', 30, 'g', 180, 8, 6, 15, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Lunch,
                        'titolo' => 'Pranzo',
                        'alimenti' => [
                            ['150 g di manzo magro', 150, 'g', 250, 40, 0, 10, [
                                ['180 g di petto di pollo', 180, 'g', 297, 56, 0, 6],
                            ]],
                            ['120 g di pasta integrale', 120, 'g', 420, 15, 84, 3, []],
                            ['30 g di olio extravergine', 30, 'g', 265, 0, 0, 30, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::AfternoonSnack,
                        'titolo' => 'Post allenamento',
                        'alimenti' => [
                            ['1 misurino di proteine in polvere', 30, 'g', 115, 24, 2, 1, []],
                            ['1 banana', 1, 'unità', 105, 1, 27, 0, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Dinner,
                        'titolo' => 'Cena',
                        'alimenti' => [
                            ['200 g di merluzzo', 200, 'g', 164, 36, 0, 1, []],
                            ['300 g di patate dolci', 300, 'g', 258, 5, 60, 0, []],
                            ['200 g di verdure con olio', 200, 'g', 200, 3, 8, 18, []],
                        ],
                        'alternative' => [],
                    ],
                ],
            ],
            [
                // 🚨 Un **secondo giorno**: è la cosa che prima di G4 non si
                // poteva scrivere affatto.
                'nome' => 'Giorno di riposo',
                'pasti' => [
                    [
                        'tipo' => MealType::Breakfast,
                        'titolo' => 'Colazione',
                        'alimenti' => [
                            ['80 g di fiocchi d\'avena', 80, 'g', 304, 11, 54, 6, []],
                            ['200 g di yogurt greco', 200, 'g', 114, 20, 6, 0, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Lunch,
                        'titolo' => 'Pranzo',
                        'alimenti' => [
                            ['150 g di salmone', 150, 'g', 310, 31, 0, 20, []],
                            ['80 g di riso', 80, 'g', 280, 6, 62, 1, []],
                            ['200 g di verdure con olio', 200, 'g', 200, 3, 8, 18, []],
                        ],
                        'alternative' => [],
                    ],
                    [
                        'tipo' => MealType::Dinner,
                        'titolo' => 'Cena',
                        'alimenti' => [
                            ['150 g di petto di pollo', 150, 'g', 248, 46, 0, 5, []],
                            ['250 g di patate', 250, 'g', 195, 5, 43, 0, []],
                            ['40 g di pane integrale', 40, 'g', 98, 4, 18, 1, []],
                        ],
                        'alternative' => [],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $giorni
     */
    private function piano(
        Tenant $tenant,
        User $tommaso,
        string $nome,
        string $rif,
        int $kcal,
        array $giorni,
    ): void {
        $esistente = NutritionPlan::withoutGlobalScopes()
            ->where('created_by', $tommaso->getKey())
            ->where('name', $nome)
            ->exists();

        if ($esistente) {
            return;
        }

        $piano = NutritionPlan::create([
            'tenant_id' => $tenant->getKey(),
            // 🚨 Modello, come le schede: D4 dice che il legame con una persona
            // nasce solo quando il piano parte via chat.
            'member_id' => null,
            'created_by' => $tommaso->getKey(),
            'name' => $nome,
            'rif_allievo' => $rif,
            'target_kcal' => $kcal,
            'status' => PlanStatus::Published,
            'published_at' => now(),
        ]);

        $posizioneGiorno = 0;

        foreach ($giorni as $datiGiorno) {
            $giorno = $piano->days()->create([
                'position' => $posizioneGiorno++,
                'name' => $datiGiorno['nome'],
            ]);

            $posizione = 0;

            foreach ($datiGiorno['pasti'] as $datiPasto) {
                $pasto = $this->pasto($piano, $giorno, $datiPasto, $posizione++, null);

                $p = 0;

                foreach ($datiPasto['alternative'] as $alt) {
                    $this->pasto($piano, $giorno, $alt, $p++, $pasto->getKey());
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $dati
     */
    private function pasto(
        NutritionPlan $piano,
        NutritionPlanDay $giorno,
        array $dati,
        int $posizione,
        ?int $alternativaDi,
    ): NutritionPlanMeal {
        $pasto = $piano->mealsConAlternative()->create([
            'nutrition_plan_day_id' => $giorno->getKey(),
            'alternativa_di_id' => $alternativaDi,
            'meal' => $dati['tipo']->value,
            'position' => $posizione,
            'title' => $dati['titolo'],
        ]);

        $i = 0;

        foreach ($dati['alimenti'] as [$desc, $qty, $unita, $kcal, $pro, $carb, $gras, $alternative]) {
            $principale = $pasto->itemsConAlternative()->create([
                'position' => $i++,
                'description' => $desc,
                'qty' => $qty,
                'unit' => $unita,
                'grams' => $unita === 'g' ? $qty : null,
                'kcal' => $kcal,
                'protein' => $pro,
                'carbs' => $carb,
                'fat' => $gras,
                'origine_valori' => 'manual',
            ]);

            $a = 0;

            foreach ($alternative as [$adesc, $aqty, $aunita, $akcal, $apro, $acarb, $agras]) {
                /*
                 * 🚨 **Le alternative hanno le macro**, e non e' zelo: senza,
                 * sceglierne una non direbbe al diario dell'allievo cosa
                 * scrivere — che e' tutto il punto di D2, e la ragione per cui
                 * non sono piu' un campo di testo.
                 */
                $pasto->itemsConAlternative()->create([
                    'alternativa_di_id' => $principale->getKey(),
                    'position' => $a++,
                    'description' => $adesc,
                    'qty' => $aqty,
                    'unit' => $aunita,
                    'grams' => $aunita === 'g' ? $aqty : null,
                    'kcal' => $akcal,
                    'protein' => $apro,
                    'carbs' => $acarb,
                    'fat' => $agras,
                    'origine_valori' => 'manual',
                ]);
            }
        }

        return $pasto;
    }
}
