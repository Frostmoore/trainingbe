<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\Pages\ListTenants;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Filament\God\Resources\Users\UserResource;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏷️ **Il pannello dice il piano vero** — 21/09/2026, fase F1 di
 * `plan_UX_pannello.md`.
 *
 * ══ ⛔ COSA MOSTRAVA PRIMA ════════════════════════════════════════════════
 *
 * `tenants.plan`: una colonna **legacy** con tre valori inventati — `starter`,
 * `pro`, `enterprise` — che **non hanno niente a che vedere** con
 * l'abbonamento. Quello vero sta in `plan_subscriptions → plans`, ed è quello
 * che decide posti, intelligenza artificiale e fattura.
 *
 * 🚨 Quindi il pannello della piattaforma mostrava, per ogni palestra, **un
 * piano che non esiste**. Nessun errore, nessun sospetto: un badge grigio con
 * dentro una parola.
 *
 * 📌 Il committente, tre volte nello stesso elenco di richieste: *«I piani
 * devono avere il nome vero»*.
 *
 * ══ 💡 COSA DIFENDE QUESTO FILE ═══════════════════════════════════════════
 *
 * Che nel pannello si legga il nome del piano **dell'abbonamento in corso**, e
 * che le tre parole inventate non tornino.
 */
class IlPannelloDiceIlPianoVeroTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');
    }

    /** Una palestra abbonata davvero a un piano del listino. */
    private function palestraConPiano(string $codice = 'gym_30'): Tenant
    {
        $palestra = $this->creaPalestra();

        app(TenantContext::class)->runWithoutTenant(function () use ($palestra, $codice): void {
            PlanSubscription::query()->where('tenant_id', $palestra->getKey())->delete();

            PlanSubscription::query()->create([
                'tenant_id' => $palestra->getKey(),
                'plan_id' => Plan::query()->where('code', $codice)->sole()->getKey(),
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonth(),
                'rinnova' => true,
            ]);
        });

        return $palestra->fresh();
    }

    /**
     * 🚨 Il nome vero, e **non** quello della colonna morta.
     *
     * ⚠️ `tenants.plan` vale `starter` di default su ogni palestra: se
     * riapparisse, questo test lo direbbe.
     */
    #[Test]
    public function l_elenco_mostra_il_piano_dell_abbonamento(): void
    {
        $this->palestraConPiano('gym_30');

        Livewire::test(ListTenants::class)
            ->assertSuccessful()
            ->assertSee('Palestra Advanced')
            ->assertDontSee('starter')
            ->assertDontSee('Starter');
    }

    /**
     * 💡 **E quanto paga davvero.** 📌 *«Mi serve di sapere al volo quanto
     * paga»*.
     *
     * ⚠️ Il numero viene da `Plan::quantoCostaAlMese()`: su un piano a canone è
     * il canone, su uno a consumo è il conto del mese. ⛔ Leggere `price_cents`
     * e basta scriverebbe «0,00 €» sul piano più caro del listino.
     */
    #[Test]
    public function l_elenco_mostra_quanto_paga(): void
    {
        $this->palestraConPiano('gym_30');

        Livewire::test(ListTenants::class)
            ->assertSuccessful()
            ->assertSee('130,00 €');
    }

    /**
     * 🚨 **Una palestra senza abbonamento si vede subito**, e in rosso.
     *
     * ⛔ Prima diceva «starter», cioè sembrava che pagasse qualcosa: è il modo
     * più efficace di non accorgersi mai di un cliente che non paga.
     */
    #[Test]
    public function una_palestra_senza_abbonamento_lo_dice(): void
    {
        $palestra = $this->creaPalestra();

        app(TenantContext::class)->runWithoutTenant(
            fn () => PlanSubscription::query()->where('tenant_id', $palestra->getKey())->delete(),
        );

        Livewire::test(ListTenants::class)
            ->assertSuccessful()
            ->assertSee('nessun abbonamento');
    }

    /**
     * 📌 *«voglio che mi mostri il numero degli utenti attivi accanto al link
     * del menù proprio come le palestre»*.
     */
    #[Test]
    public function il_menu_conta_gli_utenti_attivi(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::Member, 'attivo@esempio.test');

        $spento = $this->creaUtente($palestra, UserRole::Member, 'spento@esempio.test');
        $spento->forceFill(['is_active' => false])->save();

        $badge = (int) UserResource::getNavigationBadge();

        $this->assertGreaterThanOrEqual(1, $badge);

        // ⚠️ Chi è spento non si conta: non usa niente e non costa niente.
        $this->assertSame(
            User::withoutGlobalScopes()->where('is_active', true)->count(),
            $badge,
        );
    }

    /** ⛔ E la quota AI non si mostra più: era sempre 150 per tutti. */
    #[Test]
    public function l_elenco_utenti_mostra_i_gettoni_non_la_quota(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');
        $persona->forceFill(['ai_credits' => 4200])->save();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('4.200')
            ->assertDontSee('Quota AI');
    }
}
