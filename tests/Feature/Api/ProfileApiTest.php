<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\BodyMetric;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * C1 — il profilo scritto dall'app.
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

    private function pesoDi(User $utente, float $kg): void
    {
        BodyMetric::create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'date' => today(),
            'weight_kg' => $kg,
        ]);
    }

    // ───────────────────────── scrittura ─────────────────────────

    #[Test]
    public function it_saves_the_profile_and_returns_the_derived_values(): void
    {
        $this->pesoDi($this->iscritto, 84.0);

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', [
                'sex' => 'm',
                'birthdate' => '1988-04-12',
                'height_cm' => 178,
                'activity_level' => 'moderate',
                'goal' => 'lose_weight',
            ])
            ->assertOk()
            ->assertJsonPath('data.sex', 'm')
            ->assertJsonPath('data.height_cm', 178)
            ->assertJsonPath('data.missing', [])
            // Il fabbisogno c'e' e i macro lo ricompongono: se `derived` fosse
            // null qui, l'app mostrerebbe ancora «Nessun obiettivo impostato».
            ->assertJsonStructure(['data' => ['derived' => [
                'bmi', 'bmr', 'tdee', 'target_kcal',
                'macros' => ['protein_g', 'carbs_g', 'fat_g'],
            ]]]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->iscritto->getKey(),
            'tenant_id' => $this->alfa->id,
            'height_cm' => 178,
            'goal' => 'lose_weight',
        ]);
    }

    #[Test]
    public function a_patch_does_not_erase_the_fields_it_does_not_mention(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['height_cm' => 180, 'goal' => 'maintain'])
            ->assertOk();

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['sex' => 'f'])
            ->assertOk()
            // L'altezza mandata prima e' ancora li': una PATCH che azzera cio'
            // che non nomina farebbe perdere i dati a chi salva un campo per
            // volta mentre compila.
            ->assertJsonPath('data.height_cm', 180)
            ->assertJsonPath('data.goal', 'maintain')
            ->assertJsonPath('data.sex', 'f');
    }

    // ───────────────────────── rifiuti ─────────────────────────

    #[Test]
    public function an_unknown_goal_is_rejected(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['goal' => 'dimagrire'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal');
    }

    #[Test]
    public function an_unknown_activity_level_is_rejected(): void
    {
        // `athlete` e' il vocabolario del CALCOLATORE, non del profilo (qui si
        // chiama `very_active`). Accettarlo lo farebbe tradurre in `sedentary`
        // da `activityForFormula()`, abbassando il fabbisogno **in silenzio**.
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['activity_level' => 'athlete'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('activity_level');
    }

    #[Test]
    public function a_birthdate_in_the_future_is_rejected(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['birthdate' => now()->addYear()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birthdate');
    }

    #[Test]
    public function a_malformed_meal_hour_is_rejected(): void
    {
        // Salvarlo non darebbe errore: farebbe sbagliare il pasto a ogni
        // inserimento di cibo, e nessuno collegherebbe le due cose.
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['meal_hours' => ['dinner' => '25:99']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meal_hours.dinner');
    }

    #[Test]
    public function an_unknown_meal_hour_key_is_dropped_without_failing_the_save(): void
    {
        // Un'app vecchia che manda una chiave in piu' non deve vedersi
        // rifiutare altezza e obiettivo per un campo secondario.
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', [
                'height_cm' => 175,
                'meal_hours' => ['dinner' => '19:00', 'merenda_serale' => '23:00'],
            ])
            ->assertOk()
            ->assertJsonPath('data.height_cm', 175)
            ->assertJsonPath('data.meal_hours.dinner', '19:00')
            ->assertJsonMissingPath('data.meal_hours.merenda_serale');
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

    #[Test]
    public function the_missing_list_shrinks_as_the_fields_arrive(): void
    {
        $this->pesoDi($this->iscritto, 80.0);

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['sex' => 'm', 'height_cm' => 180])
            ->assertOk()
            ->assertJsonPath('data.derived', null)
            // Resta solo la data di nascita: l'app puo' chiedere **quella**,
            // invece di dire genericamente che manca qualcosa.
            ->assertJsonPath('data.missing', ['birthdate']);
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
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['meal_hours' => ['dinner' => '18:30']])
            ->assertOk();

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
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['meal_hours' => ['lunch' => '12:15']])
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

    #[Test]
    public function each_member_only_ever_touches_their_own_profile(): void
    {
        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['height_cm' => 190])
            ->assertOk();

        // Nessun id nelle rotte: l'unico profilo raggiungibile e' il proprio, e
        // quello dell'altro non e' stato sfiorato.
        $this->comeApp($altro)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.height_cm', null);
    }

    #[Test]
    public function the_profile_of_another_gym_is_never_reachable(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $estraneo = $this->creaUtente($beta, UserRole::Member, 'anna@beta.test');

        $this->comeApp($estraneo)
            ->patchJson('/api/v1/profile', ['height_cm' => 165])
            ->assertOk();

        $this->assertDatabaseHas('profiles', [
            'user_id' => $estraneo->getKey(),
            'tenant_id' => $beta->id,
        ]);

        // Il profilo nasce nella palestra di chi lo scrive, mai in un'altra.
        $this->assertDatabaseMissing('profiles', [
            'user_id' => $estraneo->getKey(),
            'tenant_id' => $this->alfa->id,
        ]);
    }

    #[Test]
    public function the_profile_needs_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['height_cm' => 180])->assertUnauthorized();
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
                \App\Services\Nutrition\CalorieCalculator::ACTIVITY,
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
                \App\Services\Nutrition\CalorieCalculator::GOAL_DELTA,
                "L'obiettivo «{$obiettivo}» non ha una traduzione in goalForFormula().",
            );
        }
    }
}
