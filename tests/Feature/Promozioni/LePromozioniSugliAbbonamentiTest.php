<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\DurataDelloSconto;
use App\Enums\GenereDiPromozione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\AddebitoDelMese;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 💳 **Le promozioni sugli abbonamenti** — 24/09/2026, P3 di `plan_promozioni.md`.
 *
 * Opzioni 8 (sconto con durata), 9 (prova gratuita), 12 (prezzo bloccato) e 14
 * (sconto sul consumo). Stripe è finto: si guarda **cosa gli diciamo**, che è
 * l'unica cosa che decidiamo noi.
 */
final class LePromozioniSugliAbbonamentiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PacchettiGettoniSeeder::class);
        $this->stripe = $this->stripeFinto();
    }

    /** @param  array<string, mixed>  $premio */
    private function promo(array $premio, array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Abbonati',
            'genere' => GenereDiPromozione::Abbonamenti,
            'attivazione' => ComeSiAttiva::Automatica,
            'massimo_per_persona' => 1,
        ], $extra));

        PromozionePremio::query()->create(['promozione_id' => $promozione->getKey(), ...$premio]);
        PromozioneBersaglio::query()->create(['promozione_id' => $promozione->getKey(), 'tipo' => TipoBersaglio::Tutti]);

        return $promozione->fresh(['premi', 'bersagli']);
    }

    private function persona(): User
    {
        return $this->creaUtente($this->creaPalestra(), UserRole::Member, 'tizio@esempio.test');
    }

    /** @return array<string, mixed> la sessione aperta per il Plus */
    private function apriIlPlus(User $utente): array
    {
        app(Cassa::class)->apri($utente, Cassa::ABBONAMENTO);

        return $this->stripe->ultima('checkout.sessions.create')[0];
    }

    // ═════════════════════════ la durata ═════════════════════════

    /** ⏳ **Per N rinnovi** diventa un coupon `repeating` di N mesi, su un piano mensile. */
    #[Test]
    public function lo_sconto_per_n_rinnovi_diventa_repeating(): void
    {
        $this->promo(['tipo' => TipoDiPremio::ScontoAbbonamento, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 30, 'durata' => DurataDelloSconto::NVolte, 'volte' => 3]);

        $this->apriIlPlus($this->persona());
        $coupon = $this->stripe->ultima('coupons.create')[0];

        $this->assertSame('repeating', $coupon['duration']);
        $this->assertSame(3, $coupon['duration_in_months']);
    }

    /** ⏳ E **per sempre** diventa `forever`. */
    #[Test]
    public function lo_sconto_per_sempre_diventa_forever(): void
    {
        $this->promo(['tipo' => TipoDiPremio::ScontoAbbonamento, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 10, 'durata' => DurataDelloSconto::PerSempre]);

        $this->apriIlPlus($this->persona());

        $this->assertSame('forever', $this->stripe->ultima('coupons.create')[0]['duration']);
    }

    /** 🚨 Su un piano **annuale** N rinnovi sono N×12 mesi: Stripe conta in mesi. */
    #[Test]
    public function su_un_piano_annuale_i_rinnovi_diventano_anni(): void
    {
        Plan::query()->where('code', Plan::PLUS)->update(['intervallo' => 'anno']);
        $this->promo(['tipo' => TipoDiPremio::ScontoAbbonamento, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 30, 'durata' => DurataDelloSconto::NVolte, 'volte' => 2]);

        $this->apriIlPlus($this->persona());

        $this->assertSame(24, $this->stripe->ultima('coupons.create')[0]['duration_in_months']);
    }

    /** 💡 Il default resta il primo pagamento. */
    #[Test]
    public function solo_il_primo_pagamento_resta_once(): void
    {
        $this->promo(['tipo' => TipoDiPremio::ScontoAbbonamento, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 30, 'durata' => DurataDelloSconto::UnaVolta]);

        $this->apriIlPlus($this->persona());
        $coupon = $this->stripe->ultima('coupons.create')[0];

        $this->assertSame('once', $coupon['duration']);
        $this->assertArrayNotHasKey('duration_in_months', $coupon);
    }

    // ═════════════════════════ la prova ═════════════════════════

    /**
     * 🧪 **La prova gratuita**: Stripe la riceve, e il webhook accende fino alla
     * fine della prova — non per un mese intero. Opzione 9.
     */
    #[Test]
    public function la_prova_gratuita_arriva_a_stripe_e_accende_fino_alla_fine_della_prova(): void
    {
        $utente = $this->persona();
        $promozione = $this->promo(['tipo' => TipoDiPremio::ProvaGratuita, 'giorni' => 14]);

        $sessione = $this->apriIlPlus($utente);

        $this->assertSame(14, $sessione['subscription_data']['trial_period_days']);
        $this->assertSame('14', $sessione['metadata']['prova_giorni']);
        $this->assertSame($promozione->codice, $sessione['metadata']['promozioni']);
        $this->assertArrayNotHasKey('discounts', $sessione);

        app(Cassa::class)->applica([
            'id' => 'evt_prova',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_prova',
                'mode' => 'subscription',
                'subscription' => 'sub_prova',
                'customer' => 'cus_prova',
                'amount_total' => 0,
                'metadata' => $sessione['metadata'],
            ]],
        ]);

        $riga = PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', 'sub_prova')->sole();
        $this->assertTrue($riga->ends_at->between(now()->addDays(14), now()->addDays(16)));

        // 💡 La prova è un uso: col massimo per persona a 1, non ne arriva una seconda.
        $this->assertSame(1, $promozione->fresh()->usata_volte);
        $this->apriIlPlus($utente);
        $this->assertArrayNotHasKey('trial_period_days', $this->stripe->ultima('checkout.sessions.create')[0]['subscription_data']);
    }

    /** 🎯 Una prova su un piano preciso non vale sugli altri. */
    #[Test]
    public function la_prova_su_un_piano_non_vale_sugli_altri(): void
    {
        $palestra = $this->creaPalestra();
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->delete();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test');

        $this->promo(['tipo' => TipoDiPremio::ProvaGratuita, 'giorni' => 30, 'plan_id' => Plan::query()->where('code', 'gym_30')->value('id')]);

        app(PianiInVendita::class)->apri($titolare, 'gym_10');
        $this->assertArrayNotHasKey('trial_period_days', $this->stripe->ultima('checkout.sessions.create')[0]['subscription_data']);

        app(PianiInVendita::class)->apri($titolare, 'gym_30');
        $this->assertSame(30, $this->stripe->ultima('checkout.sessions.create')[0]['subscription_data']['trial_period_days']);
    }

    // ═════════════════════════ il prezzo bloccato ═════════════════════════

    /** 🔒 Il prezzo bloccato resta scritto sull'abbonamento acceso. Opzione 12. */
    #[Test]
    public function il_prezzo_bloccato_resta_scritto_sull_abbonamento(): void
    {
        $utente = $this->persona();
        $this->promo(['tipo' => TipoDiPremio::PrezzoBloccato]);

        $sessione = $this->apriIlPlus($utente);
        $this->assertSame('1', $sessione['metadata']['prezzo_bloccato']);

        app(Cassa::class)->applica([
            'id' => 'evt_blocco',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_blocco',
                'mode' => 'subscription',
                'subscription' => 'sub_blocco',
                'customer' => 'cus_blocco',
                'amount_total' => 799,
                'metadata' => $sessione['metadata'],
            ]],
        ]);

        $this->assertTrue(PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', 'sub_blocco')->sole()->prezzo_bloccato);
    }

    // ═════════════════════════ il consumo ═════════════════════════

    private function palestraAConsumo(): array
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');
        $palestra->forceFill(['proprietario_id' => $titolare->getKey()])->save();

        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->delete();
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_consumo')->value('id'),
            'starts_at' => now()->subYear(),
        ]);

        return [Tenant::query()->withoutGlobalScopes()->find($palestra->getKey()), $titolare];
    }

    /**
     * 💶 **Lo sconto sul consumo toglie davvero**, e lo dice. Opzione 14.
     *
     * ⛔ F2 lo offriva e `AddebitoDelMese` non lo leggeva: si salvava e basta.
     */
    #[Test]
    public function lo_sconto_sul_consumo_si_applica_al_mese(): void
    {
        [$palestra] = $this->palestraAConsumo();
        $senza = app(AddebitoDelMese::class)->calcola($palestra, '2026-07');

        $promozione = $this->promo(['tipo' => TipoDiPremio::ScontoConsumo, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 50]);
        $addebito = app(AddebitoDelMese::class)->calcola($palestra, '2026-08');

        $this->assertSame(intdiv($senza->importo_cent, 2) + $senza->importo_cent % 2, $addebito->importo_cent);
        $this->assertSame($senza->importo_cent - $addebito->importo_cent, $addebito->sconto_cent);
        $this->assertSame($promozione->codice, $addebito->promozioni);

        // 🧾 E l'uso è segnato una volta sola, anche se il mese si ricalcola.
        app(AddebitoDelMese::class)->calcola($palestra, '2026-08');
        $this->assertSame(1, PromozioneAssegnazione::query()->where('riferimento', "consumo:2026-08:{$palestra->getKey()}")->count());
    }

    /** 💡 Col massimo per persona a 1 lo sconto vale un mese solo; il mese dopo si paga intero. */
    #[Test]
    public function il_massimo_per_persona_dice_per_quanti_mesi(): void
    {
        [$palestra] = $this->palestraAConsumo();
        $this->promo(['tipo' => TipoDiPremio::ScontoConsumo, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 50]);

        $agosto = app(AddebitoDelMese::class)->calcola($palestra, '2026-08');
        $settembre = app(AddebitoDelMese::class)->calcola($palestra, '2026-09');

        $this->assertGreaterThan(0, $agosto->sconto_cent);
        $this->assertSame(0, $settembre->sconto_cent);
    }
}
