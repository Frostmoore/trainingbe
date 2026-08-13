<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanItem;
use App\Models\PlanExercise;
use App\Models\WorkoutPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Giorni e alternative — G4 (D2, D10).
 *
 * 🚨 **Il test piu' importante di questa classe e'
 * `an_alternative_never_shows_up_as_a_real_row()`.** Da D2 le alternative sono
 * righe della **stessa tabella**: se un elenco non le esclude, un piano da 4
 * pasti ne mostra 11 — nel pannello, nell'API e dentro la busta cifrata. E non
 * darebbe nessun errore: darebbe un piano piu' lungo.
 */
final class GiorniEAlternativeTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function scheda(): WorkoutPlan
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'trainer@alfa.test');

        return $this->ctx()->runAs($palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $palestra->id,
            'created_by' => $trainer->getKey(),
            'name' => 'Full body',
        ]));
    }

    private function esercizio(string $nome = 'Panca piana'): Exercise
    {
        return Exercise::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => null, 'slug_normalized' => Exercise::normalize($nome)],
            ['name' => $nome, 'muscle_group' => 'chest', 'is_custom' => false],
        );
    }

    // ───────────────────── i giorni ─────────────────────

    #[Test]
    public function an_exercise_written_without_a_day_lands_in_the_default_one(): void
    {
        $piano = $this->scheda();

        $riga = PlanExercise::create([
            'workout_plan_id' => $piano->getKey(),
            'exercise_id' => $this->esercizio()->getKey(),
            'position' => 0,
        ]);

        /*
         * 🚨 **La colonna e' `NOT NULL` e nessuno l'ha valorizzata qui.**
         *
         * L'hook di `PlanExercise::booted()` ricava il giorno dalla **scheda che
         * la riga dichiara gia'**: non e' magia che nasconde un errore, e' cio'
         * che rende impossibile violare un invariante che sei punti diversi del
         * codice dovrebbero altrimenti ricordarsi.
         */
        $this->assertNotNull($riga->workout_plan_day_id);
        $this->assertSame($piano->getKey(), $riga->day->workout_plan_id);
    }

    #[Test]
    public function the_default_day_is_created_once_not_at_every_row(): void
    {
        $piano = $this->scheda();

        foreach (range(1, 3) as $i) {
            PlanExercise::create([
                'workout_plan_id' => $piano->getKey(),
                'exercise_id' => $this->esercizio("Esercizio {$i}")->getKey(),
                'position' => $i,
            ]);
        }

        // ⚠️ Tre esercizi, **un** giorno. Un giorno per riga farebbe una scheda
        // a tre giorni da una a uno — e nessuno se ne accorgerebbe finche' non
        // la apre.
        $this->assertSame(1, $piano->days()->count());
        $this->assertSame(3, $piano->days()->first()->exercises()->count());
    }

    // ───────────────────── le alternative ─────────────────────

    #[Test]
    public function an_alternative_never_shows_up_as_a_real_row(): void
    {
        $piano = $this->scheda();
        $giorno = $piano->giornoPredefinito();

        $principale = PlanExercise::create([
            'workout_plan_id' => $piano->getKey(),
            'workout_plan_day_id' => $giorno->getKey(),
            'exercise_id' => $this->esercizio('Panca piana')->getKey(),
            'position' => 0,
        ]);

        PlanExercise::create([
            'workout_plan_id' => $piano->getKey(),
            'workout_plan_day_id' => $giorno->getKey(),
            'alternativa_di_id' => $principale->getKey(),
            'exercise_id' => $this->esercizio('Panca con manubri')->getKey(),
            'position' => 0,
        ]);

        /*
         * 🚨 **Due righe in tabella, un esercizio da fare.**
         *
         * Se una qualsiasi di queste tre relazioni smettesse di filtrare, la
         * scheda si allungherebbe in silenzio. E' successo davvero: prima di G4
         * `WorkoutPlan::exercises()` non filtrava, ed era corretto — le
         * alternative non esistevano. Dal momento in cui esistono, la stessa
         * riga di codice e' sbagliata.
         */
        $this->assertSame(2, PlanExercise::withoutGlobalScopes()->count());
        $this->assertSame(1, $piano->exercises()->count());
        $this->assertSame(1, $giorno->exercises()->count());
        $this->assertSame(2, $piano->exercisesConAlternative()->count());

        $this->assertTrue($principale->alternative()->exists());
        $this->assertFalse($principale->eUnAlternativa());
        $this->assertTrue($principale->alternative->first()->eUnAlternativa());
    }

    #[Test]
    public function deleting_the_main_row_takes_its_alternatives_with_it(): void
    {
        $piano = $this->scheda();
        $giorno = $piano->giornoPredefinito();

        $principale = PlanExercise::create([
            'workout_plan_id' => $piano->getKey(),
            'workout_plan_day_id' => $giorno->getKey(),
            'exercise_id' => $this->esercizio()->getKey(),
            'position' => 0,
        ]);

        PlanExercise::create([
            'workout_plan_id' => $piano->getKey(),
            'workout_plan_day_id' => $giorno->getKey(),
            'alternativa_di_id' => $principale->getKey(),
            'exercise_id' => $this->esercizio('Croci')->getKey(),
            'position' => 0,
        ]);

        $principale->delete();

        /*
         * ⚠️ Lasciarle orfane sarebbe peggio che cancellarle: senza il loro
         * originale, un `alternativa_di_id` che punta al nulla le farebbe
         * tornare a comparire come esercizi veri.
         */
        $this->assertSame(0, PlanExercise::withoutGlobalScopes()->count());
    }

    #[Test]
    public function copying_a_plan_re_points_the_alternatives_to_the_copy(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'trainer@alfa.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $modello = $this->ctx()->runAs($palestra, function () use ($palestra, $trainer): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $palestra->id,
                'created_by' => $trainer->getKey(),
                'name' => 'Modello',
            ]);

            $giorno = $piano->giornoPredefinito();

            $principale = PlanExercise::create([
                'workout_plan_id' => $piano->getKey(),
                'workout_plan_day_id' => $giorno->getKey(),
                'exercise_id' => $this->esercizio('Squat')->getKey(),
                'position' => 0,
            ]);

            PlanExercise::create([
                'workout_plan_id' => $piano->getKey(),
                'workout_plan_day_id' => $giorno->getKey(),
                'alternativa_di_id' => $principale->getKey(),
                'exercise_id' => $this->esercizio('Pressa')->getKey(),
                'position' => 0,
            ]);

            return $piano;
        });

        $copia = $this->ctx()->runAs($palestra, fn (): WorkoutPlan => $modello->assignTo($iscritto, $trainer));

        $alternativaCopiata = $copia->exercisesConAlternative()
            ->whereNotNull('alternativa_di_id')->firstOrFail();

        $principaleCopiato = $copia->exercises()->firstOrFail();

        /*
         * 🚨 **Il difetto che questo test esiste per impedire.**
         *
         * Copiando le alternative alla cieca, `alternativa_di_id` resterebbe
         * agganciato all'esercizio del **modello**. Effetto: modificare il
         * modello cambierebbe le alternative di tutte le copie — cioe'
         * esattamente cio' che `assignTo()` esiste per impedire, e per di piu'
         * in modo invisibile.
         */
        $this->assertSame($principaleCopiato->getKey(), $alternativaCopiata->alternativa_di_id);
        $this->assertNotSame($modello->getKey(), $copia->getKey());

        // ⚠️ E l'identita' e' **nuova**: una copia non e' una versione nuova
        // dello stesso piano, e' un altro piano (D15).
        $this->assertNotSame($modello->origine_id, $copia->origine_id);
        $this->assertNotNull($copia->origine_id);
    }

    // ───────────────────── i piani alimentari ─────────────────────

    #[Test]
    public function a_meal_total_does_not_count_its_alternatives(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'trainer@alfa.test');

        $this->ctx()->runAs($palestra, function () use ($palestra, $trainer): void {
            $piano = NutritionPlan::create([
                'tenant_id' => $palestra->id,
                'created_by' => $trainer->getKey(),
                'name' => 'Piano',
            ]);

            $pasto = $piano->meals()->create([
                'nutrition_plan_day_id' => $piano->giornoPredefinito()->getKey(),
                'meal' => MealType::Lunch->value,
                'position' => 0,
            ]);

            $principale = NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $pasto->getKey(),
                'position' => 0,
                'description' => '120 g di petto di pollo',
                'kcal' => 198,
            ]);

            NutritionPlanItem::create([
                'nutrition_plan_meal_id' => $pasto->getKey(),
                'alternativa_di_id' => $principale->getKey(),
                'position' => 0,
                'description' => '150 g di merluzzo',
                'kcal' => 123,
            ]);

            /*
             * 🚨 198, non 321. Contare le alternative gonfierebbe ogni pasto: un
             * pranzo con due alternative varrebbe tre pranzi, e nessuno se ne
             * accorgerebbe finche' un trainer non fa il conto a mano.
             */
            $this->assertSame(198.0, $pasto->fresh()->totals()['kcal']);

            $this->assertSame(1, $pasto->items()->count());
            $this->assertSame(2, $pasto->itemsConAlternative()->count());
        });
    }

    #[Test]
    public function every_plan_gets_a_stable_identity(): void
    {
        $piano = $this->scheda();

        // D15 — serve al telefono per riconoscere la versione nuova di un piano
        // che ha gia'. 🚨 Il titolo non basta: due piani possono chiamarsi
        // uguale, e un piano puo' cambiare nome restando lo stesso.
        $this->assertNotNull($piano->fresh()->origine_id);
        $this->assertSame(26, strlen((string) $piano->fresh()->origine_id));
    }
}
