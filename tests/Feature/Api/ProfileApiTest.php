<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Nutrition\CalorieCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * C1 — il profilo scritto dall'app. 🆕 **Dal 19/09/2026 non piu'**: sta sul
 * telefono, e il server lo lascia solo leggere (per il trasloco) e cancellare.
 *
 * Fino alla fase C il profilo si modificava **solo** dal pannello della
 * palestra: nell'app non c'era modo di impostare altezza, eta' e obiettivo, e
 * senza quelli non esiste un target calorico.
 */
class ProfileApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    // ─────────────── 🆕 il profilo sta sul telefono — 19/09/2026 ───────────────

    /**
     * 🚨 **Il server non accetta piu' il profilo.** 📌 Il committente:
     * *«assicurati che non ci scriva niente di personale (esattamente come peso
     * altezza e data di nascita)»*. ⛔ Qui c'erano sette test che salvavano
     * sesso, data di nascita e altezza con `PATCH /profile`.
     *
     * 💡 410 e non un «ok» che ignora: un'app vecchia deve sapere che non ha
     * salvato niente.
     */
    #[Test]
    public function the_server_no_longer_accepts_the_profile(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['sex' => 'm', 'birthdate' => '1988-04-12', 'height_cm' => 178])
            ->assertStatus(410)
            ->assertJsonPath('code', 'profilo_sul_telefono');

        $this->assertDatabaseMissing('profiles', ['user_id' => $this->iscritto->getKey(), 'height_cm' => 178]);
    }

    /**
     * 📦 **Il trasloco: prima si legge, poi si cancella.** `GET` resta per
     * portare a casa quello che c'era; `DELETE` toglie i dati personali e
     * lascia le note dello staff, che le scrive la palestra.
     */
    #[Test]
    public function after_the_move_the_personal_fields_leave_the_server(): void
    {
        Profile::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $this->iscritto->getKey(),
            'sex' => 'm',
            'birthdate' => '1988-04-12',
            'height_cm' => 178,
            'activity_level' => 'moderate',
            'goal' => 'lose_slow',
            'target_weight_kg' => 75,
            'meal_hours' => ['dinner' => '19:00'],
            'notes' => 'Spalla destra delicata',
        ]);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.height_cm', 178);

        $this->comeApp($this->iscritto)->deleteJson('/api/v1/profile')->assertOk();

        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->iscritto->getKey(),
            'sex' => null,
            'birthdate' => null,
            'height_cm' => null,
            'activity_level' => null,
            'goal' => null,
            'target_weight_kg' => null,
            'meal_hours' => null,
            'notes' => 'Spalla destra delicata',
        ]);
    }

    /** 💡 Chi non ha mai avuto un profilo sul server: niente da togliere, e nessun errore. */
    #[Test]
    public function deleting_a_profile_that_does_not_exist_is_fine(): void
    {
        $this->comeApp($this->iscritto)->deleteJson('/api/v1/profile')->assertOk();
    }

    // ───────────────────────── cosa manca ─────────────────────────

    #[Test]
    public function without_the_ingredients_there_is_no_target_and_it_says_which_ones(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.derived', null)
            ->assertJsonPath('data.missing', ['weight_kg', 'sex', 'birthdate', 'height_cm']);
    }

    // ───────────────────── gli orari dei pasti ─────────────────────

    /**
     * 🚨 **Il test che dimostra che `meal_hours` non e' decorativa.**
     *
     * Prima della fase C la colonna si salvava, compariva nell'API e **non era
     * letta da nessuna riga di codice**: `MealType::fromHour()` aveva le soglie
     * scritte a mano. Chi spostava la cena vedeva il campo salvarsi e il cibo
     * continuare a finire nel pasto sbagliato.
     *
     * Con le soglie fisse un cibo delle 19:00 e' `afternoon_snack` (`< 22` →
     * cena solo dalle 18). Con la cena spostata alle 18:30 dev'essere `dinner`.
     */
    #[Test]
    public function custom_meal_hours_change_which_meal_a_food_lands_in(): void
    {
        // 🆕 19/09: il profilo non si scrive piu' via API — gli orari li ha chi
        // non ha ancora fatto il trasloco.
        Profile::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $this->iscritto->getKey(),
            'meal_hours' => ['dinner' => '18:30'],
        ]);

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/food-entries', [
                'description' => 'Pollo',
                'kcal' => 300,
                'eaten_at' => today()->setTime(19, 0)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.meal', MealType::Dinner->value);
    }

    #[Test]
    public function in_the_small_hours_the_meal_is_the_last_of_the_day_not_breakfast(): void
    {
        // Alle 2:00 nessun pasto e' ancora cominciato. La risposta giusta e'
        // l'ultimo della giornata — chi e' rimasto sveglio sta facendo lo
        // spuntino serale, non la colazione di un giorno che deve iniziare.
        // E' il caso per cui non basta un `match` di soglie crescenti.
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/food-entries', [
                'description' => 'Biscotti',
                'kcal' => 200,
                'eaten_at' => today()->setTime(2, 0)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.meal', MealType::EveningSnack->value);
    }

    #[Test]
    public function the_six_meal_hours_are_always_returned_defaults_included(): void
    {
        Profile::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $this->iscritto->getKey(),
            'meal_hours' => ['lunch' => '12:15'],
        ]);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.meal_hours.lunch', '12:15')
            // Gli altri cinque ci sono lo stesso: l'app disegna il modulo senza
            // dover conoscere i valori di serie, che altrimenti finirebbero
            // scritti anche in Dart.
            ->assertJsonPath('data.meal_hours.breakfast', MealType::DEFAULT_HOURS['breakfast'])
            ->assertJsonCount(6, 'data.meal_hours');
    }

    /**
     * 🚨 Trovato su staging il 2026-08-10, non da un test.
     *
     * Il seeder dimostrativo aveva scritto le chiavi in italiano
     * (`colazione`/`pranzo`/`cena`): non corrispondendo a nessun `MealType` non
     * avevano alcun effetto sul calcolo, ma `GET /profile` le restituiva lo
     * stesso, e la risposta mostrava **nove** pasti invece di sei. In scrittura
     * le scarta `UpdateProfileRequest`; mancava la stessa severita' in lettura.
     */
    #[Test]
    public function junk_meal_hour_keys_already_in_the_column_are_not_returned(): void
    {
        Profile::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $this->iscritto->getKey(),
            'meal_hours' => ['cena' => '20:00', 'dinner' => '19:00'],
        ]);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonCount(6, 'data.meal_hours')
            ->assertJsonPath('data.meal_hours.dinner', '19:00');

        $this->assertArrayNotHasKey('cena', $risposta->json('data.meal_hours'));
    }

    // ───────────────────────── isolamento ─────────────────────────

    /** Nessun id nelle rotte: si toglie solo il proprio, mai quello di un altro. */
    #[Test]
    public function each_member_only_ever_clears_their_own_profile(): void
    {
        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        Profile::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $altro->getKey(),
            'height_cm' => 190,
        ]);

        $this->comeApp($this->iscritto)->deleteJson('/api/v1/profile')->assertOk();

        $this->assertDatabaseHas('profiles', ['user_id' => $altro->getKey(), 'height_cm' => 190]);
    }

    #[Test]
    public function the_profile_needs_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['height_cm' => 180])->assertUnauthorized();
        $this->deleteJson('/api/v1/profile')->assertUnauthorized();
    }

    // ───────────────────── il vocabolario resta unico ─────────────────────

    /**
     * 🚨 Il modello traduce il proprio vocabolario in quello del calcolatore.
     * Se qualcuno aggiunge un livello a `Profile::ACTIVITY_LEVELS` e si scorda
     * il `match` di `activityForFormula()`, quel livello viene tradotto in
     * `sedentary` **senza errore** e il fabbisogno risulta piu' basso del vero.
     */
    #[Test]
    public function every_declared_activity_level_has_a_translation(): void
    {
        foreach (array_keys(Profile::ACTIVITY_LEVELS) as $livello) {
            $profilo = new Profile(['activity_level' => $livello]);

            $this->assertArrayHasKey(
                $profilo->activityForFormula(),
                CalorieCalculator::ACTIVITY,
                "Il livello «{$livello}» non ha una traduzione in activityForFormula().",
            );

            // `sedentary` e' anche il default del `match`: e' l'unico che puo'
            // tradursi in sé stesso senza che sia un sintomo.
            if ($livello !== 'sedentary') {
                $this->assertNotSame(
                    'sedentary',
                    $profilo->activityForFormula(),
                    "Il livello «{$livello}» ricade sul default: manca dal match di activityForFormula().",
                );
            }
        }
    }

    #[Test]
    public function every_declared_goal_has_a_translation(): void
    {
        foreach (array_keys(Profile::GOALS) as $obiettivo) {
            $profilo = new Profile(['goal' => $obiettivo]);

            $this->assertArrayHasKey(
                $profilo->goalForFormula(),
                CalorieCalculator::GOAL_DELTA,
                "L'obiettivo «{$obiettivo}» non ha una traduzione in goalForFormula().",
            );
        }
    }
}
