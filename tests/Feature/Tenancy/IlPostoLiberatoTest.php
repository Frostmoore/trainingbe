<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Chat\CancelloDellaChat;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\LoSlotDelTrainer;
use App\Services\Tenancy\UnisciAUnaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎟️ **Il posto liberato non si riapre da una parte sola** — 22/09/2026.
 *
 * 📌 Il committente (Q-A5-2, dopo il giro A5): alla domanda «un trainer che ha
 * liberato il posto può riaprire da solo la conversazione?» — *«No, ovviamente.
 * Cioè la chat si deve bloccare (max 3 messaggi, come sempre) e se vuole
 * reinvitarlo gli deve mandare un link di invito»*.
 *
 * ⛔ Nel giro l'allievo liberato restava fra «i miei utenti» con «Riapri la
 * conversazione», e la chat era chiusa del tutto: nemmeno i tre messaggi per
 * mandargli l'invito.
 */
final class IlPostoLiberatoTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    private User $allievo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $this->trainer = $this->conPianoDaTrainer(app(CreaTenantPersonale::class)(
            'Coach', 'coach@esempio.test', ['password' => self::FAKE_PASSWORD], UserRole::FreeTrainer,
        ));

        app(TenantContext::class)->runAs($this->palestra, function (): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($this->trainer->getKey());
            $fresco->unsetRelation('roles');
            $fresco->assignRole(UserRole::Trainer->value);
        });

        $allievo = app(CreaTenantPersonale::class)(
            'Rita', 'rita@esempio.test', ['password' => self::FAKE_PASSWORD], UserRole::FreeUser,
        );

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $this->trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($this->trainer),
            )->token,
            $allievo,
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($allievo->fresh(), $this->palestra, UserRole::Member);

        // Il trainer libera il posto.
        $this->assertTrue(app(LoSlotDelTrainer::class)->decidi($this->trainer, $allievo, false));

        $this->allievo = User::withoutGlobalScopes()->findOrFail($allievo->getKey());
    }

    private function esceDallaPalestra(): void
    {
        app(EsciDaUnaPalestra::class)($this->allievo);
        $this->allievo = User::withoutGlobalScopes()->findOrFail($this->allievo->getKey());
    }

    private function filo(): Conversation
    {
        return Conversation::withoutGlobalScopes()
            ->where('trainer_id', $this->trainer->getKey())
            ->where('member_id', $this->allievo->getKey())
            ->firstOrFail();
    }

    #[Test]
    public function uscito_dalla_palestra_non_e_piu_fra_i_suoi(): void
    {
        $this->esceDallaPalestra();

        $this->assertNotSame($this->trainer->tenant_id, $this->allievo->tenant_id, 'Non doveva tornare dal trainer.');
        $this->assertNotContains(
            $this->allievo->getKey(),
            app(ChiSiVede::class)->allieviDi($this->trainer->fresh())->pluck('id')->all(),
        );
    }

    #[Test]
    public function il_trainer_non_riapre_il_rapporto_da_solo(): void
    {
        $this->comeApp($this->trainer->fresh(), ['trainer'])
            ->postJson('/api/v1/trainer/members/'.$this->allievo->getKey().'/toggle')
            ->assertStatus(409)
            ->assertJsonPath('code', 'posto_liberato');

        $this->assertTrue(app(LoSlotDelTrainer::class)->haLiberatoIlPosto(
            (int) $this->trainer->getKey(),
            (int) $this->allievo->getKey(),
        ));
    }

    /** 💡 Finché è iscritto dove il trainer allena, lo segue da trainer della palestra. */
    #[Test]
    public function in_palestra_si_scrivono_ancora(): void
    {
        $c = Conversation::between($this->trainer, $this->allievo);

        $this->assertTrue(app(CancelloDellaChat::class)->puoScrivere($this->allievo, $c)->consentito);
    }

    #[Test]
    public function fuori_dalla_palestra_tornano_i_tre_messaggi(): void
    {
        $c = Conversation::between($this->trainer, $this->allievo);
        $this->esceDallaPalestra();

        $permesso = app(CancelloDellaChat::class)->puoScrivere($this->allievo, $c->fresh());

        $this->assertTrue($permesso->consentito, 'La chat non doveva chiudersi del tutto.');
        $this->assertSame(3, $permesso->restanti);
        $this->assertSame(TipoConversazione::Informazioni, $this->filo()->tipo);
    }

    /** 📌 *«se vuole reinvitarlo gli deve mandare un link di invito»*. */
    #[Test]
    public function con_un_invito_accettato_si_riprende_tutto(): void
    {
        Conversation::between($this->trainer, $this->allievo);
        $this->esceDallaPalestra();
        app(CancelloDellaChat::class)->puoScrivere($this->allievo, $this->filo());

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $this->trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($this->trainer->fresh()),
            )->token,
            $this->allievo,
        );

        $this->assertFalse(app(LoSlotDelTrainer::class)->haLiberatoIlPosto(
            (int) $this->trainer->getKey(),
            (int) $this->allievo->getKey(),
        ));
        $this->assertSame(TipoConversazione::Iscritto, $this->filo()->tipo);
        $this->assertContains(
            $this->allievo->getKey(),
            app(ChiSiVede::class)->allieviDi($this->trainer->fresh())->pluck('id')->all(),
        );
    }
}
