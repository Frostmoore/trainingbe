<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
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
 * Il timbro di una scheda: quando e' cambiata davvero — 3b-B.16.5, 24/08/2026,
 * potato in 3b-B.17.7 il 25/08.
 *
 * ══ 🚨 PERCHE' SOPRAVVIVE A B.16 ══════════════════════════════════════════
 *
 * ⛔ Questo file era nato per la **sincronizzazione delle schede col telefono**,
 * cancellata poche ore dopo (B.17): le schede dell'iscritto vivono sul telefono
 * e non si sincronizza piu' niente. 🚨 Ci si aspetterebbe che se ne andasse
 * insieme a lei, e invece **serve ancora**, per un motivo che con l'app non
 * c'entra:
 *
 * 💡 **La colonna «Modificato» del pannello.** `WorkoutPlansTable` mostra
 * `updated_at` e ci ordina sopra. Senza i `$touches` su `PlanExercise` e
 * `WorkoutPlanDay`, `workout_plans.updated_at` cambierebbe **solo** quando
 * cambia la riga della scheda: un trainer che aggiunge un esercizio non la
 * toccherebbe affatto, e il pannello direbbe che quella scheda non si tocca da
 * marzo mentre l'ha modificata cinque minuti fa.
 *
 * ⚠️ **Una cosa nata per un motivo puo' guadagnarsene un altro**, e il momento
 * di accorgersene e' quando si cancella il primo. ⛔ Togliere i `$touches`
 * «perche' erano di B.16» avrebbe rotto il pannello in silenzio, senza un solo
 * test rosso — perche' i test erano questi.
 *
 * ── ⛔ Cosa se n'e' andato davvero ────────────────────────────────────────
 *
 * Il protocollo di conflitto (`base_updated_at` → 409) di B.16.6: era
 * facoltativo e **nessun client lo mandava**. Vedi `WorkoutPlanController@update`.
 */
final class QuandoLaSchedaECambiataTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
    }

    #[Test]
    public function la_scheda_dice_quando_e_cambiata(): void
    {
        $piano = $this->pianoDi();

        $this->comeApp($this->iscritto)
            ->getJson("/api/v1/workout-plans/{$piano->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $piano->getKey())
            ->assertJsonStructure(['data' => ['updated_at']]);
    }

    /**
     * ══ 🚨 IL TEST CHE VALE PIÙ DI TUTTI QUI ═══════════════════════════════
     *
     * ⛔ `workout_plans.updated_at` di suo cambia **solo** quando cambia la riga
     * della scheda. Un trainer che dal pannello aggiunge un esercizio non la
     * toccherebbe affatto, e il telefono terrebbe la versione vecchia
     * **credendola aggiornata** — senza nessun errore da nessuna parte.
     *
     * 💡 A questo servono i `$touches` su `PlanExercise` e `WorkoutPlanDay`.
     */
    #[Test]
    public function e_cambia_anche_quando_cambia_solo_una_riga(): void
    {
        $piano = $this->pianoDi();

        $prima = $piano->fresh()->updated_at;

        // Un secondo di scarto, o i due istanti sono identici e il test non
        // proverebbe niente.
        $this->travel(2)->seconds();

        $riga = $piano->exercises()->first();
        $riga->update(['sets' => 5]);

        $this->assertTrue(
            $piano->fresh()->updated_at->greaterThan($prima),
            'Cambiare una riga non ha toccato la scheda: il telefono non se ne accorgerebbe.',
        );
    }

    /** ⚠️ E vale anche quando una riga **sparisce**, che e' il caso che fa danno. */
    #[Test]
    public function e_anche_quando_una_riga_sparisce(): void
    {
        $piano = $this->pianoDi();

        $prima = $piano->fresh()->updated_at;
        $this->travel(2)->seconds();

        $piano->exercises()->first()->delete();

        $this->assertTrue($piano->fresh()->updated_at->greaterThan($prima));
    }


    /**
     * ⛔ **Chi manda ancora `base_updated_at` non prende un errore.**
     *
     * 🚨 Il campo se n'e' andato con il protocollo di conflitto (3b-B.17.7), e
     * questo test fissa **come** se n'e' andato: la scrittura passa e il campo
     * viene ignorato, non rifiutato.
     *
     * ⚠️ E' la differenza fra togliere una regola e aggiungerne una al
     * contrario: se una validazione lo rifiutasse, un client rimasto indietro
     * si vedrebbe **spegnere il salvataggio** da una modifica che doveva solo
     * semplificare il server.
     */
    #[Test]
    public function chi_manda_ancora_la_versione_di_base_scrive_lo_stesso(): void
    {
        $piano = $this->pianoDi();

        $vecchia = $piano->updated_at->toIso8601String();

        $this->travel(2)->seconds();
        $piano->update(['name' => 'Cambiata da un\'altra parte']);

        $this->comeApp($this->iscritto)
            ->putJson("/api/v1/workout-plans/{$piano->getKey()}", [
                'name' => 'Quella che avevo io',
                'base_updated_at' => $vecchia,
                'exercises' => [['name' => 'Piegamenti']],
            ])
            ->assertOk();

        $this->assertSame('Quella che avevo io', $piano->fresh()->name);
    }


    private function pianoDi(): WorkoutPlan
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $piano = WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->iscritto->getKey(),
            'created_by' => $this->iscritto->getKey(),
            'name' => 'Giorno 1',
            'status' => PlanStatus::Published,
            'source' => PlanSource::Manual,
            'published_at' => now(),
        ]);

        $giorno = $piano->giornoPredefinito();

        $piano->exercises()->create([
            'workout_plan_day_id' => $giorno->getKey(),
            'exercise_id' => Exercise::query()->where('name', 'Piegamenti')->value('id'),
            'position' => 0,
            'sets' => 4,
            'reps' => '15',
        ]);

        return $piano->fresh();
    }
}
