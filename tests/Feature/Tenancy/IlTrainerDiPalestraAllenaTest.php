<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\DaiUnaCasaAlTrainer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏋️ **Il trainer creato dal pannello allena davvero** — giro A6, 22/09/2026.
 *
 * ══ 🐛 IL DIFETTO ═════════════════════════════════════════════════════════
 *
 * Dal 18/09 (decisione 2, `DaiUnaCasaAlTrainer`) ogni trainer ha uno spazio
 * suo, anche quello creato dal pannello di una palestra. Ma le richieste
 * dell'app girano nello spazio **di casa** (`ResolveTenant`), e lì lui è
 * `free_user` sul piano gratuito:
 *
 * - `isTrainer()` rispondeva **no** → `/trainer/*` in 403;
 * - `eAbbonato()` guardava il suo spazio vuoto → l'app mostrava «Abbonamento
 *   da trainer scaduto» a chi lavora e non ha mai avuto un abbonamento;
 * - `haUnoSpazioSuo()` rispondeva **sì** → allievi propri **senza limite**,
 *   gratis (il piano gratuito ha `max_members` nullo).
 *
 * ⚠️ I test di prima non potevano vederlo: `creaUtente(…, Trainer, …)` crea il
 * trainer **dentro** la palestra, cioè nel mondo di prima del 18/09. Qui il
 * trainer nasce come lo crea il pannello (`CreateTrainer::afterCreate`).
 */
final class IlTrainerDiPalestraAllenaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Come `CreateTrainer::afterCreate()`: nasce in palestra e riceve una casa. */
    private function trainerDalPannello(Tenant $palestra): User
    {
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        app(DaiUnaCasaAlTrainer::class)($trainer->fresh());

        return User::withoutGlobalScopes()->findOrFail($trainer->getKey());
    }

    #[Test]
    public function vede_gli_iscritti_della_palestra_dall_app(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');
        $trainer = $this->trainerDalPannello($palestra);

        $this->assertNotSame($palestra->getKey(), $trainer->tenant_id, 'Il trainer doveva avere una casa sua.');

        $risposta = $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/trainer/members')
            ->assertOk();

        $this->assertContains($iscritto->getKey(), collect($risposta->json('data'))->pluck('id')->all());
        $this->assertFalse($risposta->json('meta.allievi_propri'), 'Senza piano da trainer non ha posti suoi.');
    }

    /** 💡 Il muro dell'app si decide da qui: la palestra paga il lavoro, non il Plus. */
    #[Test]
    public function il_profilo_dice_che_puo_allenare_anche_se_non_e_abbonato(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->trainerDalPannello($palestra);

        $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.puo_allenare', true)
            ->assertJsonPath('data.abbonato', false);
    }

    /** ⚠️ Una palestra sospesa non fa allenare nessuno. */
    #[Test]
    public function con_la_palestra_sospesa_non_allena(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->trainerDalPannello($palestra);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/trainer/members')
            ->assertForbidden();

        $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.puo_allenare', false);
    }

    /** 🚨 Il buco commerciale: allievi propri illimitati sul piano gratuito. */
    #[Test]
    public function senza_piano_da_trainer_non_invita_allievi_suoi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->trainerDalPannello($palestra);

        $this->comeApp($trainer, ['trainer'])
            ->postJson('/api/v1/trainer/invites')
            ->assertUnprocessable();

        $this->assertDatabaseMissing('trainer_invites', ['trainer_id' => $trainer->getKey()]);
    }
}
