<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\DailyBurn;
use App\Models\NutritionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Le calorie bruciate arrivano davvero al target? — N23, 19/08/2026.
 *
 * ── 🚨 Il difetto riferito, e misurato ─────────────────────────────────────
 *
 * *«se aggiungo quante calorie ho bruciato nella scheda cibo, queste non
 * appaiono da nessuna parte, né nell'header della scheda oggi, né nel target
 * calorico odierno»* — il committente.
 *
 * **Era vero, e le cause erano due:**
 *
 * | | |
 * |---|---|
 * | Il target del giorno | Dopo **D9-bis** il server non conosce il peso: `targets` torna `null`, e la somma `kcal_base + bruciate` non arriva a nessuno schermo |
 * | Svuotare il campo | `kcal` era `required`: mandare `null` dava **422**, e la funzione promessa nel modulo non esisteva |
 *
 * 💡 La somma vera vive ora nell'app (`TargetDelGiorno`, N23.B1). Questi test
 * fissano il **contratto del server** su cui quella somma si appoggia.
 */
class BruciateNelTargetTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@olimpo.it');

        // Il profilo completo quanto il server può averlo dopo D9-bis: altezza,
        // nascita, sesso, attività e obiettivo. ⚠️ Il peso no — quello vive solo
        // sul telefono, ed è il motivo per cui `computedTargets()` non calcola.
        $this->iscritto->profile()->updateOrCreate([], [
            'tenant_id' => $this->palestra->id,
            'height_cm' => 180,
            'birthdate' => '1990-01-01',
            'sex' => 'm',
            'activity_level' => 'sedentary',
            'goal' => 'maintain',
        ]);
    }

    private function dichiara(int $kcal): void
    {
        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/daily-burn', ['kcal' => $kcal])
            ->assertStatus(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function diario(): array
    {
        return $this->actingAs($this->iscritto)
            ->getJson('/api/v1/diary')
            ->assertOk()
            ->json('data');
    }

    #[Test]
    public function le_bruciate_dichiarate_tornano_nel_diario(): void
    {
        $this->dichiara(450);

        $this->assertSame(450, $this->diario()['burned']['kcal']);
    }

    #[Test]
    public function le_bruciate_dichiarate_tornano_nel_riepilogo(): void
    {
        $this->dichiara(450);

        $riepilogo = $this->actingAs($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(450, $riepilogo['nutrition']['burned']['kcal']);
    }

    /**
     * 🚨 **Il server NON restituisce nessun target, e va bene così.**
     *
     * Dopo **D9-bis** non conosce il peso: senza peso non c'è BMR, senza BMR non
     * c'è TDEE. ⚠️ Questo test fissa il fatto, perché è la **causa** del difetto:
     * la somma esiste in `targetsFor()` ma non arriva a nessuno schermo.
     *
     * 💡 Se un giorno diventasse rosso vorrebbe dire che qualcuno ha rimesso i
     * dati del corpo sul server — e allora va rivista **anche** la somma lato
     * app, o si conterebbe due volte.
     */
    #[Test]
    public function senza_peso_il_server_non_calcola_nessun_target(): void
    {
        $this->dichiara(450);

        $this->assertNull($this->diario()['targets']);
    }

    /**
     * ⚠️ Con un piano del trainer, invece, la somma c'è: è l'unico ramo in cui il
     * target del server arriva davvero allo schermo — ed è il motivo per cui
     * l'app **non deve risommare** quando il numero viene da qui.
     */
    #[Test]
    public function con_un_piano_del_trainer_il_target_somma_le_bruciate(): void
    {
        app(TenantContext::class)->runAs($this->palestra, function (): void {
            $piano = NutritionPlan::create([
                'member_id' => $this->iscritto->id,
                'name' => 'Piano',
                'target_kcal' => 2000,
                'status' => PlanStatus::Published,
            ]);

            $piano->forceFill(['published_at' => now()])->save();
        });

        $this->dichiara(450);

        $diario = $this->diario();

        $this->assertNotNull($diario['targets']);
        $this->assertSame(2000, $diario['targets']['kcal_base']);
        $this->assertSame(2450, $diario['targets']['kcal']);
    }

    /**
     * ⚠️ Svuotare il campo deve **rimettere la stima**, non azzerare.
     *
     * 🚨 Prima del 19/08 questa chiamata rispondeva **422**: il modulo dell'app
     * prometteva *«Vuoto = usa la stima degli allenamenti»* e il server la
     * rifiutava.
     */
    #[Test]
    public function svuotare_il_campo_rimette_la_stima(): void
    {
        $this->dichiara(450);

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/daily-burn', ['kcal' => null])
            ->assertStatus(201);

        $this->assertSame(0, $this->diario()['burned']['kcal']);
        $this->assertNull(DailyBurn::query()->where('user_id', $this->iscritto->id)->first());
    }

    /**
     * 🚨 **`0` e `null` sono due cose diverse.**
     *
     * `0` è una dichiarazione — «oggi non ho bruciato niente» — e resta.
     * `null` è «non lo so», e rimette la stima. ⚠️ Confonderle azzererebbe il
     * margine di chi voleva solo tornare al calcolo automatico.
     */
    #[Test]
    public function zero_non_e_lo_stesso_di_vuoto(): void
    {
        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/daily-burn', ['kcal' => 0])
            ->assertStatus(201);

        $this->assertNotNull(
            DailyBurn::query()->where('user_id', $this->iscritto->id)->first(),
            'Uno zero dichiarato è una riga, non l\'assenza di una riga.',
        );
    }

    /** ⚠️ Ometterlo resta un errore: è un client sbagliato, non «non lo so». */
    #[Test]
    public function omettere_il_campo_resta_un_errore(): void
    {
        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/daily-burn', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kcal');
    }
}
