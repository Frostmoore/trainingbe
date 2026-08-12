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
use Illuminate\Support\Carbon;
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

    /**
     * Il pasto si deduce dall'ora quando l'app non lo dice.
     *
     * 🚨 **L'ora e' quella dell'orologio di chi mangia, non UTC** — A3.
     *
     * ⚠️ Prima questo test costruiva l'istante con `today()->setTime(8, 30)`,
     * cioe' **le 8:30 UTC**, e passava solo perche' il server tagliava la
     * giornata nello stesso fuso sbagliato. A Roma quelle sono le 10:30, e il
     * cornetto finiva nella merenda: il test verde certificava il difetto.
     */
    #[Test]
    public function the_meal_is_inferred_from_the_hour(): void
    {
        $colazione = Carbon::parse(
            $this->iscritto->dataDiOggi().' 08:30',
            $this->iscritto->fusoOrario(),
        );

        $this->comeIscritto()
            ->postJson('/api/v1/food-entries', [
                'description' => 'Cornetto',
                'eaten_at' => $colazione->toIso8601String(),
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

    // ───────────────── modifica con ricalcolo (C15) ─────────────────

    /** Una voce con i valori per 100 g: è ciò che rende possibile il ricalcolo. */
    private function voceConPer100(): FoodEntry
    {
        return $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Petto di pollo',
            'qty' => 100,
            'unit' => 'g',
            'grams' => 100,
            'kcal' => 165,
            'protein' => 31,
            'carbs' => 0,
            'fat' => 3.6,
            'kcal_100' => 165,
            'protein_100' => 31,
            'carbs_100' => 0,
            'fat_100' => 3.6,
        ]));
    }

    /**
     * 🚨 Cambiando la quantità i macro si aggiornano **da soli**, e li ricalcola
     * il server.
     *
     * Se lo facesse l'app dovrebbe avere una seconda copia della tabella
     * unità→grammi e dei valori per 100 g: il giorno che ne cambia una sola, il
     * diario mostrerebbe un totale e il database ne conterrebbe un altro senza
     * che niente lo segnali.
     */
    #[Test]
    public function changing_the_quantity_rescales_the_macros(): void
    {
        $voce = $this->voceConPer100();

        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['qty' => 200])
            ->assertOk()
            ->assertJsonPath('data.grams', 200.0)
            ->assertJsonPath('data.kcal', 330.0)
            ->assertJsonPath('data.protein', 62.0);
    }

    /**
     * ⚠️ I macro mandati **vincono sempre**: se l'utente li ha corretti a mano
     * non si sovrascrivono con una stima.
     */
    #[Test]
    public function macros_sent_by_hand_win_over_the_recalculation(): void
    {
        $voce = $this->voceConPer100();

        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['qty' => 200, 'kcal' => 300])
            ->assertOk()
            ->assertJsonPath('data.grams', 200.0)
            ->assertJsonPath('data.kcal', 300.0)
            // Gli altri, non mandati, si riscalano lo stesso.
            ->assertJsonPath('data.protein', 62.0);
    }

    /**
     * 🚨 Il fattore per unità viene **dalla voce**, non dalla tabella generica.
     *
     * Se l'AI ha detto che un cucchiaio di QUELL'olio pesa 14 g, raddoppiando
     * devono venire 28 g — non i 30 della conversione generica, che non sa di
     * che alimento si tratta.
     */
    #[Test]
    public function the_food_aware_factor_survives_a_quantity_change(): void
    {
        $voce = $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Olio di oliva',
            'qty' => 1,
            'unit' => 'cucchiaio',
            'grams' => 14,
            'kcal' => 126,
            'kcal_100' => 900,
        ]));

        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['qty' => 2])
            ->assertOk()
            ->assertJsonPath('data.grams', 28.0)
            ->assertJsonPath('data.kcal', 252.0);
    }

    #[Test]
    public function explicit_grams_win_over_any_conversion(): void
    {
        $voce = $this->voceConPer100();

        // L'utente ha pesato la porzione: nessuna tabella sa più di una bilancia.
        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['qty' => 2, 'unit' => 'cucchiaio', 'grams' => 37])
            ->assertOk()
            ->assertJsonPath('data.grams', 37.0)
            ->assertJsonPath('data.kcal', 61.05);
    }

    /**
     * 🚨 **I valori per 100 g si DERIVANO dagli assoluti** — difetto riferito
     * provando l'app il 12/08/2026.
     *
     * ⚠️ Questo test diceva il contrario — *«senza valori per 100 g non si
     * inventa niente»* — e passava. Ma lo schema dell'AI **non ha nessun campo
     * `_100`**: chiede `grams`, `kcal` e i macro e basta. Quindi **ogni** voce
     * creata dall'AI nasceva senza riferimento, e cambiarne la quantità lasciava
     * i macro fermi ai valori di prima. È esattamente il sintomo riferito:
     * *«quando vado a modificarle a mano non mi ricalcola nulla»*.
     *
     * 💡 Derivare non è inventare: se 300 g valgono 500 kcal, 100 g ne valgono
     * 166,67. È l'informazione che c'era già, scritta in una forma riscalabile.
     */
    #[Test]
    public function the_per_100_values_are_derived_so_a_manual_edit_recomputes(): void
    {
        $voce = $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Piatto misto',
            'qty' => 300,
            'unit' => 'g',
            'grams' => 300,
            'kcal' => 500,
        ]));

        $this->assertEqualsWithDelta(166.67, $voce->fresh()->kcal_100, 0.01);

        // Metà porzione ⇒ metà calorie. Prima restavano 500.
        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['grams' => 150])
            ->assertOk()
            ->assertJsonPath('data.kcal', 250.01);
    }

    /**
     * 🚨 **«Pezzi» non è un'unità di misura** — difetto riferito il 12/08/2026:
     * *«Ho scritto "Due cotolette di pollo" e me le segna come 2 pezzi»*.
     *
     * L'AI risponde con l'unità con cui la persona ha parlato, che non sta in
     * `FoodUnit::FACTORS` e non si può convertire: un pezzo non ha un peso.
     *
     * 💡 Ma i grammi l'AI li ha già dati, perché sa di che alimento si parla:
     * si tiene il peso e si riscrive la quantità in grammi.
     *
     * ⚠️ **La descrizione resta intatta**, quindi «erano due» non si perde.
     */
    #[Test]
    public function an_unknown_unit_becomes_grams(): void
    {
        $voce = $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Due cotolette di pollo',
            'qty' => 2,
            'unit' => 'pezzi',
            'grams' => 300,
            'kcal' => 480,
        ]))->fresh();

        $this->assertSame('g', $voce->unit);
        $this->assertSame(300.0, $voce->qty);
        $this->assertSame(300.0, $voce->grams);
        $this->assertSame('Due cotolette di pollo', $voce->description);

        // E adesso si può correggere a mano, che era il punto.
        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['qty' => 150])
            ->assertOk()
            ->assertJsonPath('data.grams', 150.0)
            ->assertJsonPath('data.kcal', 240.0);
    }

    /**
     * ⚠️ Senza grammi non si deriva niente, e va bene così: una voce scritta a
     * mano senza peso non ha nessun riferimento da cui riscalare, e inventarne
     * uno darebbe un numero plausibile e falso.
     */
    #[Test]
    public function without_grams_nothing_is_invented(): void
    {
        $voce = $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Un piatto a occhio',
            'unit' => 'pezzi',
            'kcal' => 500,
        ]))->fresh();

        $this->assertNull($voce->grams);
        $this->assertNull($voce->kcal_100);

        // L'unità sconosciuta resta: senza peso non c'è niente in cui
        // convertirla, e riscriverla in grammi sarebbe una bugia.
        $this->assertSame('pezzi', $voce->unit);
    }

    #[Test]
    public function renaming_an_entry_does_not_touch_the_quantity(): void
    {
        $voce = $this->voceConPer100();

        // Nessun campo di quantità nella richiesta: non deve scattare nessun
        // ricalcolo, o rinominare una voce ne cambierebbe i numeri.
        $this->comeIscritto()
            ->patchJson("/api/v1/food-entries/{$voce->id}", ['description' => 'Pollo alla griglia'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Pollo alla griglia')
            ->assertJsonPath('data.grams', 100.0)
            ->assertJsonPath('data.kcal', 165.0);
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

    /**
     * 🚨 **Senza piano, il server non calcola nessun target** — S5.5.
     *
     * Non perche' manchi la formula: perche' manca **il peso**, che da S5 vive
     * solo sul telefono (decisione D9-bis). Il target del profilo lo calcola
     * l'app con `CalcolatoreCalorie`, il ritratto fedele di `CalorieCalculator`
     * verificato con gli stessi valori attesi (S5.1).
     *
     * ⚠️ E il server **non inventa niente**: restituisce `null`, non uno zero
     * ne' un valore di ripiego. Un target calorico sbagliato non e' un numero
     * storto — e' una dieta storta.
     */
    #[Test]
    public function without_a_plan_the_server_computes_no_target_and_says_so(): void
    {
        $this->profiloCompleto();

        $risposta = $this->comeIscritto()->getJson('/api/v1/targets')->assertOk();

        $this->assertNull($risposta->json('data.kcal_base'));
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

    /**
     * 🚨 **Vale su OGNI strada**, perche' la guardia sta nel `saving()` del
     * modello e non in una regola di validazione. Una `Rule` andrebbe ripetuta in
     * cinque controller e dimenticata nel sesto.
     */
    #[Test]
    public function the_mass_guard_holds_on_the_ai_path_too(): void
    {
        $this->iscritto->registraConsenso('ai_consent_at', true);

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'items' => [
                    ['name' => 'Coppiette', 'grams' => 100, 'kcal' => 588, 'protein' => 56, 'carbs' => 4, 'fat' => 40],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'macros_exceed_mass');

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

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
