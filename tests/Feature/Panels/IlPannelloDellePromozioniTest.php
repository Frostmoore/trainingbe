<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\GenereDiPromozione;
use App\Enums\StatoPromozione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Filament\God\Resources\Promozioni\Pages\ListPromozioni;
use App\Filament\Gym\Pages\OffertaPerTe;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎁 **Le promozioni nel pannello** — 22/09/2026, fase F2.
 *
 * ⛔ **Non prova il conto**: quello sta in `LePromozioniTest`, dove ci sono le
 * regole che costano soldi. Qui si difende che le pagine si aprano e che la
 * pagina dell'offerta **non dica di sì a chi non c'entra**.
 */
class IlPannelloDellePromozioniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function promo(array $extra = [], ?array $bersaglio = null): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Sconto di settembre',
            'descrizione' => 'Il venti per cento sul prossimo pagamento.',
            'genere' => GenereDiPromozione::Gettoni,
        ], $extra));

        // 🆕 P1 — lo sconto è un premio.
        PromozionePremio::query()->create([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoDiPremio::ScontoGettoni,
            'tipo_sconto' => TipoDiSconto::Percentuale,
            'valore' => 20,
        ]);

        PromozioneBersaglio::query()->create(array_merge([
            'promozione_id' => $promozione->getKey(),
            'tipo' => TipoBersaglio::Tutti,
        ], $bersaglio ?? []));

        return $promozione->fresh(['bersagli', 'premi']);
    }

    #[Test]
    public function l_elenco_delle_promozioni_si_apre(): void
    {
        $this->promo();

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListPromozioni::class)
            ->assertSuccessful()
            ->assertSee('Sconto di settembre')
            ->assertSee('20%');
    }

    /**
     * 🚨 **Una promozione scaduta non si mostra «attiva»**, anche se la colonna
     * `stato` dice ancora quello.
     *
     * ⛔ È la cosa più fuorviante che questa pagina potrebbe fare: un badge
     * verde su un'offerta che al pagamento non si applica.
     */
    #[Test]
    public function una_scaduta_si_vede_come_scaduta(): void
    {
        $this->promo(['scade_il' => now()->subDay(), 'stato' => StatoPromozione::Attiva]);

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListPromozioni::class)
            ->assertSuccessful()
            ->assertSee('Scaduta');
    }

    /** 🔗 Il link porta a una pagina che spiega l'offerta, nel pannello. */
    #[Test]
    public function la_pagina_dell_offerta_la_spiega(): void
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');

        $promozione = $this->promo(['attivazione' => 'link']);

        $this->actingAs($titolare);
        filament()->setCurrentPanel('admin');
        app(TenantContext::class)->set($palestra);

        Livewire::test(OffertaPerTe::class, ['codice' => $promozione->codice])
            ->assertSuccessful()
            ->assertSee('Sconto di settembre')
            ->assertSee('Il venti per cento sul prossimo pagamento.');
    }

    /**
     * 🚨 **Un codice inoltrato a chi non c'entra dice di no**, e dice perché.
     *
     * ⛔ Senza questo, una promozione «per gli allievi della palestra Tal dei
     * Tali» finirebbe su mezza piattaforma al primo inoltro.
     */
    #[Test]
    public function a_chi_non_c_entra_dice_di_no(): void
    {
        $palestra = $this->creaPalestra();
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $estraneo = $this->creaUtente($altra, UserRole::GymAdmin, 'altro@esempio.test');

        $promozione = $this->promo(
            ['attivazione' => 'link'],
            ['tipo' => TipoBersaglio::Palestra, 'tenant_id' => $palestra->getKey()],
        );

        $this->actingAs($estraneo);
        filament()->setCurrentPanel('admin');
        app(TenantContext::class)->set($altra);

        Livewire::test(OffertaPerTe::class, ['codice' => $promozione->codice])
            ->assertSuccessful()
            ->assertSee('Questa offerta non è per il tuo account.')
            ->assertDontSee('Il venti per cento sul prossimo pagamento.');
    }

    /** ⚠️ E se è scaduta lo dice, invece di un generico «non valida». */
    #[Test]
    public function se_e_scaduta_lo_dice(): void
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo2@esempio.test');

        $promozione = $this->promo(['attivazione' => 'link', 'scade_il' => now()->subDay()]);

        $this->actingAs($titolare);
        filament()->setCurrentPanel('admin');
        app(TenantContext::class)->set($palestra);

        Livewire::test(OffertaPerTe::class, ['codice' => $promozione->codice])
            ->assertSuccessful()
            ->assertSee('Questa offerta è scaduta.');
    }
}
