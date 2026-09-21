<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\EventoDiPromozione;
use App\Enums\GenereDiPromozione;
use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Models\InvitoInPalestra;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * ⚡ **Le promozioni a evento, «porta un amico», gettoni a ogni rinnovo, sconti
 * sbloccati** — 24/09/2026, P6 di `plan_promozioni.md` (opzioni 15, 16, 18, 27, 32).
 */
final class GliEventiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PacchettiGettoniSeeder::class);
        $this->stripeFinto();
    }

    /**
     * @param  list<array<string, mixed>>  $premi
     * @param  array<string, mixed>  $extra
     */
    private function promo(array $premi, array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Evento',
            'genere' => GenereDiPromozione::Entrambi,
            'attivazione' => ComeSiAttiva::Evento,
            'massimo_per_persona' => null,
        ], $extra));

        foreach ($premi as $premio) {
            PromozionePremio::query()->create(['promozione_id' => $promozione->getKey(), ...$premio]);
        }

        PromozioneBersaglio::query()->create(['promozione_id' => $promozione->getKey(), 'tipo' => TipoBersaglio::Tutti]);

        return $promozione->fresh(['premi', 'bersagli']);
    }

    private function libero(string $email = 'tizio@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)('Tizio', $email, ['password' => self::FAKE_PASSWORD])->fresh();
    }

    /** Il primo pagamento del Plus, come lo manda Stripe. */
    private function paga(User $utente, string $sessione = 'cs_plus', string $tipo = Cassa::ABBONAMENTO): void
    {
        app(Cassa::class)->applica([
            'id' => 'evt_'.$sessione,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessione,
                'mode' => $tipo === Cassa::ABBONAMENTO ? 'subscription' : 'payment',
                'subscription' => $tipo === Cassa::ABBONAMENTO ? 'sub_'.$utente->getKey() : null,
                'customer' => 'cus_'.$utente->getKey(),
                'amount_total' => 799,
                'metadata' => [
                    'tipo' => $tipo,
                    'user_id' => (string) $utente->getKey(),
                    'tenant_id' => (string) $utente->tenant_id,
                    'gettoni' => $tipo === Cassa::GETTONI ? '100' : '0',
                ],
            ]],
        ]);
    }

    private function rinnova(User $utente, string $fattura, string $motivo = 'subscription_cycle'): void
    {
        app(Cassa::class)->applica([
            'id' => 'evt_'.$fattura,
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => $fattura,
                'subscription' => 'sub_'.$utente->getKey(),
                'billing_reason' => $motivo,
                'amount_paid' => 799,
                'lines' => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
            ]],
        ]);
    }

    // ═════════════════════════ gli eventi ═════════════════════════

    /** ⚡ **Alla registrazione**: chi si iscrive riceve il regalo. */
    #[Test]
    public function alla_registrazione_arriva_il_regalo(): void
    {
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 25]], ['evento' => EventoDiPromozione::Registrazione]);

        $this->assertSame(25, $this->libero()->ai_credits);
    }

    /** ⚡ **Ingresso in palestra** a chi entra, **invito accettato** a chi aveva invitato. */
    #[Test]
    public function l_invito_della_palestra_premia_chi_entra_e_chi_invita(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');
        $nuovo = $this->libero('nuovo@esempio.test');

        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 30]], ['evento' => EventoDiPromozione::IngressoInPalestra]);
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 7]], ['evento' => EventoDiPromozione::InvitoAccettato]);

        $invito = InvitoInPalestra::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'invitato_da' => $capo->getKey(),
            'token' => Str::random(40),
            'expires_at' => now()->addWeek(),
        ]);

        $invito->forceFill(['used_at' => now(), 'accepted_by' => $nuovo->getKey()])->save();

        $this->assertSame(30, $nuovo->fresh()->ai_credits);
        $this->assertSame(7, $capo->fresh()->ai_credits);
    }

    /** ⚡ L'invito di un trainer premia il trainer. */
    #[Test]
    public function l_invito_del_trainer_premia_il_trainer(): void
    {
        $trainer = $this->libero('coach@esempio.test');
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 9]], ['evento' => EventoDiPromozione::InvitoAccettato]);

        $invito = TrainerInvite::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'trainer_id' => $trainer->getKey(),
            'token' => Str::random(40),
            'expires_at' => now()->addWeek(),
        ]);
        $invito->forceFill(['used_at' => now()])->save();

        $this->assertSame(9, $trainer->fresh()->ai_credits);
    }

    /** ⚡ **Abbonamento + gettoni** all'attivazione (opzione 15), e l'acquisto di gettoni. */
    #[Test]
    public function il_primo_abbonamento_e_l_acquisto_di_gettoni_scattano(): void
    {
        $tizio = $this->libero();
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 50]], ['evento' => EventoDiPromozione::PrimoAbbonamento]);
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 5]], ['evento' => EventoDiPromozione::AcquistoGettoni]);

        $this->paga($tizio);
        $this->assertSame(50, $tizio->fresh()->ai_credits);

        $this->paga($tizio, 'cs_gettoni', Cassa::GETTONI);
        $this->assertSame(50 + 100 + 5, $tizio->fresh()->ai_credits);
    }

    /**
     * 🔁 **Gettoni a ogni rinnovo** (opzione 16): al primo pagamento e a ogni
     * rinnovo vero; la fattura di creazione non conta due volte, e una fattura
     * ripetuta nemmeno.
     */
    #[Test]
    public function i_gettoni_a_ogni_rinnovo(): void
    {
        $tizio = $this->libero();
        $this->promo([['tipo' => TipoDiPremio::GettoniAOgniRinnovo, 'gettoni' => 10]], ['attivazione' => ComeSiAttiva::Automatica]);

        $this->paga($tizio);
        $this->assertSame(10, $tizio->fresh()->ai_credits);

        $this->rinnova($tizio, 'in_creazione', 'subscription_create');
        $this->assertSame(10, $tizio->fresh()->ai_credits);

        $this->rinnova($tizio, 'in_mese_2');
        $this->rinnova($tizio, 'in_mese_2');
        $this->assertSame(20, $tizio->fresh()->ai_credits);
    }

    /** ⚡ **L'N-esimo rinnovo**: solo quello. */
    #[Test]
    public function il_rinnovo_numero_n(): void
    {
        $tizio = $this->libero();
        $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 40]], ['evento' => EventoDiPromozione::Rinnovo, 'evento_parametri' => ['numero' => 2]]);

        $this->paga($tizio);
        $this->rinnova($tizio, 'in_1');
        $this->assertSame(0, (int) $tizio->fresh()->ai_credits);

        $this->rinnova($tizio, 'in_2');
        $this->assertSame(40, $tizio->fresh()->ai_credits);
    }

    // ═════════════════════════ lo sconto sbloccato ═════════════════════════

    /**
     * 🔓 **Chi compra l'abbonamento sblocca uno sconto sui gettoni** (opzione 18):
     * vale solo per chi l'ha guadagnato, al pagamento **senza codice**, e scade.
     */
    #[Test]
    public function lo_sconto_sbloccato_vale_solo_per_chi_l_ha_guadagnato_e_scade(): void
    {
        $tizio = $this->libero();
        $caio = $this->libero('caio@esempio.test');
        $this->promo(
            [['tipo' => TipoDiPremio::SbloccaSconto, 'sblocca_genere' => GenereDiPromozione::Gettoni, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 30, 'giorni' => 10]],
            ['evento' => EventoDiPromozione::PrimoAbbonamento, 'massimo_per_persona' => 1],
        );

        $servizio = app(LePromozioni::class);
        $this->assertCount(0, $servizio->automaticheDi($tizio, SuCosaVale::Gettoni));

        $this->paga($tizio);

        $mie = $servizio->automaticheDi($tizio->fresh(), SuCosaVale::Gettoni);
        $this->assertCount(1, $mie);
        $this->assertSame(300, $servizio->applica(1000, $mie, SuCosaVale::Gettoni)['sconto']);
        $this->assertCount(0, $servizio->automaticheDi($caio, SuCosaVale::Gettoni));

        $this->travel(11)->days();
        $this->assertCount(0, $servizio->automaticheDi($tizio->fresh(), SuCosaVale::Gettoni));
    }

    // ═════════════════════════ porta un amico ═════════════════════════

    /**
     * 🤝 **Porta un amico** (opzione 27): il codice, il collegamento, e i due
     * premi — a chi arriva e a chi l'ha portato.
     */
    #[Test]
    public function porta_un_amico_premia_tutti_e_due(): void
    {
        $vecchio = $this->libero('vecchio@esempio.test');
        $this->promo([
            ['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 20, 'a_chi' => 'destinatario'],
            ['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 50, 'a_chi' => 'chi_ha_invitato'],
        ], ['evento' => EventoDiPromozione::AmicoArrivato, 'evento_parametri' => ['quando' => 'registrazione']]);

        $codice = $this->comeApp($vecchio)->getJson('/api/v1/account/amico')->assertOk()->json('data.codice');
        $this->assertNotEmpty($codice);

        $nuovo = $this->libero('nuovo@esempio.test');
        $this->comeApp($nuovo)->postJson('/api/v1/account/amico', ['codice' => $codice])->assertCreated();

        $this->assertSame(20, $nuovo->fresh()->ai_credits);
        $this->assertSame(50, $vecchio->fresh()->ai_credits);
        $this->assertSame(1, $this->comeApp($vecchio)->getJson('/api/v1/account/amico')->json('data.amici'));
    }

    /** ⛔ Una volta sola, non a se stessi, e non a distanza di mesi. */
    #[Test]
    public function il_codice_amico_ha_le_sue_regole(): void
    {
        $vecchio = $this->libero('vecchio@esempio.test');
        $codice = $vecchio->codiceAmico();

        $this->comeApp($vecchio)->postJson('/api/v1/account/amico', ['codice' => $codice])->assertUnprocessable();

        $nuovo = $this->libero('nuovo@esempio.test');
        $this->comeApp($nuovo)->postJson('/api/v1/account/amico', ['codice' => $codice])->assertCreated();
        $this->comeApp($nuovo)->postJson('/api/v1/account/amico', ['codice' => $codice])->assertUnprocessable();

        $tardi = $this->libero('tardi@esempio.test');
        $tardi->forceFill(['created_at' => now()->subDays(8)])->saveQuietly();
        $this->comeApp($tardi)->postJson('/api/v1/account/amico', ['codice' => $codice])->assertUnprocessable();
    }

    /** 🤝 «Al primo acquisto»: niente all'iscrizione, i premi al primo pagamento. */
    #[Test]
    public function porta_un_amico_al_primo_acquisto(): void
    {
        $vecchio = $this->libero('vecchio@esempio.test');
        $this->promo([
            ['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 50, 'a_chi' => 'chi_ha_invitato'],
        ], ['evento' => EventoDiPromozione::AmicoArrivato, 'evento_parametri' => ['quando' => 'primo_acquisto']]);

        $nuovo = $this->libero('nuovo@esempio.test');
        $this->comeApp($nuovo)->postJson('/api/v1/account/amico', ['codice' => $vecchio->codiceAmico()])->assertCreated();
        $this->assertSame(0, (int) $vecchio->fresh()->ai_credits);

        $this->paga($nuovo->fresh());
        $this->paga($nuovo->fresh(), 'cs_secondo', Cassa::GETTONI);

        $this->assertSame(50, $vecchio->fresh()->ai_credits);
    }
}
