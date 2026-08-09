<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\BodyMetric;
use App\Models\FoodEntry;
use App\Models\NutritionPlan;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B5.6 — il diario alimentare visto dall'app.
 */
class NutritionApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    private User $altro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->altro = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
    }

    private function comeIscritto(?User $u = null): static
    {
        return $this->comeApp($u ?? $this->iscritto);
    }

    // ───────────────────────── voci ─────────────────────────

    #[Test]
    public function it_logs_a_food_entry(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Petto di pollo',
                'grams' => 200, 'kcal' => 330, 'protein' => 62, 'carbs' => 0, 'fat' => 7,
                'meal' => 'lunch',
            ])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Petto di pollo')
            ->assertJsonPath('data.meal', 'lunch');
    }

    /**
     * I grammi si derivano da quantita' e unita' quando mancano.
     *
     * Una voce senza grammi non entra in nessun totale: e' come non averla
     * scritta, e l'utente non ha modo di accorgersene.
     */
    #[Test]
    public function grams_are_derived_from_quantity_and_unit(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Olio', 'qty' => 2, 'unit' => 'cucchiaio', 'kcal' => 180,
            ])
            ->assertCreated()
            ->assertJsonPath('data.grams', 30.0);
    }

    /** Il pasto si deduce dall'ora quando l'app non lo dice. */
    #[Test]
    public function the_meal_is_inferred_from_the_hour(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Cornetto',
                'eaten_at' => today()->setTime(8, 30)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.meal', MealType::Breakfast->value);
    }

    /**
     * 🚨 Un pasto sbagliato si RIFIUTA, non si corregge di nascosto.
     *
     * Prima la regola era `['nullable','string']`: qualunque parola passava, poi
     * `MealType::tryFrom()` tornava null e si ripiegava sull'ora. Chiamando l'API
     * con `meal: "pranzo"` (i nomi italiani dell'app storica) il cibo finiva
     * nello spuntino del pomeriggio, con un **201** che diceva che era andato
     * tutto bene. Chi sviluppa il client non ha modo di accorgersene: il valore
     * sbagliato torna solo dentro la risposta, dove nessuno lo confronta.
     */
    #[Test]
    public function an_unknown_meal_is_rejected_instead_of_being_guessed(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Pasta',
                'kcal' => 400,
                'meal' => 'pranzo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meal');
    }

    #[Test]
    public function a_member_cannot_touch_the_entries_of_another(): void
    {
        $altrui = $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->altro->id, 'eaten_at' => now(),
            'meal' => MealType::Lunch, 'description' => 'Di Luigi', 'kcal' => 500,
        ]));

        $this->comeIscritto()->patchJson("/api/v1/food-entries/{$altrui->id}", ['kcal' => 1])->assertNotFound();
        $this->comeIscritto()->deleteJson("/api/v1/food-entries/{$altrui->id}")->assertNotFound();
    }

    // ───────────────────────── la giornata ─────────────────────────

    #[Test]
    public function the_diary_returns_six_meals_even_when_empty(): void
    {
        $risposta = $this->comeIscritto()->getJson('/api/v1/diary')->assertOk();

        $this->assertCount(6, $risposta->json('data.meals'));
        $this->assertSame('breakfast', $risposta->json('data.meals.0.meal'));
        $this->assertSame('evening_snack', $risposta->json('data.meals.5.meal'));
    }

    #[Test]
    public function the_diary_sums_the_day(): void
    {
        $this->comeIscritto()->postJson('/api/v1/food-entries', [
            'description' => 'A', 'meal' => 'lunch', 'kcal' => 500, 'protein' => 30, 'carbs' => 50, 'fat' => 15,
        ]);
        $this->comeIscritto()->postJson('/api/v1/food-entries', [
            'description' => 'B', 'meal' => 'dinner', 'kcal' => 700, 'protein' => 40, 'carbs' => 60, 'fat' => 25,
        ]);

        $this->comeIscritto()
            ->getJson('/api/v1/diary')
            ->assertOk()
            ->assertJsonPath('data.totals.kcal', 1200.0)
            ->assertJsonPath('data.totals.protein', 70.0);
    }

    /**
     * 🚨 **Il target del giorno = target base + calorie bruciate.**
     *
     * Regola di prodotto: chi si allena puo' mangiare di piu' quel giorno.
     * Mostrargli il target fisso lo porterebbe a credere di aver sforato quando
     * non e' vero.
     */
    #[Test]
    public function the_daily_target_grows_with_what_was_burned(): void
    {
        $this->pianoAttivo(targetKcal: 2000);

        $senza = $this->comeIscritto()->getJson('/api/v1/targets')->assertOk();
        $this->assertSame(2000, $senza->json('data.kcal'));

        $this->comeIscritto()->postJson('/api/v1/daily-burn', ['kcal' => 400])->assertCreated();

        $con = $this->comeIscritto()->getJson('/api/v1/targets')->assertOk();

        $this->assertSame(2400, $con->json('data.kcal'));
        $this->assertSame(2000, $con->json('data.kcal_base'), 'Il target base non deve muoversi.');
    }

    /**
     * Il piano scritto da un professionista vince sul calcolo automatico.
     *
     * La formula conosce quattro numeri; il trainer conosce la persona.
     */
    #[Test]
    public function an_active_plan_wins_over_the_computed_target(): void
    {
        $this->profiloCompleto();
        $this->pianoAttivo(targetKcal: 1800);

        $this->comeIscritto()
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.source', 'plan')
            ->assertJsonPath('data.kcal_base', 1800);
    }

    #[Test]
    public function without_a_plan_the_target_comes_from_the_profile(): void
    {
        $this->profiloCompleto();

        $risposta = $this->comeIscritto()->getJson('/api/v1/targets')->assertOk();

        $this->assertSame('profile', $risposta->json('data.source'));
        $this->assertGreaterThan(1200, $risposta->json('data.kcal'));
    }

    /**
     * Senza gli ingredienti della formula non si inventa un target.
     *
     * L'utente ci costruirebbe sopra una dieta.
     */
    #[Test]
    public function without_enough_data_there_is_no_target_at_all(): void
    {
        $this->comeIscritto()
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** L'override del giorno sostituisce, non si somma alle sessioni. */
    #[Test]
    public function the_manual_burn_replaces_and_does_not_add_up(): void
    {
        $this->comeIscritto()->postJson('/api/v1/daily-burn', ['kcal' => 500])->assertCreated();
        $this->comeIscritto()->postJson('/api/v1/daily-burn', ['kcal' => 300])->assertCreated();

        $this->comeIscritto()
            ->getJson('/api/v1/diary')
            ->assertOk()
            ->assertJsonPath('data.burned.kcal', 300)
            ->assertJsonPath('data.burned.source', 'manual');
    }

    // ───────────────────────── preferiti ─────────────────────────

    #[Test]
    public function it_saves_an_entry_as_a_favourite_and_puts_it_back(): void
    {
        $voce = $this->comeIscritto()->postJson('/api/v1/food-entries', [
            'description' => 'Yogurt greco', 'grams' => 170, 'kcal' => 100, 'protein' => 17,
            'meal' => 'breakfast',
        ])->assertCreated();

        $id = $voce->json('data.id');

        $preferito = $this->comeIscritto()
            ->postJson("/api/v1/food-entries/{$id}/favorite")
            ->assertCreated();

        $this->comeIscritto()
            ->postJson("/api/v1/food-favorites/{$preferito->json('data.id')}/add", ['meal' => 'morning_snack'])
            ->assertCreated()
            ->assertJsonPath('data.0.description', 'Yogurt greco')
            ->assertJsonPath('data.0.meal', 'morning_snack');

        $this->assertSame(2, FoodEntry::withoutGlobalScopes()->where('user_id', $this->iscritto->id)->count());
    }

    /**
     * Un pasto intero salvato come preferito ne restituisce tutte le voci.
     *
     * E' la funzione che decide se il diario viene usato per piu' di una
     * settimana: ricomporre a mano la stessa colazione ogni mattina e' il punto
     * in cui le persone smettono.
     */
    #[Test]
    public function it_saves_a_whole_meal_and_restores_every_item(): void
    {
        foreach ([['Pane', 200], ['Marmellata', 90], ['Caffe', 5]] as [$nome, $kcal]) {
            $this->comeIscritto()->postJson('/api/v1/food-entries', [
                'description' => $nome, 'meal' => 'breakfast', 'kcal' => $kcal, 'grams' => 50,
            ])->assertCreated();
        }

        $preferito = $this->comeIscritto()
            ->postJson('/api/v1/food-favorites/meal', [
                'description' => 'La mia colazione', 'meal' => 'breakfast',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_meal', true)
            ->assertJsonPath('data.items_count', 3)
            ->assertJsonPath('data.kcal', 295.0);

        $rimesso = $this->comeIscritto()
            ->postJson("/api/v1/food-favorites/{$preferito->json('data.id')}/add", [
                'meal' => 'breakfast',
                'eaten_at' => today()->addDay()->setTime(8, 0)->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertCount(3, $rimesso->json('data'));
    }

    #[Test]
    public function favourites_are_ordered_by_actual_use(): void
    {
        $a = $this->preferito('Poco usato');
        $b = $this->preferito('Molto usato');

        for ($i = 0; $i < 3; $i++) {
            $this->comeIscritto()->postJson("/api/v1/food-favorites/{$b}/add", ['meal' => 'lunch']);
        }

        $this->comeIscritto()->postJson("/api/v1/food-favorites/{$a}/add", ['meal' => 'lunch']);

        $elenco = $this->comeIscritto()->getJson('/api/v1/food-favorites')->assertOk()->json('data');

        $this->assertSame('Molto usato', $elenco[0]['description']);
    }

    // ───────────────────────── piano ─────────────────────────

    #[Test]
    public function the_active_plan_is_returned_with_its_meals(): void
    {
        $piano = $this->pianoAttivo(targetKcal: 2200);

        $pasto = $piano->meals()->create(['meal' => MealType::Lunch->value, 'position' => 1, 'title' => 'Pranzo']);
        $pasto->items()->create(['description' => 'Riso', 'grams' => 80, 'kcal' => 280, 'position' => 1]);

        $this->comeIscritto()
            ->getJson('/api/v1/nutrition-plan')
            ->assertOk()
            ->assertJsonPath('data.targets.kcal', 2200)
            ->assertJsonPath('data.meals.0.items.0.description', 'Riso')
            ->assertJsonPath('data.meals.0.totals.kcal', 280.0);
    }

    /** Una bozza non e' un piano attivo: l'app non deve vederla. */
    #[Test]
    public function a_draft_plan_is_not_active(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => NutritionPlan::create([
            'member_id' => $this->iscritto->id, 'name' => 'Bozza',
            'target_kcal' => 2000, 'status' => PlanStatus::Draft,
        ]));

        $this->comeIscritto()
            ->getJson('/api/v1/nutrition-plan')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function pianoAttivo(int $targetKcal): NutritionPlan
    {
        return $this->ctx()->runAs($this->alfa, function () use ($targetKcal): NutritionPlan {
            $p = NutritionPlan::create([
                'member_id' => $this->iscritto->id,
                'name' => 'Piano',
                'target_kcal' => $targetKcal,
                'target_protein_g' => 150,
                'target_carbs_g' => 200,
                'target_fat_g' => 60,
                'status' => PlanStatus::Published,
            ]);

            $p->forceFill(['published_at' => now()])->save();

            return $p;
        });
    }

    private function profiloCompleto(): void
    {
        $this->ctx()->runAs($this->alfa, function (): void {
            Profile::create([
                'user_id' => $this->iscritto->id,
                'sex' => 'm',
                'birthdate' => now()->subYears(30)->toDateString(),
                'height_cm' => 180,
                'activity_level' => 'moderate',
                'goal' => 'maintain',
            ]);

            BodyMetric::create([
                'user_id' => $this->iscritto->id, 'date' => today(), 'weight_kg' => 80,
            ]);
        });
    }

    private function preferito(string $nome): int
    {
        $voce = $this->comeIscritto()->postJson('/api/v1/food-entries', [
            'description' => $nome, 'meal' => 'lunch', 'kcal' => 100, 'grams' => 100,
        ])->assertCreated();

        return $this->comeIscritto()
            ->postJson("/api/v1/food-entries/{$voce->json('data.id')}/favorite")
            ->assertCreated()
            ->json('data.id');
    }
}
