<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\AddebitoMensile;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\AddebitoDelMese;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 💳 **Stripe in tutti gli scenari** — §9, 18/09/2026.
 *
 * > *«il discorso di stripe è che va sistemato tutto, in tutti gli scenari,
 * > almeno abbiamo la parte pagamenti coperta»* — il committente.
 *
 * ══ 🚨 PERCHE' QUESTI TEST NON C'ERANO ════════════════════════════════════
 *
 * ⛔ Il lato **in uscita** verso Stripe non si poteva provare: ogni servizio si
 * faceva il suo `new StripeClient(...)`. I test si fermavano ai rifiuti, e la
 * strada buona l'aveva percorsa solo la produzione. 💡 Da §9.1 Stripe sta nel
 * container, e qui c'è un finto che **registra ogni chiamata**.
 *
 * ⚠️ Il webhook si chiama con `Cassa::applica()` direttamente: la **firma** è già
 * provata da `StripeWebhookTest`, qui si prova cosa succede dopo.
 */
final class StripeInTuttiGliScenariTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->stripe = $this->stripeFinto();
    }

    private function piano(string $codice): Plan
    {
        return Plan::query()->where('code', $codice)->firstOrFail();
    }

    private function trainer(string $email = 'coach@esempio.test', UserRole $ruolo = UserRole::FreeTrainer): User
    {
        return app(CreaTenantPersonale::class)(
            'Coach', $email, ['password' => TestCase::FAKE_PASSWORD], $ruolo,
        )->fresh();
    }

    /** @return array{0: Tenant, 1: User} la palestra e il suo proprietario */
    private function palestraConTitolare(): array
    {
        $palestra = $this->creaPalestra();

        /*
         * ⚠️ Si toglie l'abbonamento di serie di `creaPalestra()`: qui si prova
         * proprio come una palestra **ottiene** un piano.
         */
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->delete();

        return [$palestra->fresh(), $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test')];
    }

    /** Un abbonamento pagato su Stripe, già in corso. */
    private function canoneStripe(Tenant $tenant, string $codice, string $sub = 'sub_esistente'): PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $this->piano($codice)->getKey(),
            'stripe_subscription_id' => $sub,
            'stripe_customer_id' => 'cus_esistente',
            'rinnova' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);
    }

    /** Il webhook del primo pagamento, com'è scritto dall'apertura. */
    private function pagato(User $utente, Tenant $tenant, string $codice, array $extra = []): void
    {
        app(Cassa::class)->applica([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => 'cs_test_prova',
                'mode' => 'subscription',
                'subscription' => 'sub_nuovo_'.uniqid(),
                'customer' => 'cus_nuovo',
                'metadata' => [
                    'tipo' => PianiInVendita::TIPO,
                    'piano' => $codice,
                    'user_id' => (string) $utente->getKey(),
                    'tenant_id' => (string) $tenant->getKey(),
                ],
            ], $extra)],
        ]);
    }

    // ═══════════════════════ 9.2 — vendere un piano ═══════════════════════

    /**
     * 🚨 **Un trainer può pagare il suo piano** — prima no: il sito lo mostrava
     * coi prezzi, senza nessun pulsante.
     */
    #[Test]
    public function un_trainer_apre_il_pagamento_del_suo_piano(): void
    {
        $coach = $this->trainer();

        $url = app(PianiInVendita::class)->apri($coach, 'trainer_15');

        $this->assertStringStartsWith('https://checkout.stripe.test/', $url);

        $richiesta = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame('subscription', $richiesta['mode']);
        $this->assertSame('trainer_15', $richiesta['metadata']['piano']);
        $this->assertSame((string) $coach->tenant_id, $richiesta['metadata']['tenant_id']);

        /*
         * 🚨 **Il prezzo dal listino**, non dal client.
         */
        $this->assertSame(
            $this->piano('trainer_15')->price_cents,
            $richiesta['line_items'][0]['price_data']['unit_amount'],
        );

        /*
         * ⚠️ E i metadata **anche sull'abbonamento**, o il rinnovo del mese dopo
         * non saprebbe cosa sta rinnovando.
         */
        $this->assertSame('trainer_15', $richiesta['subscription_data']['metadata']['piano']);
    }

    /** 🚨 E dall'API il prezzo non si sceglie: un campo in più non conta. */
    #[Test]
    public function dallapi_il_prezzo_non_si_sceglie(): void
    {
        $coach = $this->trainer();

        $this->actingAs($coach, 'sanctum')
            ->postJson('/api/v1/billing/checkout', [
                'tipo' => 'piano',
                'piano' => 'trainer_30',
                'unit_amount' => 1,
                'prezzo' => 1,
            ])
            ->assertOk();

        $this->assertSame(
            $this->piano('trainer_30')->price_cents,
            $this->stripe->ultima('checkout.sessions.create')[0]['line_items'][0]['price_data']['unit_amount'],
        );
    }

    #[Test]
    public function il_titolare_di_una_palestra_apre_il_piano_palestra(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        app(PianiInVendita::class)->apri($titolare, 'gym_50');

        $meta = $this->stripe->ultima('checkout.sessions.create')[0]['metadata'];

        $this->assertSame((string) $palestra->getKey(), $meta['tenant_id']);
        $this->assertSame('gym_50', $meta['piano']);
    }

    /**
     * ⛔ **Un iscritto non abbona la sua palestra**: pagherebbe per conto di tutti.
     */
    #[Test]
    public function un_iscritto_non_compra_il_piano_della_palestra(): void
    {
        [$palestra] = $this->palestraConTitolare();
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');

        $this->expectException(InvalidArgumentException::class);

        app(PianiInVendita::class)->apri($iscritto, 'gym_20');
    }

    /** ⛔ I piani si comprano dallo spazio giusto. */
    #[Test]
    public function un_trainer_non_compra_un_piano_palestra_dal_suo_spazio(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PianiInVendita::class)->apri($this->trainer(), 'gym_20');
    }

    /**
     * 🚨 **Chi ha già un canone pagato non ne apre un secondo** — lo cambia.
     *
     * ⛔ Un secondo checkout sarebbe un secondo abbonamento, e il primo
     * continuerebbe a rinnovarsi: due addebiti al mese per un servizio solo.
     */
    #[Test]
    public function chi_ha_gia_un_canone_non_ne_apre_un_secondo(): void
    {
        $coach = $this->trainer();
        $this->canoneStripe($coach->tenant, 'trainer_5');

        try {
            app(PianiInVendita::class)->apri($coach, 'trainer_15');
            $this->fail('si e aperto un secondo abbonamento');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non paghi due volte', $e->getMessage());
        }

        $this->assertSame([], $this->stripe->chiamateA('checkout.sessions.create'));
    }

    /** 🚨 **Il webhook accende il piano giusto, sul tenant giusto.** */
    #[Test]
    public function il_webhook_accende_il_piano(): void
    {
        $coach = $this->trainer();

        $this->pagato($coach, $coach->tenant, 'trainer_15', ['subscription' => 'sub_acceso']);

        $riga = PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', 'sub_acceso')->first();

        $this->assertNotNull($riga, 'il piano pagato non si e acceso');
        $this->assertSame($coach->tenant_id, $riga->tenant_id);
        $this->assertSame($this->piano('trainer_15')->getKey(), $riga->plan_id);
        $this->assertTrue($riga->eAttivo());
    }

    /** 💡 Lo stesso abbonamento due volte non fa due righe. */
    #[Test]
    public function il_webhook_ripetuto_non_raddoppia(): void
    {
        $coach = $this->trainer();

        $this->pagato($coach, $coach->tenant, 'trainer_5', ['subscription' => 'sub_doppio']);
        $this->pagato($coach, $coach->tenant, 'trainer_5', ['subscription' => 'sub_doppio']);

        $this->assertSame(
            1,
            PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', 'sub_doppio')->count(),
        );
    }

    /**
     * 🚨 **Il piano di prima si chiude — anche su Stripe.**
     *
     * ⛔ Chiuso solo da noi continuerebbe a rinnovarsi: il modo più silenzioso
     * di far pagare due volte qualcuno.
     */
    #[Test]
    public function il_piano_di_prima_si_chiude_anche_su_stripe(): void
    {
        $coach = $this->trainer();
        $vecchio = $this->canoneStripe($coach->tenant, 'trainer_5', 'sub_vecchio');

        $this->pagato($coach, $coach->tenant, 'trainer_30', ['subscription' => 'sub_nuovo']);

        $this->assertFalse($vecchio->fresh()->eAttivo(), 'il piano di prima e ancora attivo');
        $this->assertNotEmpty(
            array_filter($this->stripe->chiamateA('subscriptions.cancel'), fn ($c) => $c[1][0] === 'sub_vecchio'),
            'il piano di prima si e chiuso solo da noi: su Stripe continua a rinnovarsi',
        );
    }

    /**
     * 💡 **Chi compra un piano trainer diventa trainer** nel suo spazio — il caso
     * di chi allena in palestra (a casa è `free_user`) e vuole un giro suo.
     */
    #[Test]
    public function chi_compra_un_piano_trainer_diventa_trainer(): void
    {
        $persona = $this->trainer('diventa@esempio.test', UserRole::FreeUser);

        $this->pagato($persona, $persona->tenant, 'trainer_5');

        $this->assertTrue(
            $this->ctx()->runAs($persona->tenant, function () use ($persona): bool {
                $fresco = User::withoutGlobalScopes()->findOrFail($persona->getKey());
                $fresco->unsetRelation('roles');

                return $fresco->hasRole(UserRole::FreeTrainer->value);
            }),
            'ha pagato il piano trainer e non e diventato trainer',
        );
    }

    // ═══════════════════════ 9.3 — il piano a consumo ═══════════════════════

    /**
     * 🚨 **Il consumo salva la carta, non addebita.** Il cliente nasce prima, così
     * si sa per certo chi riceverà le fatture.
     */
    #[Test]
    public function il_consumo_salva_la_carta_senza_addebitare(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        app(PianiInVendita::class)->apri($titolare, 'gym_consumo');

        $this->assertCount(1, $this->stripe->chiamateA('customers.create'), 'il cliente non e nato');

        $sessione = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame('setup', $sessione['mode'], 'il consumo addebita subito invece di salvare la carta');
        $this->assertArrayNotHasKey('line_items', $sessione);
        $this->assertStringStartsWith('cus_finto_', $sessione['customer']);
    }

    /**
     * 🚨 **La carta diventa quella di serie**, o la fattura di fine mese troverebbe
     * un cliente senza nessun modo di pagare.
     */
    #[Test]
    public function il_webhook_del_consumo_accende_il_piano_e_fissa_la_carta(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $this->pagato($titolare, $palestra, 'gym_consumo', [
            'mode' => 'setup',
            'subscription' => null,
            'customer' => 'cus_consumo',
            'setup_intent' => 'seti_prova',
        ]);

        $aggiornamento = $this->stripe->ultima('customers.update');

        $this->assertSame('cus_consumo', $aggiornamento[0]);
        $this->assertSame('pm_finto', $aggiornamento[1]['invoice_settings']['default_payment_method']);

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->where('plan_id', $this->piano('gym_consumo')->getKey())
            ->first();

        $this->assertNotNull($riga, 'il consumo non si e acceso');
        $this->assertSame('cus_consumo', $riga->stripe_customer_id);
        $this->assertNull($riga->ends_at, 'il consumo ha preso una scadenza');
    }

    /**
     * ⚠️ **Dal canone al consumo, a fine periodo**: quel mese è già pagato, e il
     * consumo non deve farlo pagare una seconda volta.
     */
    #[Test]
    public function dal_canone_al_consumo_si_passa_a_fine_periodo(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();
        $canone = $this->canoneStripe($palestra, 'gym_50', 'sub_canone');

        $this->pagato($titolare, $palestra, 'gym_consumo', [
            'mode' => 'setup',
            'subscription' => null,
            'customer' => 'cus_consumo',
            'setup_intent' => 'seti_prova',
        ]);

        $disdetta = $this->stripe->ultima('subscriptions.update');

        $this->assertSame('sub_canone', $disdetta[0]);
        $this->assertTrue($disdetta[1]['cancel_at_period_end'], 'il canone si e chiuso subito: quel mese lo paga due volte');

        $this->assertTrue($canone->fresh()->eAttivo(), 'il canone gia pagato si e spento prima del tempo');

        $consumo = PlanSubscription::withoutGlobalScopes()
            ->where('plan_id', $this->piano('gym_consumo')->getKey())
            ->firstOrFail();

        $this->assertEquals(
            $canone->ends_at->toDateTimeString(),
            $consumo->starts_at->toDateTimeString(),
            'il consumo non parte dove finisce il canone',
        );
    }

    // ═══════════════════════ 9.4 — il ciclo di vita ═══════════════════════

    /**
     * 🚨 **Un rinnovo rifiutato si segna** — e non si spegne niente.
     */
    #[Test]
    public function un_rinnovo_rifiutato_si_segna_e_non_spegne(): void
    {
        $coach = $this->trainer();
        $riga = $this->canoneStripe($coach->tenant, 'trainer_15', 'sub_rifiutato');

        app(Cassa::class)->applica([
            'id' => 'evt_fallito',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_fallita', 'subscription' => 'sub_rifiutato']],
        ]);

        $riga->refresh();

        $this->assertNotNull($riga->pagamento_fallito_il, 'il rifiuto non si e segnato: nessuno lo sapra');
        $this->assertTrue($riga->eAttivo(), 'al primo rifiuto si e spento il servizio');
    }

    /** 💡 Pagato al tentativo dopo, il segno se ne va. */
    #[Test]
    public function pagato_il_segno_del_rifiuto_se_ne_va(): void
    {
        $coach = $this->trainer();
        $riga = $this->canoneStripe($coach->tenant, 'trainer_15', 'sub_ripreso');
        $riga->forceFill(['pagamento_fallito_il' => now()->subDay()])->save();

        app(Cassa::class)->applica([
            'id' => 'evt_pagato',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_pagata',
                'subscription' => 'sub_ripreso',
                'lines' => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
            ]],
        ]);

        $this->assertNull($riga->fresh()->pagamento_fallito_il);
    }

    /**
     * 🚨 **La fattura a consumo è «emessa», non «pagata», finché non paga.**
     *
     * ⛔ Prima risultava pagata appena creata: con la carta rifiutata il pannello
     * diceva «Pagato» su una fattura mai pagata.
     */
    #[Test]
    public function la_fattura_a_consumo_e_pagata_solo_quando_e_pagata(): void
    {
        [$palestra] = $this->palestraConTitolare();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $this->piano('gym_consumo')->getKey(),
            'stripe_customer_id' => 'cus_consumo',
            'starts_at' => now()->subYear(),
        ]);

        $servizio = app(AddebitoDelMese::class);
        $addebito = $servizio->incassa($servizio->calcola($palestra, '2026-08'));

        $this->assertSame(AddebitoMensile::EMESSO, $addebito->stato, 'la fattura risulta pagata appena emessa');

        app(Cassa::class)->applica([
            'id' => 'evt_consumo_pagato',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => (string) $addebito->stripe_invoice_id,
                'metadata' => ['tipo' => 'addebito_a_consumo', 'addebito_id' => (string) $addebito->getKey()],
            ]],
        ]);

        $this->assertSame(AddebitoMensile::INCASSATO, $addebito->fresh()->stato);
    }

    /** 🚨 E se la carta la rifiuta, lo dice. */
    #[Test]
    public function la_fattura_a_consumo_rifiutata_lo_dice(): void
    {
        [$palestra] = $this->palestraConTitolare();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $this->piano('gym_consumo')->getKey(),
            'stripe_customer_id' => 'cus_consumo',
            'starts_at' => now()->subYear(),
        ]);

        $servizio = app(AddebitoDelMese::class);
        $addebito = $servizio->incassa($servizio->calcola($palestra, '2026-08'));

        app(Cassa::class)->applica([
            'id' => 'evt_consumo_rifiutato',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => (string) $addebito->stripe_invoice_id,
                'metadata' => ['tipo' => 'addebito_a_consumo', 'addebito_id' => (string) $addebito->getKey()],
            ]],
        ]);

        $this->assertSame(AddebitoMensile::FALLITO, $addebito->fresh()->stato);
    }

    /**
     * 🚨 **Una fattura emessa non si riemette.** Rilanciare il giro del mese
     * farebbe due fatture per lo stesso mese.
     */
    #[Test]
    public function una_fattura_emessa_non_si_riemette(): void
    {
        [$palestra] = $this->palestraConTitolare();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $this->piano('gym_consumo')->getKey(),
            'stripe_customer_id' => 'cus_consumo',
            'starts_at' => now()->subYear(),
        ]);

        $servizio = app(AddebitoDelMese::class);
        $addebito = $servizio->incassa($servizio->calcola($palestra, '2026-08'));
        $servizio->incassa($addebito->fresh());

        $this->assertCount(1, $this->stripe->chiamateA('invoices.create'), 'lo stesso mese e stato fatturato due volte');
    }

    // ═══════════════════════════ 9.5 — cambiare ═══════════════════════════

    /** 💡 **Salire è subito**, e il conguaglio lo fa Stripe. */
    #[Test]
    public function salire_di_piano_e_subito(): void
    {
        $coach = $this->trainer();
        $riga = $this->canoneStripe($coach->tenant, 'trainer_5', 'sub_sale');

        app(PianiInVendita::class)->cambia($coach, 'trainer_30');

        $aggiornamento = $this->stripe->ultima('subscriptions.update');

        $this->assertSame('sub_sale', $aggiornamento[0]);
        $this->assertSame('create_prorations', $aggiornamento[1]['proration_behavior']);
        $this->assertSame(
            $this->piano('trainer_30')->price_cents,
            $aggiornamento[1]['items'][0]['price_data']['unit_amount'],
        );

        /*
         * ⚠️ **Subito anche da noi**: chi è appena salito deve poter invitare il
         * sesto allievo adesso, non quando arriva un evento.
         */
        $this->assertSame($this->piano('trainer_30')->getKey(), $riga->fresh()->plan_id);
    }

    /**
     * ⛔ **Scendere non lascia fuori nessuno.** Chi dei ventuno iscritti resterebbe
     * fuori da un piano da venti? Deciderlo qui sarebbe scegliere a caso.
     */
    #[Test]
    public function scendere_si_rifiuta_se_lascia_fuori_qualcuno(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();
        $this->canoneStripe($palestra, 'gym_50', 'sub_scende');

        $this->piano('gym_20')->forceFill(['max_members' => 2])->save();

        foreach (['a', 'b', 'c'] as $i) {
            $this->creaUtente($palestra, UserRole::Member, "iscritto-{$i}@esempio.test");
        }

        try {
            app(PianiInVendita::class)->cambia($titolare, 'gym_20');
            $this->fail('si e sceso lasciando fuori un iscritto');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('3 iscritti', $e->getMessage());
        }

        $this->assertSame([], $this->stripe->chiamateA('subscriptions.update'), 'Stripe e stato toccato lo stesso');
    }

    /** ⚠️ Senza un piano pagato non c'è niente da cambiare. */
    #[Test]
    public function senza_un_piano_pagato_non_si_cambia(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PianiInVendita::class)->cambia($this->trainer(), 'trainer_30');
    }

    /** 🔌 E la rotta risponde con il messaggio, non con un errore tecnico. */
    #[Test]
    public function la_rotta_del_cambio_risponde_con_il_messaggio(): void
    {
        $this->actingAs($this->trainer(), 'sanctum')
            ->postJson('/api/v1/billing/cambia-piano', ['piano' => 'trainer_30'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Non hai un piano da cambiare: scegline uno dal listino.');
    }
}
