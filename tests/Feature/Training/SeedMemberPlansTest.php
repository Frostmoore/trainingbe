<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * `training:seed-plans` — le schede di prova.
 *
 * 🚨 **Non e' un comando cosmetico**: e' quello che rende provabile il player, e
 * un comando di preparazione che sbaglia in silenzio produce un ambiente che
 * *sembra* pronto. Le tre cose che devono valere sono qui sotto: che le schede
 * arrivino all'iscritto giusto, che una sola sia sua (decisione D1) e che
 * rilanciarlo non duplichi niente.
 */
class SeedMemberPlansTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ExerciseLibrarySeeder::class);

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    /** @return Collection<int, WorkoutPlan> */
    private function schede(): Collection
    {
        return app(TenantContext::class)->runAs(
            $this->alfa,
            fn () => WorkoutPlan::query()->forMember($this->iscritto)->with('exercises')->get(),
        );
    }

    #[Test]
    public function crea_tre_schede_pubblicate_per_l_iscritto(): void
    {
        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])
            ->assertSuccessful();

        $schede = $this->schede();

        $this->assertCount(3, $schede);

        foreach ($schede as $scheda) {
            $this->assertSame(
                PlanStatus::Published,
                $scheda->status,
                'Una scheda non pubblicata non compare nell\'app: il comando non servirebbe a niente.',
            );
            $this->assertNotNull($scheda->published_at);
            $this->assertGreaterThanOrEqual(6, $scheda->exercises->count());
        }
    }

    /**
     * 🚨 Il cuore del comando.
     *
     * Se fossero tutte dello stesso autore, il pulsante «Modifica» risulterebbe
     * sempre presente o sempre assente, e la regola D1 — il discriminante e'
     * `created_by`, non il ruolo — non sarebbe provabile a mano.
     */
    #[Test]
    public function una_sola_scheda_e_dell_iscritto_le_altre_del_trainer(): void
    {
        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])
            ->assertSuccessful();

        $schede = $this->schede()->keyBy('name');

        $this->assertSame(
            $this->iscritto->getKey(),
            $schede['Gambe e core']->created_by,
            'Questa deve risultare modificabile nell\'app.',
        );

        $this->assertSame($this->trainer->getKey(), $schede['Push — petto, spalle, tricipiti']->created_by);
        $this->assertSame($this->trainer->getKey(), $schede['Pull — schiena e bicipiti']->created_by);
    }

    /**
     * ⚠️ Un comando di preparazione che a ogni esecuzione lascia un ambiente
     * diverso e' un comando che non si osa rieseguire.
     */
    #[Test]
    public function rilanciarlo_non_duplica_ne_schede_ne_righe(): void
    {
        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])->assertSuccessful();

        $primaSchede = $this->schede();
        $primaRighe = $primaSchede->sum(fn (WorkoutPlan $p): int => $p->exercises->count());

        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])->assertSuccessful();

        $dopoSchede = $this->schede();

        $this->assertCount($primaSchede->count(), $dopoSchede);
        $this->assertSame(
            $primaRighe,
            $dopoSchede->sum(fn (WorkoutPlan $p): int => $p->exercises->count()),
        );
    }

    #[Test]
    public function con_dry_non_scrive_niente(): void
    {
        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test', '--dry' => true])
            ->assertSuccessful();

        $this->assertCount(0, $this->schede());
    }

    #[Test]
    public function su_un_email_inesistente_fallisce_invece_di_far_finta(): void
    {
        $this->artisan('training:seed-plans', ['email' => 'nessuno@alfa.test'])
            ->assertFailed();
    }

    /**
     * 🚨 Meglio fermarsi che creare schede monche.
     *
     * Una scheda a cui mancano due esercizi su sei sembra funzionante ed e'
     * un'altra cosa da quella che il comando dice di aver creato.
     */
    #[Test]
    public function senza_libreria_esercizi_si_ferma_e_dice_come_rimediare(): void
    {
        Exercise::query()->withoutGlobalScopes()->forceDelete();

        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])
            ->expectsOutputToContain('ExerciseLibrarySeeder')
            ->assertFailed();

        $this->assertCount(0, $this->schede());
    }

    /**
     * Le schede appartengono alla palestra dell'iscritto, non a nessun'altra:
     * e' il vincolo che regge tutto il resto.
     */
    #[Test]
    public function le_schede_restano_dentro_la_palestra_dell_iscritto(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $estraneo = $this->creaUtente($beta, UserRole::Member, 'luca@beta.test');

        $this->artisan('training:seed-plans', ['email' => 'mario@alfa.test'])->assertSuccessful();

        foreach ($this->schede() as $scheda) {
            $this->assertSame($this->alfa->getKey(), $scheda->tenant_id);
        }

        $delBeta = app(TenantContext::class)->runAs(
            $beta,
            fn () => WorkoutPlan::query()->forMember($estraneo)->count(),
        );

        $this->assertSame(0, $delBeta);
    }
}
