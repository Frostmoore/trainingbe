<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MuscleGroup;
use App\Models\Exercise;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * La libreria esercizi di base della piattaforma — B2.4.
 *
 * 🚨 **Serve a rendere possibile l'onboarding di una palestra.**
 * Senza una base condivisa, il primo accesso di un cliente comincia con due ore
 * di data entry — e nessuno le fa: il trainer scrive i nomi nelle note e la
 * libreria non nasce mai. Con questa base, `ExerciseMatcher` (B7.3) ha anche
 * qualcosa su cui riconciliare i PDF fin dal primo import.
 *
 * I nomi sono quelli che si usano davvero in una palestra italiana, non le
 * traduzioni letterali dall'inglese: e' il vocabolario su cui il matcher deve
 * fare centro.
 *
 * `updateOrCreate` sulla forma canonica: rilanciare il seeder non duplica.
 */
class ExerciseLibrarySeeder extends Seeder
{
    /**
     * nome, gruppo, attrezzo, MET.
     *
     * I MET vengono dal Compendium of Physical Activities. Dove non e' noto si
     * lascia `null`: `WorkoutCalorieService` usa allora il valore generico 5.0,
     * che e' meglio di un numero inventato per riempire una colonna.
     *
     * @var list<array{0: string, 1: MuscleGroup, 2: ?string, 3: ?float}>
     */
    private const ESERCIZI = [
        // Petto
        ['Panca piana', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Panca inclinata', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Panca declinata', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Croci ai cavi', MuscleGroup::Chest, 'cavi', 3.5],
        ['Croci su panca', MuscleGroup::Chest, 'manubri', 3.5],
        ['Chest press', MuscleGroup::Chest, 'macchina', 4.0],
        ['Piegamenti', MuscleGroup::Chest, 'corpo libero', 3.8],
        ['Dips', MuscleGroup::Chest, 'corpo libero', 5.0],

        // Schiena
        ['Lat machine', MuscleGroup::Back, 'macchina', 4.5],
        ['Trazioni', MuscleGroup::Back, 'corpo libero', 8.0],
        ['Rematore con bilanciere', MuscleGroup::Back, 'bilanciere', 5.0],
        ['Rematore con manubrio', MuscleGroup::Back, 'manubri', 5.0],
        ['Pulley basso', MuscleGroup::Back, 'cavi', 4.5],
        ['Stacco da terra', MuscleGroup::Back, 'bilanciere', 6.0],
        ['Stacco rumeno', MuscleGroup::Back, 'bilanciere', 5.5],
        ['Iperestensioni', MuscleGroup::Back, 'corpo libero', 3.5],

        // Spalle
        ['Lento avanti', MuscleGroup::Shoulders, 'bilanciere', 5.0],
        ['Shoulder press', MuscleGroup::Shoulders, 'manubri', 4.5],
        ['Alzate laterali', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Alzate frontali', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Alzate posteriori', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Tirate al mento', MuscleGroup::Shoulders, 'bilanciere', 4.0],

        // Braccia
        ['Curl bicipiti', MuscleGroup::Biceps, 'manubri', 3.5],
        ['Curl con bilanciere', MuscleGroup::Biceps, 'bilanciere', 3.5],
        ['Curl a martello', MuscleGroup::Biceps, 'manubri', 3.5],
        ['Curl alla panca Scott', MuscleGroup::Biceps, 'macchina', 3.5],
        ['Push down tricipiti', MuscleGroup::Triceps, 'cavi', 3.5],
        ['French press', MuscleGroup::Triceps, 'bilanciere', 3.5],
        ['Estensioni sopra la testa', MuscleGroup::Triceps, 'manubri', 3.5],
        ['Panca stretta', MuscleGroup::Triceps, 'bilanciere', 5.0],

        // Gambe
        ['Squat', MuscleGroup::Quads, 'bilanciere', 6.0],
        ['Squat frontale', MuscleGroup::Quads, 'bilanciere', 6.0],
        ['Pressa', MuscleGroup::Quads, 'macchina', 5.0],
        ['Affondi', MuscleGroup::Quads, 'manubri', 5.0],
        ['Leg extension', MuscleGroup::Quads, 'macchina', 4.0],
        ['Leg curl', MuscleGroup::Hamstrings, 'macchina', 4.0],
        ['Hip thrust', MuscleGroup::Glutes, 'bilanciere', 5.0],
        ['Abduzioni', MuscleGroup::Glutes, 'macchina', 3.5],
        ['Calf in piedi', MuscleGroup::Calves, 'macchina', 3.5],
        ['Calf da seduto', MuscleGroup::Calves, 'macchina', 3.5],

        // Core
        ['Crunch', MuscleGroup::Abs, 'corpo libero', 3.0],
        ['Plank', MuscleGroup::Abs, 'corpo libero', 3.0],
        ['Russian twist', MuscleGroup::Abs, 'corpo libero', 3.5],
        ['Sollevamento gambe', MuscleGroup::Abs, 'corpo libero', 3.5],

        // Cardio
        ['Tapis roulant', MuscleGroup::Cardio, 'macchina', 7.0],
        ['Cyclette', MuscleGroup::Cardio, 'macchina', 6.8],
        ['Ellittica', MuscleGroup::Cardio, 'macchina', 5.0],
        ['Vogatore', MuscleGroup::Cardio, 'macchina', 7.0],
        ['Corda', MuscleGroup::Cardio, 'corpo libero', 11.0],
        ['Burpees', MuscleGroup::FullBody, 'corpo libero', 8.0],
    ];

    public function run(): void
    {
        // 🚨 Fuori da ogni contesto: questi esercizi sono della **piattaforma**
        // (`tenant_id = null`). Girando dentro un contesto, il trait
        // `BelongsToTenantOrGlobal` li assegnerebbe a quella palestra e tutte le
        // altre non li vedrebbero.
        app(TenantContext::class)->runWithoutTenant(function (): void {
            foreach (self::ESERCIZI as [$nome, $gruppo, $attrezzo, $met]) {
                Exercise::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => null,
                        'slug_normalized' => Exercise::normalize($nome),
                    ],
                    [
                        'name' => $nome,
                        'muscle_group' => $gruppo,
                        'equipment' => $attrezzo,
                        'met' => $met,
                        'is_custom' => false,
                    ],
                );
            }
        });

        $this->command?->info('Libreria esercizi: '.count(self::ESERCIZI).' esercizi della piattaforma.');
    }
}
