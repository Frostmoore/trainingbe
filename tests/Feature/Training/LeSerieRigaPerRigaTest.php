<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\PlanExercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Le serie riga per riga, sul server — 3b-D.10, 25/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«ovviamente queste modifiche devono riguardare anche l'editor del trainer e
 * quello del server, mi pare ovvio. A che cazzo serve fare delle modifiche se
 * poi non sono ovunque»*.
 *
 * ⛔ Aveva ragione: l'app era stata insegnata a scrivere «12 a 40, 10 a 45, 8 a
 * 50» e il server sapeva ancora dire soltanto «3 x 12 a 40».
 *
 * ══ 🚨 COSA DIFENDE QUESTO FILE ═══════════════════════════════════════════
 *
 * 1. che le righe si salvino e tornino indietro **uguali**;
 * 2. che il riassunto vecchio lo **ricavi il server**, cosi' i due formati non
 *    possano contraddirsi;
 * 3. che una scheda scritta **prima** che la colonna esistesse torni comunque
 *    in righe — e' il *«le schede gia' esistenti ricalchino questa nuova
 *    impostazione»* visto da qui;
 * 4. che chi le serie non le manda **scriva come prima**.
 */
final class LeSerieRigaPerRigaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ExerciseLibrarySeeder::class);

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
    }

    /**
     * ⚠️ E' **la** cosa che il modello vecchio non sapeva dire.
     */
    #[Test]
    public function tre_serie_con_tre_pesi_diversi_si_salvano_e_tornano(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Gambe',
                'exercises' => [[
                    'name' => 'Squat',
                    'muscle_group' => 'quads',
                    'serie' => [
                        ['reps' => 12, 'weight' => 40, 'rest_sec' => 90],
                        ['reps' => 10, 'weight' => 45, 'rest_sec' => 90],
                        ['reps' => 8, 'weight' => 50, 'rest_sec' => 120],
                    ],
                ]],
            ])
            ->assertCreated();

        $righe = $risposta->json('data.exercises.0.serie');

        $this->assertCount(3, $righe);
        $this->assertSame([40.0, 45.0, 50.0], array_column($righe, 'weight'));
        $this->assertSame(120, $righe[2]['rest_sec'], 'il recupero e\' della riga');
    }

    /**
     * 🚨 **Il riassunto lo ricava il server**, non chi manda: cosi' i due
     * formati non possono contraddirsi.
     *
     * ⚠️ E il riassunto **perde le differenze fra le serie** — 12, 10 e 8
     * diventano «12-8». E' il prezzo dichiarato della compatibilita', e va
     * scritto perche' chi legge il formato vecchio sappia cosa non sta
     * vedendo.
     */
    #[Test]
    public function e_il_riassunto_vecchio_lo_calcola_il_server(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Gambe',
                'exercises' => [[
                    'name' => 'Squat',
                    'muscle_group' => 'quads',

                    /*
                     * ⛔ Numeri vecchi **sbagliati apposta**: se il server li
                     * prendesse per buoni invece di ricavarli, si vedrebbe.
                     *
                     * ⚠️ Sbagliati ma **legali**: `sets: 99` sforava `max:30` e
                     * il test moriva di 422 prima di arrivare al punto. Un test
                     * che fallisce per la ragione sbagliata non dimostra
                     * niente.
                     */
                    'sets' => 7,
                    'reps' => 'settecento',
                    'target_weight' => 999,

                    'serie' => [
                        ['reps' => 12, 'weight' => 40, 'rest_sec' => 90],
                        ['reps' => 8, 'weight' => 50, 'rest_sec' => 120],
                    ],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.exercises.0.sets', 2)
            ->assertJsonPath('data.exercises.0.reps', '12-8')
            ->assertJsonPath('data.exercises.0.target_weight', 40.0)
            ->assertJsonPath('data.exercises.0.rest_sec', 90)
            ->assertJsonPath('data.exercises.0.prescription', '2 × 12-8');
    }

    /**
     * 🚨 **Il caso che rende vera la frase del committente.**
     *
     * Questa riga e' scritta come si scriveva prima che la colonna esistesse:
     * `serie` e' `null` in tabella. ⛔ Se tornasse `null` anche nell'API, l'app
     * dovrebbe avere un ramo «se e' vecchia» — e quel ramo e' il posto in cui i
     * difetti si nascondono.
     */
    #[Test]
    public function una_scheda_scritta_prima_torna_comunque_in_righe(): void
    {
        $piano = $this->pianoVecchio();

        $this->comeApp($this->iscritto)
            ->getJson("/api/v1/workout-plans/{$piano->getKey()}")
            ->assertOk()
            ->assertJsonCount(4, 'data.exercises.0.serie')
            ->assertJsonPath('data.exercises.0.serie.0.reps', 12)
            ->assertJsonPath('data.exercises.0.serie.3.weight', 40.0)
            ->assertJsonPath('data.exercises.0.serie.3.rest_sec', 90);
    }

    /** ⚠️ Il numero **piu' basso** di un intervallo: «8-12» diventa 8. */
    #[Test]
    public function e_di_un_intervallo_si_prende_il_numero_piu_basso(): void
    {
        $riga = new PlanExercise(['sets' => 3, 'reps' => '8-12']);

        $this->assertSame(8, $riga->serieRighe()[0]['reps']);
        $this->assertCount(3, $riga->serieRighe());
    }

    /**
     * ⛔ **Mai un elenco vuoto**: un esercizio senza serie non e' mostrabile, e
     * una lista vuota diventerebbe una card muta in mezzo alla scheda.
     */
    #[Test]
    public function e_chi_non_ha_proprio_niente_ha_comunque_una_riga(): void
    {
        $this->assertCount(1, (new PlanExercise)->serieRighe());
    }

    /**
     * ⚠️ **Chi non le manda scrive come prima.** L'app gia' installata manda
     * `sets` + `reps` e basta: pretendere le righe le spegnerebbe il
     * salvataggio — cioe' romperebbe l'app di chi non ha fatto niente di male.
     */
    #[Test]
    public function chi_manda_i_numeri_vecchi_salva_come_sempre(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Alla vecchia',
                'exercises' => [[
                    'name' => 'Squat',
                    'muscle_group' => 'quads',
                    'sets' => 4,
                    'reps' => '15',
                    'target_weight' => 30,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.exercises.0.sets', 4)
            ->assertJsonCount(4, 'data.exercises.0.serie')
            ->assertJsonPath('data.exercises.0.serie.0.reps', 15);
    }

    /**
     * 💡 L'isometria: i secondi al posto dei chili.
     *
     * ⚠️ `carico` **non e' deducibile dalle righe** — «peso coi campi vuoti» e
     * «a corpo libero» hanno le stesse righe — ed e' per questo che e' una
     * colonna sua.
     */
    #[Test]
    public function l_isometria_viaggia_nei_secondi_e_il_carico_si_conserva(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Core',
                'exercises' => [[
                    'name' => 'Plank',
                    'muscle_group' => 'abs',
                    'carico' => 'iso',
                    'serie' => [['iso_sec' => 45, 'rest_sec' => 60]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.exercises.0.carico', 'iso')
            ->assertJsonPath('data.exercises.0.serie.0.iso_sec', 45)
            ->assertJsonPath('data.exercises.0.target_weight', null);
    }

    /**
     * 🚨 **Anche dentro i giorni**, e non e' scontato: le regole di validazione
     * erano scritte due volte — una per la lista piatta e una per i giorni — e
     * nella prima stesura le serie valevano solo per una delle due strade.
     */
    #[Test]
    public function e_valgono_anche_per_gli_esercizi_dentro_un_giorno(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Split',
                'days' => [[
                    'name' => 'Giorno 1',
                    'exercises' => [[
                        'name' => 'Squat',
                        'muscle_group' => 'quads',
                        'serie' => [
                            ['reps' => 12, 'weight' => 40],
                            ['reps' => 10, 'weight' => 45],
                        ],
                    ]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.days.0.exercises.0.serie')
            ->assertJsonPath('data.days.0.exercises.0.serie.1.weight', 45.0);
    }

    /** ⛔ Un tetto c'e', e non e' pignoleria: e' una colonna JSON. */
    #[Test]
    public function e_non_si_possono_mandare_mille_serie(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Troppe',
                'exercises' => [[
                    'name' => 'Squat',
                    'muscle_group' => 'quads',
                    'serie' => array_fill(0, 31, ['reps' => 10]),
                ]],
            ])
            ->assertStatus(422);
    }

    /** Una scheda come si scriveva prima del 25/08: `serie` nulla in tabella. */
    private function pianoVecchio(): WorkoutPlan
    {
        $piano = WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->iscritto->getKey(),
            'created_by' => $this->iscritto->getKey(),
            'name' => 'Scritta prima',
            'status' => PlanStatus::Published,
            'source' => PlanSource::Manual,
            'published_at' => now(),
        ]);

        $piano->exercises()->create([
            'workout_plan_day_id' => $piano->giornoPredefinito()->getKey(),
            'exercise_id' => Exercise::query()->where('name', 'Squat')->value('id'),
            'position' => 0,

            // 🚨 Il formato vecchio, e `serie` non si scrive proprio: e' il
            // caso di ogni riga esistente il giorno della migrazione.
            'sets' => 4,
            'reps' => '12',
            'target_weight' => 40,
            'rest_sec' => 90,
        ]);

        return $piano->fresh(['exercises.exercise']);
    }
}
