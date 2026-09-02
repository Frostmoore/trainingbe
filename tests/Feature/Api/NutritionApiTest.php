<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
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

            /*
             * ⚠️ Niente peso: da S5.5 il server non lo conosce piu' (D9-bis).
             * Un profilo «completo» lato server e' completo di tutto TRANNE il
             * peso, e per questo il target dal profilo non si calcola piu' qui.
             */
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
    // ───────── la massa impossibile, che BLOCCA (12/08/2026) ─────────

    /**
     * 🚨 **L'unica guardia del sistema che rifiuta invece di avvisare.**
     *
     * Il 12/08/2026 il modello ha prodotto delle coppiette di maiale con 56 g di
     * proteine, 4 di carboidrati e 40 di grassi **per 100 g**: acqua zero.
     * 588 kcal sono un numero plausibile, ed e' per questo che sarebbe passato.
     *
     * Il committente: *«non e' possibile che un alimento abbia piu' macro che
     * peso»*. Qui non c'e' nessuna interpretazione in cui quel numero abbia
     * senso, quindi non si abbassa la confidenza: si rifiuta.
     */
    #[Test]
    public function an_entry_with_more_macros_than_mass_is_refused(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Coppiette di maiale',
                'grams' => 100,
                'kcal' => 588,
                'protein' => 56,
                'carbs' => 4,
                'fat' => 40,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'macros_exceed_mass');

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

    /*
    | ══ ⛔ `the_mass_guard_holds_on_the_ai_path_too` E' PASSATO IN DART — I2.5 ═
    |
    | Provava che la guardia sulla massa valesse anche sulla strada dell'AI,
    | perche' stava nel `saving()` del modello e non in una `Rule`.
    |
    | 🚨 Quella strada **non passa piu' di qui**: `ai/food/valida` setaccia e
    | restituisce, e a scrivere e' il telefono. La guardia e' in
    | `guardie_della_voce.dart`, con gli stessi numeri — le coppiette da 588 kcal
    | con 100 g di macro in 100 g di prodotto — e il test in
    | `guardie_della_voce_test.dart`.
    |
    | ⚠️ **Gli altri test della massa restano**, e devono: `/food-entries` esiste
    | ancora e la guardia nel modello pure. Spariranno insieme, con I4.
    */

    /**
     * ⚠️ **Anche la modifica**: correggere una voce sana in una impossibile deve
     * fallire, o la guardia sarebbe aggirabile con due richieste.
     */
    #[Test]
    public function an_edit_cannot_make_an_entry_impossible(): void
    {
        $voce = FoodEntry::create([
            'tenant_id' => $this->iscritto->tenant_id,
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => 'lunch',
            'description' => 'Petto di pollo',
            'grams' => 100,
            'kcal' => 165,
            'protein' => 31,
            'carbs' => 0,
            'fat' => 3.6,
        ]);

        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['protein' => 150])
            ->assertStatus(422)
            ->assertJsonPath('error', 'macros_exceed_mass');

        $this->assertSame(31.0, (float) $voce->fresh()->protein);
    }

    /**
     * 🚨 **Cento grammi d'olio sono cento grammi di grassi, e vanno salvati.**
     *
     * I grassi puri e gli zuccheri puri sono gli unici alimenti senza acqua: se
     * la guardia li rifiutasse, rifiuterebbe l'olio — che e' fra le voci piu'
     * comuni di qualunque diario.
     */
    #[Test]
    public function pure_fat_reaches_one_hundred_percent_and_is_accepted(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Olio EVO',
                'grams' => 100,
                'kcal' => 900,
                'protein' => 0,
                'carbs' => 0,
                'fat' => 100,
            ])
            ->assertCreated();

        $this->assertSame(1, FoodEntry::withoutGlobalScopes()->count());
    }

    /**
     * 💡 Il 2% di tolleranza e' per gli arrotondamenti: il modello manda numeri
     * interi, e tre arrotondamenti per eccesso su una voce da 100 g possono
     * sforare di poco senza che ci sia niente di impossibile.
     */
    #[Test]
    public function rounding_does_not_trip_the_guard(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Zucchero',
                'grams' => 100,
                'kcal' => 400,
                'protein' => 0,
                'carbs' => 101,
                'fat' => 0,
            ])
            ->assertCreated();
    }

    /** ⚠️ Senza grammi non c'e' niente con cui confrontare: non si blocca. */
    #[Test]
    public function without_grams_nothing_is_refused(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Un piatto',
                'kcal' => 500,
                'protein' => 900,
            ])
            ->assertCreated();
    }
}
