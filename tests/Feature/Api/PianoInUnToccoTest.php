<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanItem;
use App\Models\NutritionPlanMeal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * «Ho mangiato quello che c'era scritto» — F8.2/F8.3 della Parte B.
 *
 * ── 💡 Perché questa funzione decide se i piani servono a qualcosa ──────────
 *
 * **Se registrare un pasto del piano costa quanto scriverlo a mano, il piano non
 * lo usa nessuno.** Un piano alimentare che richiede di ridigitare sei alimenti
 * a ogni pasto viene abbandonato in una settimana — e con lui il lavoro del
 * trainer che l'ha scritto.
 *
 * ⚠️ **F8.1 non è stata fatta.** Il piano prescrive *«capire prima cosa non
 * funziona oggi — serve una sessione col committente»*, e quella sessione non
 * c'è stata. Quello che è implementato qui sono le due cose che il piano
 * dichiarava già decise: il tocco singolo e le alternative al momento della
 * scelta. Il resto di F8 aspetta.
 */
class PianoInUnToccoTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    private NutritionPlanMeal $colazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        $this->ctx()->runAs($this->alfa, function (): void {
            $piano = NutritionPlan::create([
                'tenant_id' => $this->alfa->id,
                'member_id' => $this->iscritto->id,
                'name' => 'Piano di prova',
                'starts_at' => now()->subWeek(),
            ]);

            // ⚠️ Un piano nasce in **bozza**, e `activeFor()` cerca solo i
            // pubblicati: senza questa riga l'endpoint risponde 404 e il test
            // sembrerebbe provare che la funzione non c'è. È la stessa regola
            // che impedisce a una bozza di finire addosso a qualcuno.
            $piano->publish();

            $this->colazione = NutritionPlanMeal::create([
                'nutrition_plan_id' => $piano->id,
                'meal' => MealType::Breakfast,
                'position' => 1,
                'title' => 'Colazione',
            ]);

            $pollo = NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $this->colazione->id,
                'position' => 1,
                'description' => '120 g di petto di pollo',
                'qty' => 120, 'unit' => 'g', 'grams' => 120,
                'kcal' => 198, 'protein' => 37, 'carbs' => 0, 'fat' => 4,
            ]);

            /*
             * ⚠️ Le alternative sono ciò che rende un piano praticabile.
             *
             * 🚨 **Da G4 sono righe, non più JSON** in una colonna: hanno gli
             * stessi campi dell'alimento che sostituiscono, ed è l'unico modo
             * perché scegliendone una il diario sappia cosa scrivere.
             */
            NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $this->colazione->id,
                'alternativa_di_id' => $pollo->getKey(),
                'position' => 0,
                'description' => '150 g di merluzzo',
                'grams' => 150, 'kcal' => 123, 'protein' => 27, 'fat' => 1,
            ]);

            NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $this->colazione->id,
                'position' => 2,
                'description' => '80 g di riso',
                'qty' => 80, 'unit' => 'g', 'grams' => 80,
                'kcal' => 280, 'protein' => 6, 'carbs' => 62, 'fat' => 1,
            ]);
        });
    }

    // ─────────────────────── F8.2 — un tocco ───────────────────────

    #[Test]
    public function one_call_writes_the_whole_meal(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")
            ->assertCreated()
            ->assertJsonPath('data.create', 2);

        $voci = FoodEntry::withoutGlobalScopes()->get();

        $this->assertCount(2, $voci);
        $this->assertSame(478.0, (float) $voci->sum('kcal'));
    }

    /**
     * 🚨 **La provenienza si conserva.**
     *
     * Il giorno in cui si vorrà sapere «quanto seguono davvero i piani» la
     * risposta deve essere una query, non una stima.
     */
    #[Test]
    public function the_entries_remember_they_came_from_a_plan(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")
            ->assertCreated();

        $voce = FoodEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(FoodSource::Plan, $voce->source);
        $this->assertNotNull($voce->nutrition_plan_id);
        $this->assertSame(MealType::Breakfast, $voce->meal);
    }

    /**
     * ⚠️ **L'ora è quella del pasto, non `now()`.**
     *
     * Registrare la colazione alle 22 con l'ora corrente la farebbe finire fra
     * le cene, e ogni schermata che deduce il pasto dall'ora la classificherebbe
     * di conseguenza.
     */
    #[Test]
    public function the_time_is_the_meal_time_not_the_moment_you_tap(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")
            ->assertCreated();

        $ora = FoodEntry::withoutGlobalScopes()->firstOrFail()->eaten_at;

        $this->assertLessThan(
            18,
            (int) $ora->copy()->setTimezone($this->iscritto->fusoOrario())->format('H'),
            'Una colazione è finita nel pomeriggio.',
        );
    }

    // ─────────────────── F8.3 — le alternative ───────────────────

    /**
     * «120 g di pollo **oppure** 150 g di merluzzo», scelto **al momento**.
     *
     * 🚨 Nella **stessa** richiesta: sepolta in una modifica successiva,
     * un'alternativa costa più che riscrivere l'alimento a mano — cioè non la
     * userebbe nessuno, ed è la funzione che rende un piano seguibile.
     */
    #[Test]
    public function an_alternative_can_be_chosen_in_the_same_call(): void
    {
        $pollo = NutritionPlanItem::query()
            ->where('description', '120 g di petto di pollo')->firstOrFail();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten", [
                'sostituzioni' => [$pollo->id => 0],
            ])
            ->assertCreated();

        $descrizioni = FoodEntry::withoutGlobalScopes()->pluck('description')->all();

        $this->assertContains('150 g di merluzzo', $descrizioni);
        $this->assertNotContains('120 g di petto di pollo', $descrizioni);
    }

    /**
     * 💡 **L'alternativa sovrascrive solo ciò che dichiara.**
     *
     * Nel piano di prova il merluzzo indica descrizione, grammi, kcal, proteine
     * e grassi — ma **non** i carboidrati. ⚠️ Azzerarli sarebbe sbagliato in
     * modo silenzioso: il totale del giorno tornerebbe più basso senza che
     * nessuno abbia scritto zero da nessuna parte.
     */
    #[Test]
    public function an_alternative_only_overrides_what_it_declares(): void
    {
        $pollo = NutritionPlanItem::query()
            ->where('description', '120 g di petto di pollo')->firstOrFail();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten", [
                'sostituzioni' => [$pollo->id => 0],
            ])
            ->assertCreated();

        $voce = FoodEntry::withoutGlobalScopes()
            ->where('description', '150 g di merluzzo')->firstOrFail();

        $this->assertSame(150.0, (float) $voce->grams);
        $this->assertSame(123.0, (float) $voce->kcal);
        // I carboidrati restano quelli della voce principale: 0.
        $this->assertSame(0.0, (float) $voce->carbs);
    }

    /**
     * ⚠️ **Un indice fuori intervallo non è un errore.**
     *
     * Un 422 in mezzo a un pasto costringerebbe a ricominciare per colpa di
     * un'alternativa che magari il trainer ha appena tolto dal piano.
     */
    #[Test]
    public function an_unknown_alternative_falls_back_to_the_main_item(): void
    {
        $pollo = NutritionPlanItem::query()
            ->where('description', '120 g di petto di pollo')->firstOrFail();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten", [
                'sostituzioni' => [$pollo->id => 99],
            ])
            ->assertCreated();

        $this->assertContains(
            '120 g di petto di pollo',
            FoodEntry::withoutGlobalScopes()->pluck('description')->all(),
        );
    }

    // ─────────────────────── i confini ───────────────────────

    /** ⚠️ Il pasto di un piano di qualcun altro non si registra. */
    #[Test]
    public function you_cannot_eat_someone_elses_plan(): void
    {
        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'luca@alfa.test');

        $this->comeApp($altro)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")
            ->assertNotFound();

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

    /**
     * 💡 **Si può registrare due volte, ed è deliberato.**
     *
     * Capita di mangiare due colazioni, e un server che lo impedisce costringe
     * a combattere con l'app invece che con la fame. L'app mostra il pasto come
     * già registrato guardando il diario: è lì che va risolto, non qui.
     */
    #[Test]
    public function eating_the_same_meal_twice_is_allowed(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")->assertCreated();
        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/nutrition-plan/meals/{$this->colazione->id}/eaten")->assertCreated();

        $this->assertSame(4, FoodEntry::withoutGlobalScopes()->count());
    }
}
