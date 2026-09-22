<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Console\Commands\PotaIDocumentiDelleSchede;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\PlanImports\Pages\ListPlanImports;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanImport;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🗑️ **I documenti delle schede importate dal pannello scadono** — A8 di
 * `plan_blocco_A.md`, 22/09/2026.
 *
 * ⛔ Fino al 22/09 il PDF o la foto caricati da una palestra restavano per
 * sempre, e nel pannello non c'era modo di toglierli (DPIA R19). Se la scheda era
 * intestata a un allievo, il suo nome restava sul server senza scadenza.
 */
final class IDocumentiDelleSchedeScadonoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');
        $this->gestore = $this->creaUtente($this->alfa, UserRole::GymAdmin, 'capo@alfa.test');

        filament()->setCurrentPanel('admin');
    }

    /**
     * 🚨 Dopo 30 giorni il **file** sparisce, dal database e dal disco. ⚠️ La
     * riga dell'import resta (è la storia), e la scheda creata non si tocca.
     */
    #[Test]
    public function dopo_trenta_giorni_il_documento_se_ne_va(): void
    {
        $vecchio = $this->importCon(giorniFa: PotaIDocumentiDelleSchede::GIORNI + 1, conScheda: true);
        $percorso = $vecchio->documento()->getPath();
        $this->assertFileExists($percorso);

        $this->artisan('schede:pota-documenti')->assertSuccessful();

        $vecchio->refresh();
        $this->assertNull($vecchio->documento(), 'Il documento è ancora nel database.');
        $this->assertFileDoesNotExist($percorso);
        $this->assertNotNull(WorkoutPlanImport::withoutGlobalScopes()->find($vecchio->getKey()), 'È sparito l\'import intero.');
        $this->assertNotNull(WorkoutPlan::withoutGlobalScopes()->find($vecchio->workout_plan_id), 'È sparita la scheda.');
    }

    /** Prima dei 30 giorni resta: serve per rivederla col documento accanto. */
    #[Test]
    public function prima_dei_trenta_giorni_resta(): void
    {
        $recente = $this->importCon(giorniFa: PotaIDocumentiDelleSchede::GIORNI - 1);

        $this->artisan('schede:pota-documenti')->assertSuccessful();

        $this->assertNotNull($recente->refresh()->documento());
    }

    #[Test]
    public function la_prova_non_cancella_niente(): void
    {
        $vecchio = $this->importCon(giorniFa: PotaIDocumentiDelleSchede::GIORNI + 1);

        $this->artisan('schede:pota-documenti', ['--dry-run' => true])
            ->expectsOutputToContain('[prova] Documenti cancellati: 1')
            ->assertSuccessful();

        $this->assertNotNull($vecchio->refresh()->documento());
    }

    /**
     * 🆕 **«Elimina» nell'elenco degli import**: via la riga e il documento,
     * ⚠️ la scheda creata resta — è della palestra, e può essere già stata mandata.
     */
    #[Test]
    public function la_palestra_elimina_un_import_e_la_scheda_resta(): void
    {
        $import = $this->importCon(giorniFa: 1, conScheda: true);
        $percorso = $import->documento()->getPath();

        $this->entra($this->gestore);

        Livewire::test(ListPlanImports::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($import));

        $this->assertNull(WorkoutPlanImport::withoutGlobalScopes()->find($import->getKey()));
        $this->assertFileDoesNotExist($percorso);
        $this->assertNotNull(WorkoutPlan::withoutGlobalScopes()->find($import->workout_plan_id));
    }

    private function importCon(int $giorniFa, bool $conScheda = false): WorkoutPlanImport
    {
        return app(TenantContext::class)->runAs($this->alfa, function () use ($giorniFa, $conScheda): WorkoutPlanImport {
            $scheda = $conScheda
                ? WorkoutPlan::create(['name' => 'Scheda importata', 'created_by' => $this->gestore->getKey()])
                : null;

            $import = WorkoutPlanImport::create([
                'uploaded_by' => $this->gestore->getKey(),
                'member_id' => null,
                'workout_plan_id' => $scheda?->getKey(),
            ]);

            $tmp = tempnam(sys_get_temp_dir(), 'imp');
            file_put_contents($tmp, '%PDF-1.4 finto');
            $import->addMedia($tmp)->usingFileName('scheda.pdf')->toMediaCollection(WorkoutPlanImport::COLLECTION);

            $import->forceFill(['created_at' => now()->subDays($giorniFa)])->saveQuietly();

            return $import->refresh();
        });
    }

    private function entra(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(TenantContext::class)->set($utente->tenant);
    }
}
