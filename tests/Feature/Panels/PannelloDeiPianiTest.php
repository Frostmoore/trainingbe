<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\CreateWorkoutPlan;
use App\Models\Exercise;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

        $this->palestra = $this->creaPalestra('Alfa', 'alfa');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'trainer@alfa.test');
    }

    private function esercizio(): Exercise
    {
        return Exercise::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => null, 'slug_normalized' => Exercise::normalize('Panca piana')],
            ['name' => 'Panca piana', 'muscle_group' => 'chest', 'is_custom' => false],
        );
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

    /**
     * ⛔ **I piani alimentari non stanno più nel pannello della palestra** —
     * 22/09/2026, fase F5.15.
     *
     * 📌 Il committente: *«Non esistono quelli vecchi, quindi si tolgono e
     * basta»*.
     *
     * 💡 **Le tabelle restano**, e non è un'incoerenza: i piani alimentari
     * vivono ancora nell'app, dove li scrive l'AI su richiesta della persona.
     * ⛔ Quello che sparisce è **la palestra che li scrive per qualcun altro** —
     * cioè un professionista che prescrive una dieta a una persona, che è una
     * cosa che non possiamo tenere sul server e che nessuno stava usando.
     *
     * 🚨 Il test guarda la **rotta**, non la classe: una risorsa cancellata e
     * una risorsa nascosta si distinguono solo così.
     */
    #[Test]
    public function il_pannello_della_palestra_non_ha_piu_i_piani_alimentari(): void
    {
        $this->actingAs($this->trainer)->get('/admin/nutrition-plans')->assertNotFound();
        $this->actingAs($this->trainer)->get('/admin/nutrition-plans/create')->assertNotFound();
    }

    #[Test]
    public function the_create_pages_open(): void
    {
        /*
         * 🚨 **La pagina che il primo test non provava.**
         *
         * `PannelloDeiPianiTest` apriva `/edit` e non `/create`, e sono due
         * pagine diverse: su `/create` non c'e' nessun record, quindi i
         * `Repeater` annidati partono da zero invece che da righe esistenti.
         */
        $this->actingAs($this->trainer)->get('/admin/workout-plans/create')->assertOk();
    }

    #[Test]
    public function a_workout_plan_can_actually_be_saved_from_the_panel(): void
    {
        /*
         * 🚨 **Aprire una pagina e salvarla sono due prove diverse**, e la prima
         * non implica la seconda: sul `create` i `Repeater` annidati partono
         * vuoti, e il problema nasce quando le righe si scrivono davvero.
         */
        // ⚠️ `Livewire::test()` non passa da `ResolveTenant`: il contesto va
        // impostato a mano, o `BelongsToTenant` non riempie `tenant_id`. Vedi
        // la nota in `RoleScopingTest::entraCome()`.
        $this->actingAs($this->trainer);
        app(TenantContext::class)->set($this->palestra);

        Livewire::test(CreateWorkoutPlan::class)
            ->fillForm([
                'name' => 'Split',
                'status' => PlanStatus::Draft->value,
                'days' => [
                    [
                        'name' => 'Giorno A',
                        'exercises' => [
                            ['exercise_id' => $this->esercizio()->getKey(), 'sets' => 4, 'reps' => '8-10'],
                        ],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $piano = WorkoutPlan::withoutGlobalScopes()->where('name', 'Split')->firstOrFail();

        $this->assertSame(1, $piano->days()->count());
        $this->assertSame(1, $piano->exercises()->count());
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

    /**
     * ⚠️ **Da F5.15 questi tre non parlano più del pannello**, e restano.
     *
     * 💡 I ganci di `NutritionPlan::booted()` — identità stabile, giorno
     * predefinito, nessun destinatario — servono ancora: i piani alimentari
     * **vivono nell'app**, dove li scrive l'AI su richiesta della persona.
     * ⛔ Quello che è sparito è la palestra che li scrive per qualcun altro.
     */
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
    public function the_panel_cannot_tie_a_plan_to_a_person(): void
    {
        /*
         * 🚨 **La regola del 14/08/2026, resa verificabile.**
         *
         * «Il campo assegnato a non ha senso: non dobbiamo mantenere cose
         * mediche. Deve diventare un Rif. Allievo in cui il trainer puo'
         * pseudonimizzare l'allievo.»
         *
         * ⚠️ Legare un piano a una persona **sul server** significa scrivere in
         * tabella che quella persona segue quel programma — e da un programma
         * post-infortunio si capisce cos'e' successo a chi lo esegue.
         *
         * 💡 Questo test guarda **il modulo**, non il database: la colonna
         * `member_id` resta e serve alle schede che l'iscritto scrive per se'.
         * Quello che non deve esistere e' il campo con cui la palestra crea quel
         * legame.
         */
        $this->actingAs($this->trainer);
        app(TenantContext::class)->set($this->palestra);

        /*
         * 🆕 ⛔ **Dal 22/09/2026 la pagina è una sola** (F5.15): i piani
         * alimentari non si scrivono più dal pannello della palestra.
         */
        $campi = array_keys(Livewire::test(CreateWorkoutPlan::class)->instance()->form->getFlatFields());

        $this->assertNotContains(
            'member_id',
            $campi,
            "il modulo puo' ancora legare una scheda a una persona",
        );

        // 💡 E al suo posto c'e' la pseudonimizzazione.
        $this->assertContains('rif_allievo', $campi, 'manca il Riferimento');
    }

    #[Test]
    public function a_plan_written_from_the_panel_is_always_anonymous(): void
    {
        $piano = $this->pianoAlimentare();

        // 🚨 D4 — nessun allievo sul server. È la forma che si può mandare in
        // chat, ed è l'unica che il pannello sa produrre.
        $this->assertNull($piano->fresh()->member_id);
    }

    #[Test]
    public function the_private_note_is_hidden_from_the_other_trainers(): void
    {
        $scheda = $this->ctx()->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'name' => 'Riabilitazione',
            'status' => PlanStatus::Draft,
            'created_by' => $this->trainer->getKey(),
            'rif_allievo' => 'M.R. spalla dx',
        ]));

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
        $risposta = $this->actingAs($altro)->get("/admin/workout-plans/{$scheda->getKey()}/edit");

        if ($risposta->status() === 200) {
            $risposta->assertDontSee('M.R. spalla dx');
        } else {
            // 💡 Anche «non lo apre affatto» e' una risposta corretta: e' lo
            // scoping del trainer che ha risposto prima.
            $this->assertContains($risposta->status(), [403, 404]);
        }
    }
}
