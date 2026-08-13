<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\NutritionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Le API di scrittura di schede e piani — G5.
 *
 * 🚨 **Prima di G5 i piani alimentari non avevano nessuna API di scrittura.**
 * C'erano `GET /nutrition-plan` e `eatMeal()`, e basta: un trainer non poteva
 * comporre un piano dall'app perche' non c'era dove mandarlo.
 */
final class ScritturaDeiPianiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'trainer@alfa.test');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
    }

    /** @return array<string, mixed> */
    private function pianoCompleto(): array
    {
        return [
            'name' => 'Definizione',
            'rif_allievo' => 'M.R. spalla dx',
            'days' => [[
                'name' => 'Giorno 1',
                'meals' => [[
                    'meal' => MealType::Lunch->value,
                    'title' => 'Pranzo',
                    'items' => [[
                        'description' => '120 g di petto di pollo',
                        'grams' => 120,
                        'kcal' => 198,
                        'protein' => 37,
                        'origine_valori' => 'ai',
                        'alternatives' => [[
                            'description' => '150 g di merluzzo',
                            'grams' => 150,
                            'kcal' => 123,
                            'protein' => 27,
                        ]],
                    ]],
                    'alternatives' => [[
                        'meal' => MealType::Lunch->value,
                        'title' => 'Pranzo alternativo',
                        'items' => [[
                            'description' => '200 g di salmone',
                            'kcal' => 400,
                        ]],
                    ]],
                ]],
                'alternatives' => [[
                    'name' => 'Giorno 1-bis',
                    'meals' => [[
                        'meal' => MealType::Lunch->value,
                        'items' => [['description' => 'Riso e ceci', 'kcal' => 500]],
                    ]],
                ]],
            ]],
        ];
    }

    // ───────────────────── i piani alimentari ─────────────────────

    #[Test]
    public function a_trainer_writes_a_plan_with_days_meals_and_alternatives(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        $piano = NutritionPlan::withoutGlobalScopes()->firstOrFail();

        // 🚨 D4 — anonimo: nessun allievo sul server.
        $this->assertNull($piano->member_id);
        $this->assertNotNull($piano->origine_id);

        // Un giorno principale, uno alternativo.
        $this->assertSame(1, $piano->days()->count());
        $this->assertSame(2, $piano->daysConAlternative()->count());

        $giorno = $r->json('data.days.0');

        $this->assertSame('Giorno 1', $giorno['name']);
        $this->assertCount(1, $giorno['meals'], 'il pasto alternativo e\' comparso come pasto vero');
        $this->assertCount(1, $giorno['alternatives']);
        $this->assertCount(1, $giorno['meals'][0]['alternatives']);
        $this->assertCount(1, $giorno['meals'][0]['items'][0]['alternatives']);
    }

    #[Test]
    public function the_totals_do_not_count_the_alternatives(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        /*
         * 🚨 198, non 198+123+400. Il totale di un livello somma **solo i
         * principali**: contare le alternative gonfierebbe ogni piano, e
         * nessuno se ne accorgerebbe finche' un trainer non fa il conto a mano.
         */
        $this->assertSame(198.0, $r->json('data.days.0.meals.0.totali.kcal'));
        $this->assertSame(198.0, $r->json('data.days.0.totali.kcal'));

        // 💡 E ogni alternativa porta il **proprio** totale accanto a se': e'
        // l'unica cosa che rende utile un'alternativa.
        $this->assertSame(123.0, $r->json('data.days.0.meals.0.items.0.alternatives.0.totali.kcal'));
        $this->assertSame(400.0, $r->json('data.days.0.meals.0.alternatives.0.totali.kcal'));
        $this->assertSame(500.0, $r->json('data.days.0.alternatives.0.totali.kcal'));
    }

    #[Test]
    public function the_plan_total_is_the_average_of_its_days_not_their_sum(): void
    {
        $due = $this->pianoCompleto();
        $due['days'][] = [
            'name' => 'Giorno 2',
            'meals' => [[
                'meal' => MealType::Dinner->value,
                'items' => [['description' => 'Pasta', 'kcal' => 402]],
            ]],
        ];

        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $due)
            ->assertCreated();

        /*
         * ⚠️ (198 + 402) / 2 = 300, non 600. La somma di due giorni non vuol
         * dire niente per chi legge: nessuno mangia due giorni in una volta.
         *
         * 💡 Con un piano a **un** giorno i due modi danno lo stesso numero —
         * ed e' il caso piu' comune, cioe' quello in cui la differenza passa
         * inosservata.
         */
        $this->assertSame(300.0, $r->json('data.totali.kcal'));
    }

    #[Test]
    public function more_than_three_alternatives_are_refused(): void
    {
        $troppe = $this->pianoCompleto();
        $troppe['days'][0]['meals'][0]['items'][0]['alternatives'] = array_fill(0, 4, [
            'description' => 'Qualcosa',
        ]);

        // 🚨 Non c'e' modo di esprimerlo come vincolo di database: la regola
        // applicativa e' l'unica difesa (D2).
        $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $troppe)
            ->assertStatus(422)
            ->assertJsonValidationErrors('days.0.meals.0.items.0.alternatives');
    }

    #[Test]
    public function rewriting_the_days_does_not_leave_orphan_alternatives(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        $id = $r->json('data.id');

        $this->actingAs($this->trainer, 'sanctum')
            ->putJson("/api/v1/nutrition-plans/{$id}", [
                'name' => 'Definizione',
                'days' => [['name' => 'Solo uno', 'meals' => []]],
            ])
            ->assertOk();

        $piano = NutritionPlan::withoutGlobalScopes()->findOrFail($id);

        /*
         * ⚠️ **Un giorno solo, e nessun residuo.** Cancellando solo i principali
         * resterebbero in tabella giorni alternativi orfani — e senza il loro
         * originale tornerebbero a comparire come giorni veri.
         */
        $this->assertSame(1, $piano->daysConAlternative()->count());
        $this->assertSame(0, $piano->mealsConAlternative()->count());
    }

    #[Test]
    public function renaming_a_plan_does_not_empty_it(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        $id = $r->json('data.id');

        // 🚨 `days` **assente**, non vuoto: vuol dire «non l'ho mandato».
        $this->actingAs($this->trainer, 'sanctum')
            ->putJson("/api/v1/nutrition-plans/{$id}", ['name' => 'Nome nuovo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome nuovo')
            ->assertJsonCount(1, 'data.days');
    }

    #[Test]
    public function an_empty_days_array_does_empty_it(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        $id = $r->json('data.id');

        // ⚠️ L'altra meta' della distinzione: `[]` vuol dire «non ha piu' giorni».
        $this->actingAs($this->trainer, 'sanctum')
            ->putJson("/api/v1/nutrition-plans/{$id}", ['name' => 'Vuoto', 'days' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.days');
    }

    // ───────────────────── R4 — il «Rif. Allievo» ─────────────────────

    #[Test]
    public function only_the_author_sees_the_private_note(): void
    {
        $r = $this->actingAs($this->trainer, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertCreated();

        // Chi l'ha scritto lo vede.
        $r->assertJsonPath('data.rif_allievo', 'M.R. spalla dx');

        $altroTrainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'altro@alfa.test');

        /*
         * 🚨 **404 e non 403.** Un `403` confermerebbe che quel piano esiste — e
         * insieme al `rif_allievo` direbbe qualcosa sul lavoro di un collega.
         * Due trainer della stessa palestra non si leggono i promemoria.
         */
        $this->actingAs($altroTrainer, 'sanctum')
            ->getJson('/api/v1/nutrition-plans/'.$r->json('data.id'))
            ->assertStatus(404);
    }

    #[Test]
    public function a_member_cannot_write_nutrition_plans(): void
    {
        $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/nutrition-plans', $this->pianoCompleto())
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_a_trainer');
    }

    #[Test]
    public function an_independent_trainer_can_write_them_too(): void
    {
        $libero = app(CreaTenantPersonale::class)(
            'Trainer libero',
            'libero@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        /*
         * 🚨 Dimenticare `FreeTrainer` in `puoScrivere()` gli aprirebbe l'app e
         * gli chiuderebbe **l'unica funzione per cui la usa**. Ed e' lo stesso
         * difetto gia' visto in F6: un elenco vuoto, nessun errore.
         */
        $this->actingAs($libero->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans', ['name' => 'Il mio piano'])
            ->assertCreated();
    }

    // ───────────────────── le schede a piu' giorni ─────────────────────

    #[Test]
    public function a_workout_plan_can_be_written_with_days_and_alternatives(): void
    {
        $r = $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Split',
                'days' => [
                    [
                        'name' => 'Giorno A — spinta',
                        'exercises' => [[
                            'name' => 'Panca piana',
                            'sets' => 4,
                            'reps' => '8-10',
                            'alternatives' => [['name' => 'Panca con manubri', 'sets' => 4, 'reps' => '10']],
                        ]],
                    ],
                    ['name' => 'Giorno B — tirata', 'exercises' => [['name' => 'Trazioni', 'sets' => 4]]],
                ],
            ])
            ->assertCreated();

        $piano = WorkoutPlan::withoutGlobalScopes()->findOrFail($r->json('data.id'));

        $this->assertSame(2, $piano->days()->count());

        // 🚨 Due esercizi da fare, tre righe in tabella.
        $this->assertSame(2, $piano->exercises()->count());
        $this->assertSame(3, $piano->exercisesConAlternative()->count());
    }

    #[Test]
    public function the_old_flat_shape_still_works(): void
    {
        /*
         * 🚨 **L'app gia' installata manda `exercises` piatto.** Toglierlo
         * spegnerebbe il salvataggio su ogni telefono non ancora aggiornato —
         * e sarebbe un guasto che non si vede in nessun test se non c'e'
         * questo.
         */
        $r = $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Alla vecchia maniera',
                'exercises' => [['name' => 'Squat', 'sets' => 5, 'reps' => '5']],
            ])
            ->assertCreated();

        $piano = WorkoutPlan::withoutGlobalScopes()->findOrFail($r->json('data.id'));

        // Un giorno, creato da solo, con dentro l'esercizio.
        $this->assertSame(1, $piano->days()->count());
        $this->assertNull($piano->days()->first()->name, 'un giorno inventato ha preso un nome che nessuno ha scritto');
        $this->assertSame(1, $piano->exercises()->count());
    }

    #[Test]
    public function reps_survive_as_written_inside_a_day_too(): void
    {
        $r = $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Cedimento',
                'days' => [['exercises' => [['name' => 'Curl', 'reps' => 'cedimento']]]],
            ])
            ->assertCreated();

        // ⚠️ «8-12», «cedimento», «max» sono prescrizioni legittime: chi le
        // converte in intero credendo di correggere una svista rompe meta'
        // delle schede vere.
        $piano = WorkoutPlan::withoutGlobalScopes()->findOrFail($r->json('data.id'));

        $this->assertSame('cedimento', $piano->exercises()->first()->reps);
    }
}
