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
 * Quello che serve al telefono per sincronizzare le schede — 3b-B.16, 24/08/2026.
 *
 * ══ 🚨 COSA DIFENDE QUESTO FILE ═══════════════════════════════════════════
 *
 * 📌 *«le schede sul server si sincronizzano sul telefono quando apro l'app e
 * per le modifiche vince sempre la piu' recente»*.
 *
 * ⛔ Perche' quella regola funzioni servono due cose dal server, e **nessuna
 * delle due c'era**: sapere **quando** una scheda e' cambiata, e potersi
 * accorgere che sta cambiando **sotto le mani di qualcun altro**.
 *
 * 🚨 Il difetto che ha reso urgente tutto questo: il 24/08 un salvataggio a
 * fine allenamento ha cancellato due esercizi dalla scheda del committente, e
 * il server ha eseguito senza fiatare — non aveva modo di sapere che chi
 * scriveva stava lavorando su una versione vecchia.
 */
final class LaSchedaSiSincronizzaTest extends TestCase
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
     * ⛔ **Chi scrive su una versione vecchia non sovrascrive: prende un 409.**
     *
     * 🚨 E nella risposta c'e' la versione attuale, cosi' chi ha mandato la
     * scrittura puo' decidere cosa fare invece di riprovare alla cieca.
     */
    #[Test]
    public function scrivere_su_una_versione_vecchia_da_409(): void
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
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_conflict')
            ->assertJsonPath('data.name', 'Cambiata da un\'altra parte');

        $this->assertSame(
            'Cambiata da un\'altra parte',
            $piano->fresh()->name,
            'Il 409 ha comunque scritto: e\' il difetto che doveva impedire.',
        );
    }

    /** 💡 E con la versione giusta si scrive, come sempre. */
    #[Test]
    public function ma_con_la_versione_giusta_si_scrive(): void
    {
        $piano = $this->pianoDi();

        $this->comeApp($this->iscritto)
            ->putJson("/api/v1/workout-plans/{$piano->getKey()}", [
                'name' => 'Aggiornata',
                'base_updated_at' => $piano->updated_at->toIso8601String(),
                'exercises' => [['name' => 'Piegamenti']],
            ])
            ->assertOk();

        $this->assertSame('Aggiornata', $piano->fresh()->name);
    }

    /**
     * ⚠️ **Chi non lo manda si comporta come prima.**
     *
     * ⛔ L'app gia' installata non conosce `base_updated_at`: pretenderlo
     * spegnerebbe il salvataggio a tutti quelli che non hanno ancora
     * aggiornato — cioe' romperebbe l'app di chi non ha fatto niente di male.
     */
    #[Test]
    public function e_chi_non_lo_manda_scrive_come_prima(): void
    {
        $piano = $this->pianoDi();

        $this->comeApp($this->iscritto)
            ->putJson("/api/v1/workout-plans/{$piano->getKey()}", [
                'name' => 'Senza dichiarare niente',
                'exercises' => [['name' => 'Piegamenti']],
            ])
            ->assertOk();

        $this->assertSame('Senza dichiarare niente', $piano->fresh()->name);
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
