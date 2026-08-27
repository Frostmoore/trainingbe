<?php

declare(strict_types=1);

namespace Tests\Feature\God;

use App\Enums\UserRole;
use App\Filament\God\Resources\Users\Tables\UsersTable;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 3b-H.10 — l'abbonamento regalato dal pannello god.
 *
 * ── 🚨 LA TRAPPOLA CHE QUESTI TEST CHIUDONO ───────────────────────────────
 *
 * ⛔ **Il pulsante «togli» non deve cancellare un abbonamento PAGATO.** Se lo
 * facesse, l'addebito su Stripe resterebbe attivo e il cliente si ritroverebbe
 * senza il prodotto: paga e non ha. E' il danno peggiore possibile qui, e non
 * produce nessun errore.
 *
 * 💡 Il regalo si riconosce dall'**assenza** di `stripe_subscription_id`.
 */
class AbbonamentoRegalatoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function regaloDi(User $utente): ?PlanSubscription
    {
        // ⚠️ Il metodo e' privato di proposito: qui si prova la **regola**, non
        // l'interfaccia di Filament, che ha un suo modo di essere provata e
        // costa dieci volte tanto.
        $m = new ReflectionMethod(UsersTable::class, 'regaloDi');
        $m->setAccessible(true);

        return $m->invoke(null, $utente);
    }

    private function persona(string $email = 'solo@test.it'): User
    {
        $tenant = $this->creaPalestra('Personale', 'personale-'.uniqid(), 'PERS'.random_int(1000, 9999));
        $tenant->forceFill(['kind' => \App\Enums\TenantKind::Personal])->save();

        // ⚠️ La palestra di prova nasce abbonata: qui si vuole una persona
        // **senza niente**, o il regalo non si distinguerebbe da quello che c'e'.
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();

        return $this->creaUtente($tenant, UserRole::Member, $email);
    }

    private function regala(User $utente): PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $utente->tenant->getKey(),
            'plan_id' => Plan::where('code', Plan::PLUS)->value('id'),
            'starts_at' => now(),
            'ends_at' => null,
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'rinnova' => false,
        ]);
    }

    #[Test]
    public function un_regalo_si_riconosce(): void
    {
        $utente = $this->persona();

        $this->assertNull($this->regaloDi($utente), 'prima non ha niente');

        $this->regala($utente);

        $this->assertNotNull($this->regaloDi($utente));
    }

    /**
     * ⛔ **Il test piu' importante di questo file.** Un abbonamento pagato NON
     * e' un regalo, e il pulsante «togli» non deve nemmeno vederlo.
     */
    #[Test]
    public function un_abbonamento_pagato_non_e_un_regalo(): void
    {
        $utente = $this->persona();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $utente->tenant->getKey(),
            'plan_id' => Plan::where('code', Plan::PLUS)->value('id'),
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'stripe_subscription_id' => 'sub_pagato',
            'stripe_customer_id' => 'cus_pagato',
            'rinnova' => true,
        ]);

        $this->assertNull(
            $this->regaloDi($utente),
            'cancellarlo lascerebbe l\'addebito attivo e il cliente senza prodotto',
        );
    }

    /** 🎁 Il regalo rende abbonati davvero: e' il punto dell'esercizio. */
    #[Test]
    public function il_regalo_rende_abbonati(): void
    {
        $utente = $this->persona();
        $piano = app(PianoAttivo::class);

        $this->assertFalse($piano->eAbbonato($utente));

        $this->regala($utente);

        $this->assertTrue($piano->eAbbonato($utente->fresh()));
    }

    /** 🚨 `ends_at` nullo = non scade mai, ed e' la convenzione delle palestre. */
    #[Test]
    public function e_non_scade_mai(): void
    {
        $utente = $this->persona();
        $regalo = $this->regala($utente);

        $this->assertNull($regalo->ends_at);
        $this->assertTrue($regalo->eAttivo());
    }

    /**
     * ⛔ **Un regalo scaduto non e' piu' un regalo da togliere.** Serve a non
     * far comparire «Togli abbonamento» su chi non ce l'ha piu'.
     */
    #[Test]
    public function e_un_regalo_finito_non_si_conta_piu(): void
    {
        $utente = $this->persona();

        $this->regala($utente)->update(['ends_at' => now()->subDay()]);

        $this->assertNull($this->regaloDi($utente));
    }

    /** 💡 Chi non ha tenant non ha niente da regalare, e non deve esplodere. */
    #[Test]
    public function chi_non_ha_tenant_non_esplode(): void
    {
        $god = $this->creaSuperAdmin();

        $this->assertNull($this->regaloDi($god));
    }
}
