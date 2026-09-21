<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Members\Pages\ViewMember;
use App\Models\AbbonamentoTolto;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\AbbonamentiDegliIscritti;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Billing\PianoAttivo;
use App\Services\Reporting\IContiDellaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ✂️ **La palestra toglie l'abbonamento a un iscritto** — 23/09/2026, F5.
 *
 * 📌 Il committente: *«mi serve il tasto per togliere l'abbonamento
 * all'allievo»* e *«mi serve la revenue totale dall'allievo (quindi prezzo
 * fatto all'allievo meno costo dello slot tutto per tutti i rinnovi
 * dell'abbonamento pagati dall'allievo)»*.
 *
 * ══ 📌 LE TRE CONSEGUENZE, UNA PER TEST ═════════════════════════════════
 *
 * | | Coperto | Tolto |
 * |---|---|---|
 * | Resta nella palestra | ✅ | ✅ |
 * | È abbonato | ✅ | ⛔ ricade sul gratuito |
 * | Occupa un posto pagato | ✅ | ⛔ no |
 *
 * 🚨 L'ultima **cambia la fattura** della palestra sui piani a consumo, ed è il
 * motivo per cui questa classe esiste.
 */
final class LAbbonamentoToltoDallaPalestraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $gestore;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->gestore = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@alfa.test');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');

        app(TenantContext::class)->runWithoutTenant(fn () => PlanSubscription::query()->create([
            'tenant_id' => $this->palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_30')->sole()->getKey(),
            'stripe_subscription_id' => 'sub_alfa',
            'stripe_customer_id' => 'cus_alfa',
            'rinnova' => true,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->addMonth(),
        ]));
    }

    private function servizio(): AbbonamentiDegliIscritti
    {
        return app(AbbonamentiDegliIscritti::class);
    }

    /** ✅ Di serie è coperto: nessuna riga vuol dire abbonamento. */
    #[Test]
    public function un_iscritto_nuovo_ha_l_abbonamento_della_palestra(): void
    {
        $this->assertTrue($this->servizio()->haLAbbonamento($this->iscritto));
        $this->assertTrue(app(PianoAttivo::class)->eAbbonato($this->iscritto));
    }

    /**
     * 🚨 **Tolto, ricade sul gratuito — e lo sa tutto il resto.**
     *
     * 💡 Si guarda `PianoAttivo`, non il servizio: è da lì che passano il gate
     * dell'app, l'AI e la quota. Se la regola stesse altrove, uno dei tre non
     * se ne accorgerebbe.
     */
    #[Test]
    public function tolto_l_abbonamento_torna_al_gratuito(): void
    {
        $this->servizio()->togli($this->iscritto, $this->gestore);

        $this->assertFalse($this->servizio()->haLAbbonamento($this->iscritto));
        $this->assertFalse(app(PianoAttivo::class)->eAbbonato($this->iscritto));
        $this->assertSame(Plan::FREE, app(PianoAttivo::class)->per($this->iscritto)->code);
    }

    /** ⚠️ E resta nella palestra: cambia cosa gli paga, non dove sta. */
    #[Test]
    public function tolto_l_abbonamento_resta_iscritto(): void
    {
        $this->servizio()->togli($this->iscritto, $this->gestore);

        $this->assertSame($this->palestra->getKey(), $this->iscritto->refresh()->tenant_id);
        $this->assertTrue($this->iscritto->is_active);
    }

    /**
     * 🚨 **Non occupa più un posto** — ed è la ragione per cui si toglie.
     *
     * ⛔ Questo numero finisce in fattura e decide i posti liberi per gli
     * inviti: un iscritto senza abbonamento che continua a costare sarebbe il
     * peggiore dei due mondi.
     */
    #[Test]
    public function tolto_l_abbonamento_libera_un_posto(): void
    {
        $conto = app(ContoDegliIscritti::class);

        $prima = $conto->quanti($this->palestra);

        $this->servizio()->togli($this->iscritto, $this->gestore);

        $this->assertSame($prima - 1, $conto->quanti($this->palestra));

        // 💡 E il conto di tutte le palestre dice la stessa cosa.
        $this->assertSame($prima - 1, $conto->perOgniPalestra()[$this->palestra->getKey()] ?? 0);
    }

    /** ✅ Ridato, torna tutto com'era. */
    #[Test]
    public function ridato_torna_abbonato(): void
    {
        $this->servizio()->togli($this->iscritto, $this->gestore);
        $this->servizio()->ridai($this->iscritto, $this->gestore);

        $this->assertTrue(app(PianoAttivo::class)->eAbbonato($this->iscritto));
        // 💡 Il periodo resta, chiuso: serve alla revenue, che lo conta come scoperto.
        $this->assertNotNull(AbbonamentoTolto::withoutGlobalScopes()->sole()->al);
    }

    /**
     * ⚠️ **Togliere due volte non apre due periodi.**
     *
     * ⛔ Due periodi aperti insieme conterebbero lo stesso mese due volte fra
     * quelli scoperti.
     */
    #[Test]
    public function togliere_due_volte_non_raddoppia(): void
    {
        $this->servizio()->togli($this->iscritto, $this->gestore);
        $this->servizio()->togli($this->iscritto, $this->gestore);

        $this->assertSame(1, AbbonamentoTolto::withoutGlobalScopes()->count());
    }

    /**
     * 💶 **La revenue: (quota − costo) × mesi in cui era abbonato.**
     *
     * 📌 *«prezzo fatto all'allievo meno costo dello slot tutto per tutti i
     * rinnovi dell'abbonamento pagati dall'allievo»*.
     */
    #[Test]
    public function la_revenue_conta_solo_i_mesi_in_cui_era_abbonato(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');

        $this->palestra->forceFill(['prezzo_per_allievo_cent' => 5000])->save();
        $this->iscritto->forceFill(['created_at' => Carbon::parse('2026-06-10')])->save();

        // Giugno, luglio, agosto, settembre: quattro mesi.
        $this->assertSame(4, $this->servizio()->mesiCoperti($this->iscritto));

        // ✂️ Tolto per tutto luglio.
        AbbonamentoTolto::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'dal' => Carbon::parse('2026-06-30'),
            'al' => Carbon::parse('2026-08-01'),
        ]);

        $this->assertSame(3, $this->servizio()->mesiCoperti($this->iscritto));

        $margine = 5000 - app(IContiDellaPalestra::class)
            ->costoPerAllievoCent($this->palestra->refresh());

        $this->assertSame($margine * 3, $this->servizio()->revenueCent($this->iscritto));

        Carbon::setTestNow();
    }

    /**
     * ⛔ **Senza la quota, niente revenue inventata.**
     *
     * 💡 Un margine su un prezzo mai scritto sarebbe «meno il costo»: un rosso
     * inventato da noi.
     */
    #[Test]
    public function senza_la_quota_la_revenue_non_c_e(): void
    {
        $this->assertNull($this->servizio()->revenueCent($this->iscritto));
    }

    /** 🖥️ Il tasto c'è nell'elenco e nella pagina, e funziona. */
    #[Test]
    public function dal_pannello_si_toglie_e_si_ridà(): void
    {
        $this->actingAs($this->gestore);
        app(TenantContext::class)->set($this->palestra);
        filament()->setCurrentPanel('admin');

        Livewire::test(ListMembers::class)
            ->assertSuccessful()
            ->assertSee('Ti ha reso')
            ->callTableAction('abbonamento', $this->iscritto)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($this->servizio()->haLAbbonamento($this->iscritto));

        Livewire::test(ViewMember::class, ['record' => $this->iscritto->getKey()])
            ->assertSuccessful()
            ->callAction('abbonamento')
            ->assertHasNoActionErrors();

        $this->assertTrue($this->servizio()->haLAbbonamento($this->iscritto));
    }
}
