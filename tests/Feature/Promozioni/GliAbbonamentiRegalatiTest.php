<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\OrigineAssegnazione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiInPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎀 **Abbonamenti regalati, upgrade e posti extra** — 24/09/2026, P4 di
 * `plan_promozioni.md` (opzioni 10, 11, 13).
 *
 * 🚨 La regola che conta più di tutte: un abbonamento sta su uno **spazio**, e
 * regalarlo a chi non ne è il titolare vorrebbe dire regalarlo a una palestra
 * intera. Ci sono test apposta.
 */
final class GliAbbonamentiRegalatiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function piano(string $codice): Plan
    {
        return Plan::query()->where('code', $codice)->firstOrFail();
    }

    /** @param  array<string, mixed>  $premio */
    private function promo(array $premio): Promozione
    {
        $promozione = Promozione::query()->create([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Regalo',
            'genere' => GenereDiPromozione::Abbonamenti,
            'attivazione' => ComeSiAttiva::Massa,
            'massimo_per_persona' => 1,
        ]);

        PromozionePremio::query()->create(['promozione_id' => $promozione->getKey(), ...$premio]);
        PromozioneBersaglio::query()->create(['promozione_id' => $promozione->getKey(), 'tipo' => TipoBersaglio::Tutti]);

        return $promozione->fresh(['premi', 'bersagli']);
    }

    private function consegna(Promozione $promozione, User $utente): mixed
    {
        return app(ConsegnaDeiPremi::class)->consegna($promozione, $utente, OrigineAssegnazione::Massa, 'm:'.$utente->getKey());
    }

    private function persona(): User
    {
        return app(CreaTenantPersonale::class)('Tizio', 'tizio@esempio.test', ['password' => self::FAKE_PASSWORD])->fresh();
    }

    /** @return array{0: Tenant, 1: User} una palestra col suo titolare, su un piano pagato */
    private function palestraPagata(string $piano = 'gym_10'): array
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');
        $palestra->forceFill(['proprietario_id' => $titolare->getKey()])->save();

        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->delete();
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $this->piano($piano)->getKey(),
            'stripe_subscription_id' => 'sub_palestra',
            'stripe_customer_id' => 'cus_palestra',
            'rinnova' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
        ]);

        return [$palestra->fresh(), $titolare->fresh()];
    }

    // ═════════════════════════ l'abbonamento regalato ═════════════════════════

    /** 🎀 **N periodi gratis, e poi torna com'era.** Opzione 10. */
    #[Test]
    public function l_abbonamento_regalato_dura_n_periodi_e_poi_finisce(): void
    {
        $tizio = $this->persona();
        $gratuito = app(PianoAttivo::class)->per($tizio)->code;

        $this->assertNotNull($this->consegna($this->promo(['tipo' => TipoDiPremio::AbbonamentoRegalato, 'plan_id' => $this->piano(Plan::PLUS)->getKey(), 'volte' => 2]), $tizio));
        $this->assertSame(Plan::PLUS, app(PianoAttivo::class)->per($tizio->fresh())->code);

        $this->travel(2)->months();
        $this->travel(2)->days();

        $this->assertSame($gratuito, app(PianoAttivo::class)->per($tizio->fresh())->code);
    }

    /**
     * 🚨 **A un iscritto no**: il suo spazio è la palestra, e il regalo andrebbe
     * a tutta la palestra.
     */
    #[Test]
    public function a_chi_non_e_titolare_dello_spazio_non_si_regala(): void
    {
        [$palestra] = $this->palestraPagata();
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');
        $promozione = $this->promo(['tipo' => TipoDiPremio::AbbonamentoRegalato, 'plan_id' => $this->piano(Plan::PLUS)->getKey(), 'volte' => 1]);

        $this->assertNull($this->consegna($promozione, $iscritto));
        $this->assertStringContainsString('non è tuo', (string) app(ConsegnaDeiPremi::class)->percheNo($promozione, $iscritto));
        $this->assertSame(1, PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->count());
    }

    /** ⛔ Un piano da palestra non va su uno spazio personale, e viceversa. */
    #[Test]
    public function il_piano_deve_essere_del_tipo_dello_spazio(): void
    {
        $tizio = $this->persona();

        $this->assertNull($this->consegna($this->promo(['tipo' => TipoDiPremio::AbbonamentoRegalato, 'plan_id' => $this->piano('gym_30')->getKey(), 'volte' => 1]), $tizio));
    }

    /** ⛔ **Chi paga già non riceve un regalo sopra**: Stripe continuerebbe a incassare. */
    #[Test]
    public function chi_ha_un_abbonamento_pagato_non_riceve_il_regalo(): void
    {
        [, $titolare] = $this->palestraPagata();
        $promozione = $this->promo(['tipo' => TipoDiPremio::AbbonamentoRegalato, 'plan_id' => $this->piano('gym_30')->getKey(), 'volte' => 1]);

        $this->assertNull($this->consegna($promozione, $titolare));
        $this->assertStringContainsString('a pagamento', (string) app(ConsegnaDeiPremi::class)->percheNo($promozione, $titolare));
    }

    // ═════════════════════════ l'upgrade ═════════════════════════

    /**
     * ⬆️ **Paga il suo piano e ha quello superiore per N mesi.** Opzione 11.
     *
     * Poi, senza comandi, torna in cima l'abbonamento pagato.
     */
    #[Test]
    public function l_upgrade_vale_n_mesi_e_poi_torna_il_piano_pagato(): void
    {
        [, $titolare] = $this->palestraPagata('gym_10');

        $this->assertNotNull($this->consegna($this->promo(['tipo' => TipoDiPremio::Upgrade, 'plan_id' => $this->piano('gym_30')->getKey(), 'volte' => 2]), $titolare));
        $this->assertSame('gym_30', app(PianoAttivo::class)->per($titolare)->code);

        $this->travel(2)->months();
        $this->travel(2)->days();

        $this->assertSame('gym_10', app(PianoAttivo::class)->per($titolare->fresh())->code);
    }

    /** ⛔ L'upgrade è per chi paga, e dal piano giusto se la promozione lo dice. */
    #[Test]
    public function l_upgrade_pretende_un_piano_pagato_e_quello_giusto(): void
    {
        $tizio = $this->persona();
        $this->assertNull($this->consegna($this->promo(['tipo' => TipoDiPremio::Upgrade, 'plan_id' => $this->piano(Plan::PLUS)->getKey(), 'volte' => 1]), $tizio));

        [, $titolare] = $this->palestraPagata('gym_10');
        $promozione = $this->promo(['tipo' => TipoDiPremio::Upgrade, 'plan_id' => $this->piano('gym_30')->getKey(), 'da_plan_id' => $this->piano('gym_30')->getKey(), 'volte' => 1]);

        $this->assertSame('Questo upgrade è per chi ha un altro piano.', app(ConsegnaDeiPremi::class)->percheNo($promozione, $titolare));
    }

    // ═════════════════════════ i posti ═════════════════════════

    /** ➕ **Posti in più finché valgono**, poi tornano quelli del piano. Opzione 13. */
    #[Test]
    public function i_posti_extra_contano_finche_valgono(): void
    {
        [$palestra, $titolare] = $this->palestraPagata('gym_10');
        $prima = app(InvitiInPalestra::class)->iPosti($palestra)['totali'];

        $this->consegna($this->promo(['tipo' => TipoDiPremio::PostiExtra, 'posti' => 5, 'posti_di' => 'iscritti', 'volte' => 1]), $titolare);

        $this->assertSame($prima + 5, app(InvitiInPalestra::class)->iPosti($palestra->fresh())['totali']);

        $this->travel(1)->months();
        $this->travel(1)->days();

        $this->assertSame($prima, app(InvitiInPalestra::class)->iPosti($palestra->fresh())['totali']);
    }

    /** ➕ I posti trainer vanno ai trainer, non agli iscritti. */
    #[Test]
    public function i_posti_trainer_vanno_ai_trainer(): void
    {
        [$palestra, $titolare] = $this->palestraPagata('gym_10');
        $iscrittiPrima = app(InvitiInPalestra::class)->iPosti($palestra)['totali'];
        $trainerPrima = app(InvitiInPalestra::class)->iPosti($palestra, UserRole::Trainer)['totali'];

        $this->consegna($this->promo(['tipo' => TipoDiPremio::PostiExtra, 'posti' => 2, 'posti_di' => 'trainer', 'volte' => 3]), $titolare);

        $this->assertSame($iscrittiPrima, app(InvitiInPalestra::class)->iPosti($palestra->fresh())['totali']);
        $this->assertSame(
            $trainerPrima === null ? null : $trainerPrima + 2,
            app(InvitiInPalestra::class)->iPosti($palestra->fresh(), UserRole::Trainer)['totali'],
        );
    }
}
