<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il pannello dei piani, dopo G6.
 *
 * ── 🚨 Perche' questa classe esiste ───────────────────────────────────────
 *
 * Fino a G6 `NutritionPlanResource` conteneva
 * `Textarea::make('alternatives')` — un campo su una colonna che la migrazione
 * `2026_08_14_150000` aveva **tolto**. Il modulo era **rotto**, e la suite era
 * verde: nessun test apriva quelle pagine.
 *
 * ⚠️ **E' lo stesso difetto di `G0.2`**, in un'altra forma: qualcosa che non
 * funziona e che nessuno vede perche' nessuno ci passa. Qui il minimo
 * indispensabile e' provare che le pagine **si aprano**.
 */
final class PannelloDeiPianiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'trainer@alfa.test');
    }

    private function pianoAlimentare(): NutritionPlan
    {
        return $this->ctx()->runAs($this->palestra, function (): NutritionPlan {
            $piano = NutritionPlan::create([
                'tenant_id' => $this->palestra->id,
                'created_by' => $this->trainer->getKey(),
                'name' => 'Definizione',
                'rif_allievo' => 'M.R. spalla dx',
            ]);

            $pasto = $piano->meals()->create([
                'nutrition_plan_day_id' => $piano->giornoPredefinito()->getKey(),
                'meal' => MealType::Lunch->value,
                'position' => 0,
            ]);

            $pollo = NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $pasto->getKey(),
                'position' => 0,
                'description' => '120 g di petto di pollo',
                'kcal' => 198,
            ]);

            NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $pasto->getKey(),
                'alternativa_di_id' => $pollo->getKey(),
                'position' => 0,
                'description' => '150 g di merluzzo',
                'kcal' => 123,
            ]);

            return $piano;
        });
    }

    #[Test]
    public function the_nutrition_plan_editor_opens(): void
    {
        $piano = $this->pianoAlimentare();

        /*
         * 🚨 **E' il test che sarebbe stato rosso prima di G6.** Il modulo
         * puntava a `alternatives`, una colonna tolta: aprire questa pagina
         * dava un errore di colonna sconosciuta.
         */
        $this->actingAs($this->trainer)
            ->get("/admin/nutrition-plans/{$piano->getKey()}/edit")
            ->assertOk();
    }

    #[Test]
    public function the_nutrition_plan_list_opens(): void
    {
        $this->pianoAlimentare();

        $this->actingAs($this->trainer)
            ->get('/admin/nutrition-plans')
            ->assertOk();
    }

    #[Test]
    public function the_workout_plan_editor_opens(): void
    {
        $piano = $this->ctx()->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $this->palestra->id,
            'created_by' => $this->trainer->getKey(),
            'name' => 'Full body',
        ]));

        $this->actingAs($this->trainer)
            ->get("/admin/workout-plans/{$piano->getKey()}/edit")
            ->assertOk();
    }

    #[Test]
    public function creating_a_plan_from_the_panel_gives_it_a_stable_identity(): void
    {
        $piano = $this->pianoAlimentare();

        /*
         * 🚨 **D15 vale anche fuori dall'API.** Un piano nasce anche dal
         * pannello, e senza `origine_id` il telefono non lo riconoscerebbe come
         * versione nuova di uno che ha gia': lo affiancherebbe.
         *
         * 💡 Lo garantisce l'hook `NutritionPlan::booted()`, non il controller —
         * ed e' esattamente per casi come questo che sta li'.
         */
        $this->assertNotNull($piano->fresh()->origine_id);
    }

    #[Test]
    public function a_plan_written_from_the_panel_has_a_day(): void
    {
        $piano = $this->pianoAlimentare();

        // ⚠️ `nutrition_plan_meals.nutrition_plan_day_id` e' NOT NULL: senza il
        // giorno predefinito, salvare dal pannello darebbe un errore di
        // database invece di un messaggio.
        $this->assertSame(1, $piano->days()->count());
        $this->assertSame(1, $piano->days()->first()->meals()->count());
    }

    #[Test]
    public function the_private_note_is_hidden_from_the_other_trainers(): void
    {
        $piano = $this->pianoAlimentare();
        $altro = $this->creaUtente($this->palestra, UserRole::Trainer, 'altro@alfa.test');

        /*
         * 🚨 R4 — il «Rif. Allievo» lo vede **solo chi l'ha scritto**. Nel
         * pannello il campo e' nascosto, e nella tabella la colonna e' filtrata
         * dallo scoping del trainer.
         *
         * ⚠️ La pagina resta raggiungibile perche' `ScopedToTrainer` decide
         * cosa un trainer puo' aprire: quello che questo test difende e' che il
         * **contenuto del campo** non compaia.
         */
        $risposta = $this->actingAs($altro)->get("/admin/nutrition-plans/{$piano->getKey()}/edit");

        if ($risposta->status() === 200) {
            $risposta->assertDontSee('M.R. spalla dx');
        } else {
            // 💡 Anche «non lo apre affatto» e' una risposta corretta: e' lo
            // scoping del trainer che ha risposto prima.
            $this->assertContains($risposta->status(), [403, 404]);
        }
    }
}
