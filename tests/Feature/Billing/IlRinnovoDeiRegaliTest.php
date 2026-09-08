<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TenantKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\Stripe\Cassa;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎁 Il rinnovo automatico degli abbonamenti regalati — 08/09/2026.
 *
 * ── 📌 La richiesta ────────────────────────────────────────────────────────
 *
 * Il committente, dopo il toggle «Rinnova in automatico»: *«deve funzionare
 * anche con gli abbonamenti che regalo»*.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * | | Se si rompe |
 * |---|---|
 * | Il regalo si rinnova | Un regalo sparisce dopo un mese senza che nessuno l'abbia tolto |
 * | Spegnere lo fa finire | ⛔ La spunta diventa **scollegata**: sembra funzionare e non governa niente |
 * | Le palestre non si toccano | 🚨 Un iscritto spegne il rinnovo **della sua palestra**, per tutti |
 *
 * ⚠️ La terza e' la piu' pericolosa, ed e' quella che non si nota provando:
 * l'abbonamento di una palestra e un regalo si somigliano moltissimo — nessuno
 * dei due ha un id di Stripe.
 */
class IlRinnovoDeiRegaliTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function piano(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    /** Un regalo: niente Stripe, su un tenant personale. */
    private function regala(Tenant $tenant, ?string $fine): PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->piano(Plan::PLUS)->id,
            'starts_at' => now()->subMonth(),
            'ends_at' => $fine,
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'rinnova' => true,
        ]);
    }

    private function tenantPersonale(): Tenant
    {
        $t = $this->creaPalestra('Personale', 'personale', 'PERS2345');
        $t->update(['kind' => TenantKind::Personal->value]);

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->delete();

        return $t->fresh();
    }

    #[Test]
    public function il_regalo_con_il_rinnovo_acceso_si_allunga(): void
    {
        $tenant = $this->tenantPersonale();
        $riga = $this->regala($tenant, now()->addHours(2)->toDateTimeString());

        $this->artisan('abbonamenti:rinnova-i-regali')->assertSuccessful();

        $riga->refresh();

        /*
         * 💡 Un mese e un giorno **dalla scadenza**, non da adesso: sommare a
         * `now()` farebbe scivolare la data in avanti a ogni giro.
         */
        self::assertTrue(
            $riga->ends_at->greaterThan(now()->addMonth()),
            'il regalo doveva essere allungato di un mese',
        );
    }

    #[Test]
    public function ma_se_il_rinnovo_e_spento_scade_e_basta(): void
    {
        $tenant = $this->tenantPersonale();
        $riga = $this->regala($tenant, now()->addHours(2)->toDateTimeString());
        $riga->update(['rinnova' => false]);

        $prima = $riga->ends_at;

        $this->artisan('abbonamenti:rinnova-i-regali')->assertSuccessful();

        self::assertEquals($prima, $riga->fresh()->ends_at);
    }

    /**
     * 🚨 Il caso pericoloso: la palestra non si tocca.
     *
     * ⛔ Un abbonamento di palestra ha `ends_at = null` e nessun id di Stripe,
     * esattamente come un regalo nato prima dell'08/09. ⚠️ Se il comando
     * guardasse solo Stripe, ogni notte allungherebbe righe che non sono di chi
     * usa l'app.
     */
    #[Test]
    public function le_palestre_restano_fuori_dal_comando(): void
    {
        $palestra = $this->creaPalestra();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->id)
            ->update(['ends_at' => now()->addHours(2), 'rinnova' => true]);

        $prima = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->id)
            ->value('ends_at');

        $this->artisan('abbonamenti:rinnova-i-regali')->assertSuccessful();

        $dopo = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->id)
            ->value('ends_at');

        self::assertEquals($prima, $dopo, 'la palestra non doveva essere toccata');
    }

    /**
     * ⛔ **Spegnere deve voler dire qualcosa.**
     *
     * 🚨 Un regalo nato prima dell'08/09 non ha scadenza: su una riga cosi'
     * `rinnova = false` non cambierebbe niente, e l'app direbbe «non si
     * rinnova» a un abbonamento che non finisce mai.
     */
    #[Test]
    public function spegnere_il_rinnovo_su_un_regalo_senza_scadenza_gli_da_una_fine(): void
    {
        $tenant = $this->tenantPersonale();
        $utente = $this->creaUtente($tenant, UserRole::Member, 'regalato@test.it');

        $this->regala($tenant, null);

        self::assertTrue(app(PianoAttivo::class)->eAbbonato($utente->fresh()));

        app(Cassa::class)->rinnovoAutomatico($utente, false);

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        self::assertFalse((bool) $riga->rinnova);
        self::assertNotNull($riga->ends_at, 'senza una data, spegnere non farebbe niente');
    }

    /**
     * 🚨 E un iscritto **non** puo' spegnere il rinnovo della sua palestra.
     *
     * ⛔ Sarebbe il difetto piu' grave di tutto il giro: una persona sola che
     * disdice per decine.
     */
    #[Test]
    public function un_iscritto_non_puo_spegnere_il_rinnovo_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'iscritto@test.it');

        $this->expectException(\InvalidArgumentException::class);

        app(Cassa::class)->rinnovoAutomatico($utente, false);
    }
}
