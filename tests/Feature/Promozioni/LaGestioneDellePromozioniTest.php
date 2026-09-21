<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\AiFeature;
use App\Enums\AuditAction;
use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\OrigineAssegnazione;
use App\Enums\StatoAssegnazione;
use App\Enums\StatoPromozione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiPremio;
use App\Filament\God\Resources\Promozioni\Pages\EditPromozione;
use App\Filament\God\Resources\Promozioni\Pages\ListPromozioni;
use App\Filament\God\Resources\Promozioni\Widgets\ChiLHaPresa;
use App\Models\AiCreditMovement;
use App\Models\AuditLog;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Promozioni\NumeriDellaPromozione;
use App\Services\Promozioni\RevocaDellaPromozione;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📊 **Stati, numeri e revoca** — 24/09/2026, P7 di `plan_promozioni.md`
 * (opzioni 39, 40, 41).
 */
final class LaGestioneDellePromozioniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** @param  list<array<string, mixed>>  $premi */
    private function promo(array $premi, array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Da revocare',
            'genere' => GenereDiPromozione::Entrambi,
            'attivazione' => ComeSiAttiva::Massa,
            'massimo_per_persona' => 1,
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

    private function dai(Promozione $promozione, User $utente): PromozioneAssegnazione
    {
        return app(ConsegnaDeiPremi::class)->consegna($promozione, $utente, OrigineAssegnazione::Massa, 'm:'.$utente->getKey());
    }

    // ═════════════════════════ la revoca ═════════════════════════

    /** ↩️ Gettoni senza scadenza: si tolgono quelli dati, e i comprati restano. */
    #[Test]
    public function la_revoca_toglie_i_gettoni_regalati(): void
    {
        $tizio = $this->libero();
        app(PortafoglioGettoni::class)->accredita($tizio, 50);
        $assegnazione = $this->dai($this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100]]), $tizio);

        $tolti = app(RevocaDellaPromozione::class)->revoca($assegnazione);

        $this->assertSame(100, $tolti);
        $this->assertSame(50, $tizio->fresh()->ai_credits);
        $this->assertSame(StatoAssegnazione::Revocata, $assegnazione->fresh()->stato);
        $this->assertSame(-100, AiCreditMovement::withoutGlobalScopes()->where('causale', AiCreditMovement::REVOCA_PROMOZIONE)->sole()->delta);
    }

    /** ⏳ Coi lotti si toglie il **residuo esatto**: gli spesi restano spesi, i comprati restano. */
    #[Test]
    public function coi_lotti_si_toglie_solo_il_residuo(): void
    {
        $tizio = $this->libero();
        app(PortafoglioGettoni::class)->accredita($tizio, 50);
        $assegnazione = $this->dai($this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 100, 'giorni' => 30]]), $tizio);

        foreach (range(1, 3) as $i) {
            app(PortafoglioGettoni::class)->consuma($tizio->fresh(), AiFeature::FoodPhoto);
        }

        $this->assertSame(70, app(RevocaDellaPromozione::class)->revoca($assegnazione));
        $this->assertSame(50, $tizio->fresh()->ai_credits);
    }

    /** ↩️ L'abbonamento regalato finisce subito, e i posti tornano. */
    #[Test]
    public function la_revoca_chiude_l_abbonamento_regalato(): void
    {
        $tizio = $this->libero();
        $gratuito = app(PianoAttivo::class)->per($tizio)->code;
        $assegnazione = $this->dai($this->promo([
            ['tipo' => TipoDiPremio::AbbonamentoRegalato, 'plan_id' => Plan::query()->where('code', Plan::PLUS)->value('id'), 'volte' => 3],
            ['tipo' => TipoDiPremio::PostiExtra, 'posti' => 4, 'posti_di' => 'iscritti', 'volte' => 3],
        ]), $tizio);

        $this->assertSame(Plan::PLUS, app(PianoAttivo::class)->per($tizio->fresh())->code);
        $this->assertSame(4, $tizio->fresh()->tenant->postiRegalati());

        app(RevocaDellaPromozione::class)->revoca($assegnazione);

        $this->travel(1)->seconds();
        $this->assertSame($gratuito, app(PianoAttivo::class)->per($tizio->fresh())->code);
        $this->assertSame(0, $tizio->fresh()->tenant->postiRegalati());
    }

    /** ↩️ Revoca a tutti: e il budget torna disponibile. */
    #[Test]
    public function revocare_a_tutti_restituisce_il_budget(): void
    {
        $uno = $this->libero('uno@esempio.test');
        $due = $this->libero('due@esempio.test');
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 50]], ['budget_gettoni' => 100]);

        $this->dai($promozione, $uno);
        $this->dai($promozione->fresh(), $due);
        $this->assertFalse($promozione->fresh()->eSpendibile());

        $esito = app(RevocaDellaPromozione::class)->revocaATutti($promozione->fresh());

        $this->assertSame(['persone' => 2, 'gettoni' => 100], $esito);
        $this->assertTrue($promozione->fresh()->eSpendibile());
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PromozioneRevocata->value)->count());
    }

    // ═════════════════════════ i numeri ═════════════════════════

    /** 📊 Persone, gettoni, scontato, e quanto è entrato insieme. Opzione 40. */
    #[Test]
    public function i_numeri_della_promozione(): void
    {
        $tizio = $this->libero();
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 30]], ['massimo_per_persona' => null]);
        $this->dai($promozione, $tizio);

        PromozioneAssegnazione::query()->create([
            'promozione_id' => $promozione->getKey(),
            'user_id' => $tizio->getKey(),
            'origine' => OrigineAssegnazione::Link,
            'riferimento' => 'cs_pagata',
            'stato' => StatoAssegnazione::Usata,
            'scontato_cent' => 200,
        ]);
        PagamentoIncassato::withoutGlobalScopes()->create([
            'tenant_id' => $tizio->tenant_id,
            'user_id' => $tizio->getKey(),
            'tipo' => PagamentoIncassato::GETTONI,
            'importo_cent' => 800,
            'stripe_id' => 'cs_pagata',
            'pagato_il' => now(),
        ]);

        $n = app(NumeriDellaPromozione::class)->di($promozione);

        $this->assertSame(1, $n['persone']);
        $this->assertSame(2, $n['volte']);
        $this->assertSame(30, $n['gettoni']);
        $this->assertSame(200, $n['scontato_cent']);
        $this->assertSame(800, $n['incassato_cent']);
    }

    // ═════════════════════════ il pannello ═════════════════════════

    /** 🖥️ La pagina della promozione si apre coi numeri e chi l'ha presa, e revoca. */
    #[Test]
    public function la_pagina_della_promozione_revoca(): void
    {
        $tizio = $this->libero();
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 40]]);
        $assegnazione = $this->dai($promozione, $tizio);

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(EditPromozione::class, ['record' => $promozione->getKey()])->assertSuccessful();

        Livewire::test(ChiLHaPresa::class, ['record' => $promozione])
            ->assertSee('tizio@esempio.test')
            ->callTableAction('revoca', $assegnazione);

        $this->assertSame(StatoAssegnazione::Revocata, $assegnazione->fresh()->stato);
        $this->assertSame(0, (int) $tizio->fresh()->ai_credits);
    }

    /** 🗂️ Il filtro sugli stati che non sono una colonna. Opzione 39. */
    #[Test]
    public function il_filtro_trova_le_scadute(): void
    {
        $scaduta = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 1]], ['nome' => 'Vecchia', 'scade_il' => now()->subDay()]);
        $viva = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 1]], ['nome' => 'Nuova']);

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListPromozioni::class)
            ->filterTable('come_sta', 'scadute')
            ->assertCanSeeTableRecords([$scaduta])
            ->assertCanNotSeeTableRecords([$viva]);
    }

    /** ⛔ Disattivare è definitivo: la scheda non offre più «Riattiva». */
    #[Test]
    public function una_disattivata_non_si_riattiva_dalla_scheda(): void
    {
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 1]], ['stato' => StatoPromozione::Disattivata]);

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListPromozioni::class)->assertTableActionHidden('sospendi', $promozione);
    }
}
