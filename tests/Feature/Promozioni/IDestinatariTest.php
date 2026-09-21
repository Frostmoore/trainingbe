<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiCondizione;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Filament\God\Resources\Promozioni\Pages\CreatePromozione;
use App\Filament\God\Resources\Promozioni\PromozioneResource;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozioneCondizione;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Promozioni\ChiLaRiceve;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎯 **I destinatari: esclusioni e condizioni** — 24/09/2026, P5 di
 * `plan_promozioni.md` (opzioni 20-26).
 *
 * I bersagli si sommano (OR, provato in `LePromozioniTest`); le esclusioni
 * tolgono e vincono; le condizioni restringono in AND. Una condizione per test,
 * più le combinazioni.
 */
final class IDestinatariTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /**
     * @param  list<array<string, mixed>>  $bersagli
     * @param  list<array<string, mixed>>  $condizioni
     */
    private function promo(array $bersagli = [['tipo' => TipoBersaglio::Tutti]], array $condizioni = []): Promozione
    {
        $promozione = Promozione::query()->create([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Mirata',
            'genere' => GenereDiPromozione::Gettoni,
            'attivazione' => ComeSiAttiva::Massa,
        ]);

        PromozionePremio::query()->create(['promozione_id' => $promozione->getKey(), 'tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 10]);

        foreach ($bersagli as $b) {
            PromozioneBersaglio::query()->create(['promozione_id' => $promozione->getKey(), ...$b]);
        }

        foreach ($condizioni as $c) {
            PromozioneCondizione::query()->create(['promozione_id' => $promozione->getKey(), ...$c]);
        }

        return $promozione->fresh(['premi', 'bersagli', 'condizioni']);
    }

    private function prende(Promozione $promozione, User $utente): bool
    {
        return app(ChiLaRiceve::class)->prende($promozione, $utente->fresh());
    }

    private function libero(string $email): User
    {
        return app(CreaTenantPersonale::class)('Libero', $email, ['password' => self::FAKE_PASSWORD])->fresh();
    }

    // ═════════════════════════ esclusioni ═════════════════════════

    /** 🚫 **Solo esclusioni = tutti tranne loro.** Opzione 26. */
    #[Test]
    public function solo_esclusioni_vuol_dire_tutti_tranne(): void
    {
        $palestra = $this->creaPalestra();
        $dentro = $this->creaUtente($palestra, UserRole::Member, 'dentro@esempio.test');
        $fuori = $this->libero('fuori@esempio.test');

        $promozione = $this->promo([['tipo' => TipoBersaglio::Palestra, 'tenant_id' => $palestra->getKey(), 'escludi' => true]]);

        $this->assertFalse($this->prende($promozione, $dentro));
        $this->assertTrue($this->prende($promozione, $fuori));
    }

    /** ⛔ **Un'esclusione vince su un'inclusione**: «tutti i trainer, tranne Tizio». */
    #[Test]
    public function l_esclusione_vince_sull_inclusione(): void
    {
        $palestra = $this->creaPalestra();
        $tizio = $this->creaUtente($palestra, UserRole::Trainer, 'tizio@esempio.test');
        $caio = $this->creaUtente($palestra, UserRole::Trainer, 'caio@esempio.test');

        $promozione = $this->promo([
            ['tipo' => TipoBersaglio::Ruolo, 'ruolo' => UserRole::Trainer->value],
            ['tipo' => TipoBersaglio::Utente, 'user_id' => $tizio->getKey(), 'escludi' => true],
        ]);

        $this->assertFalse($this->prende($promozione, $tizio));
        $this->assertTrue($this->prende($promozione, $caio));
    }

    // ═════════════════════════ condizioni ═════════════════════════

    /** 🔎 **Le condizioni sono in AND** fra loro e coi bersagli. */
    #[Test]
    public function le_condizioni_restringono_in_and(): void
    {
        $palestra = $this->creaPalestra();
        $nuovo = $this->creaUtente($palestra, UserRole::Trainer, 'nuovo@esempio.test');
        $vecchio = $this->creaUtente($palestra, UserRole::Trainer, 'vecchio@esempio.test');
        $vecchio->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        $iscrittoNuovo = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');

        $promozione = $this->promo(
            [['tipo' => TipoBersaglio::Ruolo, 'ruolo' => UserRole::Trainer->value]],
            [['tipo' => TipoDiCondizione::RegistratoDaMenoDi, 'parametri' => ['giorni' => 30]]],
        );

        $this->assertTrue($this->prende($promozione, $nuovo));
        $this->assertFalse($this->prende($promozione, $vecchio));
        $this->assertFalse($this->prende($promozione, $iscrittoNuovo));
    }

    /** 🔎 Abbonati e non abbonati. Opzione 20. */
    #[Test]
    public function abbonati_e_non_abbonati(): void
    {
        $palestra = $this->creaPalestra();
        $coperto = $this->creaUtente($palestra, UserRole::Member, 'coperto@esempio.test');
        $libero = $this->libero('libero@esempio.test');

        $abbonati = $this->promo(condizioni: [['tipo' => TipoDiCondizione::Abbonamento, 'parametri' => ['stato' => 'abbonato']]]);
        $nonAbbonati = $this->promo(condizioni: [['tipo' => TipoDiCondizione::Abbonamento, 'parametri' => ['stato' => 'non_abbonato']]]);

        $this->assertTrue($this->prende($abbonati, $coperto));
        $this->assertFalse($this->prende($abbonati, $libero));
        $this->assertTrue($this->prende($nonAbbonati, $libero));
        $this->assertFalse($this->prende($nonAbbonati, $coperto));
    }

    /** 🔎 Gli ex abbonati: hanno pagato, adesso no. */
    #[Test]
    public function gli_ex_abbonati(): void
    {
        $ex = $this->libero('ex@esempio.test');
        $mai = $this->libero('mai@esempio.test');

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $ex->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->value('id'),
            'stripe_subscription_id' => 'sub_vecchio',
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subMonth(),
        ]);

        $promozione = $this->promo(condizioni: [['tipo' => TipoDiCondizione::Abbonamento, 'parametri' => ['stato' => 'ex_abbonato']]]);

        $this->assertTrue($this->prende($promozione, $ex));
        $this->assertFalse($this->prende($promozione, $mai));
    }

    /** 🔎 Chi ha un pagamento fallito. */
    #[Test]
    public function chi_ha_un_pagamento_fallito(): void
    {
        $moroso = $this->libero('moroso@esempio.test');
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $moroso->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->value('id'),
            'stripe_subscription_id' => 'sub_moroso',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(5),
        ]);
        PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', 'sub_moroso')->update(['pagamento_fallito_il' => now()]);

        $promozione = $this->promo(condizioni: [['tipo' => TipoDiCondizione::Abbonamento, 'parametri' => ['stato' => 'pagamento_fallito']]]);

        $this->assertTrue($this->prende($promozione, $moroso));
        $this->assertFalse($this->prende($promozione, $this->libero('puntuale@esempio.test')));
    }

    /** 🔎 Iscritti dopo una data, e clienti da più di N mesi. Opzione 22. */
    #[Test]
    public function anzianita_in_due_modi(): void
    {
        $recente = $this->libero('recente@esempio.test');
        $fedele = $this->libero('fedele@esempio.test');
        $fedele->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $fedele->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->value('id'),
            'stripe_subscription_id' => 'sub_fedele',
            'starts_at' => now()->subMonths(14),
            'ends_at' => now()->addMonth(),
        ]);

        $dopo = $this->promo(condizioni: [['tipo' => TipoDiCondizione::RegistratoDopo, 'parametri' => ['data' => now()->subMonth()->toDateString()]]]);
        $fedeli = $this->promo(condizioni: [['tipo' => TipoDiCondizione::ClienteDaPiuDi, 'parametri' => ['mesi' => 12]]]);

        $this->assertTrue($this->prende($dopo, $recente));
        $this->assertFalse($this->prende($dopo, $fedele));
        $this->assertTrue($this->prende($fedeli, $fedele));
        $this->assertFalse($this->prende($fedeli, $recente));
    }

    /** 🔎 Per piano attuale. Opzione 23. */
    #[Test]
    public function per_piano_attuale(): void
    {
        $libero = $this->libero('libero@esempio.test');
        $plus = $this->libero('plus@esempio.test');
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $plus->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->value('id'),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $promozione = $this->promo(condizioni: [['tipo' => TipoDiCondizione::PianoAttuale, 'parametri' => ['plan_ids' => [(string) Plan::query()->where('code', Plan::PLUS)->value('id')]]]]);

        $this->assertTrue($this->prende($promozione, $plus));
        $this->assertFalse($this->prende($promozione, $libero));
    }

    /** 🔎 Per comportamento: inattivi, mai comprato, gettoni finiti, comprati almeno N. Opzione 24. */
    #[Test]
    public function per_comportamento(): void
    {
        $dormiente = $this->libero('dormiente@esempio.test');
        $dormiente->forceFill(['last_login_at' => now()->subDays(60)])->saveQuietly();
        $attivo = $this->libero('attivo@esempio.test');
        $attivo->forceFill(['last_login_at' => now()->subDay()])->saveQuietly();
        app(PortafoglioGettoni::class)->accredita($attivo, 600);

        $inattivi = $this->promo(condizioni: [['tipo' => TipoDiCondizione::InattivoDa, 'parametri' => ['giorni' => 30]]]);
        $mai = $this->promo(condizioni: [['tipo' => TipoDiCondizione::MaiCompratoGettoni]]);
        $finiti = $this->promo(condizioni: [['tipo' => TipoDiCondizione::GettoniFiniti]]);
        $grandi = $this->promo(condizioni: [['tipo' => TipoDiCondizione::GettoniComprati, 'parametri' => ['gettoni' => 500]]]);

        $this->assertTrue($this->prende($inattivi, $dormiente));
        $this->assertFalse($this->prende($inattivi, $attivo));
        $this->assertTrue($this->prende($mai, $dormiente));
        $this->assertFalse($this->prende($mai, $attivo));
        $this->assertTrue($this->prende($finiti, $dormiente));
        $this->assertFalse($this->prende($finiti, $attivo));
        $this->assertTrue($this->prende($grandi, $attivo));
        $this->assertFalse($this->prende($grandi, $dormiente));
    }

    // ═════════════════════════ il pannello ═════════════════════════

    /** 🧙 Il modulo salva esclusioni e condizioni coi loro parametri. */
    #[Test]
    public function il_modulo_salva_esclusioni_e_condizioni(): void
    {
        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(CreatePromozione::class)
            ->fillForm([
                'genere' => GenereDiPromozione::Gettoni->value,
                'premi' => [['tipo' => TipoDiPremio::ScontoGettoni->value, 'tipo_sconto' => TipoDiSconto::Percentuale->value, 'valore' => 10]],
                'bersagli' => [
                    ['tipo' => TipoBersaglio::Tutti->value, 'escludi' => false],
                    ['tipo' => TipoBersaglio::Ruolo->value, 'ruolo' => UserRole::Trainer->value, 'escludi' => true],
                ],
                'condizioni' => [['tipo' => TipoDiCondizione::RegistratoDaMenoDi->value, 'parametri' => ['giorni' => 15]]],
                'attivazione' => ComeSiAttiva::Automatica->value,
                'nome' => 'Nuovi, non trainer',
                'codice' => 'NUOVI15',
                'stato' => 'attiva',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promozione = Promozione::query()->with(['bersagli', 'condizioni'])->where('codice', 'NUOVI15')->sole();

        $this->assertTrue($promozione->bersagli->firstWhere('tipo', TipoBersaglio::Ruolo)->escludi);
        $this->assertSame(15, (int) $promozione->condizioni->sole()->parametro('giorni'));
    }

    /** 🔎 L'anteprima conta chi la prende oggi. */
    #[Test]
    public function l_anteprima_conta_chi_la_prende(): void
    {
        $this->libero('uno@esempio.test');
        $this->libero('due@esempio.test');

        $html = PromozioneResource::anteprima($this->promo())->toHtml();

        $this->assertStringContainsString('<strong>2</strong> persone', $html);
        $this->assertStringContainsString('uno@esempio.test', $html);
    }
}
