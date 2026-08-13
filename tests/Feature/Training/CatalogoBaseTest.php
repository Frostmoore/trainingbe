<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\MuscleGroup;
use App\Models\Exercise;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il catalogo esercizi della piattaforma — G3 (D9).
 *
 * 🚨 **Perche' questa classe esiste, ed e' una storia breve.**
 *
 * `ExerciseLibrarySeeder` si dichiara idempotente dal B2.4 — nel proprio
 * commento **e** nell'atlante — e **nessun test l'ha mai lanciato due volte**.
 * Era una promessa scritta in due posti e verificata in nessuno.
 *
 * ⚠️ E la promessa non e' teorica: un seeder che duplicasse gli esercizi
 * riempirebbe le tendine di doppioni a ogni deploy; uno che li cancellasse e
 * ricreasse spezzerebbe `plan_exercises.exercise_id` — cioe' svuoterebbe le
 * schede gia' scritte, in silenzio, perche' la FK e' `restrictOnDelete` solo
 * finche' qualcuno non la aggira.
 */
final class CatalogoBaseTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    #[Test]
    public function the_catalogue_covers_every_muscle_group(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $totale = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->count();

        // G3.2 — almeno 120. Prima di G3 erano 50.
        $this->assertGreaterThanOrEqual(120, $totale, "solo {$totale} esercizi nel catalogo base");

        foreach (MuscleGroup::cases() as $gruppo) {
            $quanti = Exercise::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('muscle_group', $gruppo->value)
                ->count();

            /*
             * 🚨 **Nessun gruppo a zero, e nessuno a uno.** `G0.2` aveva
             * misurato `Forearms` **0** e `Hamstrings` **1**: un gruppo vuoto
             * non e' «meno scelta», e' un trainer che apre la tendina, non trova
             * niente, e da li' in poi scrive tutto a mano — cioe' il catalogo
             * smette di esistere per lui.
             */
            $this->assertGreaterThanOrEqual(
                3,
                $quanti,
                "il gruppo {$gruppo->value} ha solo {$quanti} esercizi",
            );
        }
    }

    #[Test]
    public function running_the_seeder_twice_changes_nothing(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $prima = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->count();
        $idPrima = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->pluck('id')->sort()->values();

        // 🚨 La riga che nessuno aveva mai scritto.
        $this->seed(ExerciseLibrarySeeder::class);

        $dopo = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->count();

        $this->assertSame($prima, $dopo, 'il seeder ha duplicato');

        /*
         * ⚠️ **Gli stessi `id`, non solo lo stesso numero.** Un seeder che
         * cancellasse e ricreasse arriverebbe allo stesso conteggio con id
         * nuovi — e ogni `plan_exercises.exercise_id` gia' scritto punterebbe
         * al vuoto. Il conteggio da solo non se ne accorgerebbe.
         */
        $idDopo = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->pluck('id')->sort()->values();

        $this->assertEquals($idPrima->all(), $idDopo->all(), 'il seeder ha ricreato le righe');
    }

    #[Test]
    public function no_two_exercises_normalise_to_the_same_name(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $nomi = Exercise::withoutGlobalScopes()->whereNull('tenant_id')->pluck('name');
        $normalizzati = $nomi->map(fn (string $n): string => Exercise::normalize($n));

        /*
         * 🚨 La chiave del seeder e' `slug_normalized`, non `name`. Due nomi
         * diversi che normalizzano uguale — «Curl bicipiti» e «curl-bicipiti» —
         * si sovrascriverebbero a vicenda: il catalogo perderebbe una riga a
         * ogni seeding, e il conteggio direbbe che va tutto bene.
         */
        $this->assertSame(
            $normalizzati->unique()->count(),
            $normalizzati->count(),
            'due esercizi collidono dopo la normalizzazione: '
                .$normalizzati->duplicates()->implode(', '),
        );
    }

    #[Test]
    public function the_seeder_never_touches_a_gym_own_exercises(): void
    {
        $palestra = $this->creaPalestra();

        $suo = null;

        $this->ctx()->runAs($palestra, function () use ($palestra, &$suo): void {
            $suo = Exercise::create([
                'tenant_id' => $palestra->id,
                // ⚠️ Di proposito lo stesso nome di uno del catalogo base: e'
                // il caso che rompe se il seeder cercasse per nome invece che
                // per la coppia (tenant_id, slug_normalized).
                'name' => 'Panca piana',
                'slug_normalized' => Exercise::normalize('Panca piana'),
                'muscle_group' => MuscleGroup::Chest,
                'equipment' => 'la mia',
                'is_custom' => true,
            ]);
        });

        $this->seed(ExerciseLibrarySeeder::class);

        $suo->refresh();

        // 🚨 L'esercizio della palestra e' rimasto suo, col suo attrezzo.
        $this->assertSame($palestra->id, $suo->tenant_id);
        $this->assertSame('la mia', $suo->equipment);
        $this->assertTrue((bool) $suo->is_custom);

        // E quello globale con lo stesso nome esiste comunque, separato.
        $this->assertTrue(
            Exercise::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('slug_normalized', Exercise::normalize('Panca piana'))
                ->exists(),
        );
    }

    #[Test]
    public function an_unknown_met_stays_null_instead_of_being_invented(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        /*
         * ⚠️ Il seeder dichiara: «dove non e' noto si lascia `null`, perche'
         * `WorkoutCalorieService` usa allora il generico 5.0 — meglio di un
         * numero inventato per riempire una colonna».
         *
         * 💡 Questo test tiene in piedi quella regola: il giorno che qualcuno
         * riempisse tutti i MET «per completezza», il calorie service
         * comincerebbe a fidarsi di numeri che nessuno ha misurato.
         */
        $senzaMet = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->whereNull('met')
            ->count();

        $this->assertGreaterThan(0, $senzaMet, 'nessun MET a null: sono stati inventati?');
    }
}
