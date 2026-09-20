<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\OffertaDiPiano;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use App\Services\Tenancy\IscrizioneDalSito;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 🔗 **Il piano su misura si paga con Stripe** — 21/09/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * > *«certo che il piano cucito a mano deve essere fatto con stripe»*
 *
 * ══ ⛔ COSA C'ERA, E PERCHÉ ERA SBAGLIATO ═════════════════════════════════
 *
 * La mattina del 21/09 il piano su misura si **assegnava** dal pannello:
 * nasceva un `PlanSubscription` senza `stripe_subscription_id`, cioè **un
 * servizio acceso che nessuno fattura**. 🚨 Su un canone voleva dire
 * ricordarsi ogni mese di incassarlo a mano — cioè, prima o poi, regalarlo.
 *
 * ══ 💡 COSA DIFENDE QUESTO FILE ═══════════════════════════════════════════
 *
 * Che dal link si **paghi davvero**: che la sessione di checkout parta col
 * prezzo del piano cucito, che il webhook accenda l'abbonamento **con gli id di
 * Stripe dentro**, e che il link non si possa riusare, inoltrare o spendere
 * dopo la scadenza.
 */
final class OffertaSuMisuraTest extends TestCase
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

    /** Il piano cucito: 400 iscritti, 30 trainer, 890 € al mese. */
    private function pianoSuMisura(int $trainer = 30, int $iscritti = 400, array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'su_misura_prova',
            'name' => 'Su misura — prova',
            'kind' => PlanKind::Gym,
            'ai_enabled' => true,
            'max_members' => $iscritti,
            'max_trainers' => $trainer,
            'price_cents' => 89000,
            'is_public' => false,
            'cucito_a_mano' => true,
        ], $extra));
    }

    private function offerta(Plan $piano, array $extra = []): OffertaDiPiano
    {
        return OffertaDiPiano::create(array_merge([
            'plan_id' => $piano->getKey(),
            'token' => OffertaDiPiano::nuovoToken(),
        ], $extra));
    }

    /** @return array{0: Tenant, 1: User} la palestra, senza piano, e il titolare */
    private function palestraConTitolare(): array
    {
        $palestra = $this->creaPalestra();

        // ⚠️ Via l'abbonamento di serie: qui si prova come si **ottiene** un piano.
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->delete();

        return [$palestra->fresh(), $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test')];
    }

    // ═══════════════════════ il link porta al checkout ═══════════════════════

    /**
     * 🚨 **Il cuore della richiesta**: dal link si finisce su Stripe, col
     * prezzo del piano cucito e il token nei `metadata`.
     *
     * ⚠️ Il token nei metadata non è un dettaglio: è l'unico modo che ha il
     * webhook di sapere **quale offerta** si è consumata. Senza, lo stesso link
     * attiverebbe il piano una seconda volta.
     */
    #[Test]
    public function dal_link_si_paga_su_stripe(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $piano = $this->pianoSuMisura();
        $offerta = $this->offerta($piano, ['tenant_id' => $palestra->getKey()]);

        $this->actingAs($titolare)
            ->post("/offerta/{$offerta->token}")
            ->assertRedirect('https://checkout.stripe.test/finto/1');

        $sessione = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame('subscription', $sessione['mode']);
        $this->assertSame(89000, $sessione['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('su_misura_prova', $sessione['metadata']['piano']);
        $this->assertSame($offerta->token, $sessione['metadata']['offerta']);

        // ⚠️ Ancora nessun abbonamento: lo accende il webhook, a soldi arrivati.
        $this->assertSame(0, PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->count());
    }

    /**
     * 🚨 **Il webhook accende il piano e consuma l'offerta.**
     *
     * ⚠️ E l'abbonamento nasce **con gli id di Stripe dentro**: è la differenza
     * fra un cliente che paga ogni mese da solo e uno che non paga mai.
     */
    #[Test]
    public function il_webhook_accende_il_piano_e_brucia_il_link(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $piano = $this->pianoSuMisura();
        $offerta = $this->offerta($piano, ['tenant_id' => $palestra->getKey()]);

        app(Cassa::class)->applica([
            'id' => 'evt_offerta',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_offerta',
                'mode' => 'subscription',
                'subscription' => 'sub_su_misura',
                'customer' => 'cus_su_misura',
                'metadata' => [
                    'tipo' => PianiInVendita::TIPO,
                    'piano' => $piano->code,
                    'user_id' => (string) $titolare->getKey(),
                    'tenant_id' => (string) $palestra->getKey(),
                    'offerta' => $offerta->token,
                ],
            ]],
        ]);

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->sole();

        $this->assertSame($piano->getKey(), $riga->plan_id);
        $this->assertSame('sub_su_misura', $riga->stripe_subscription_id, 'il piano è nato senza Stripe dietro');
        $this->assertSame('cus_su_misura', $riga->stripe_customer_id);

        $this->assertNotNull($offerta->refresh()->accettata_il, 'il link è ancora spendibile dopo il pagamento');
        $this->assertFalse($offerta->eValida());
    }

    /**
     * 🚨 **Chi ha già un canone pagato su Stripe non ricompra: cambia.**
     *
     * ⛔ Un secondo checkout sarebbe un **secondo** abbonamento su Stripe, e il
     * primo continuerebbe a rinnovarsi: due addebiti al mese per un servizio
     * solo, e nessun errore da nessuna parte.
     *
     * 💡 Qui l'offerta si consuma subito: non c'è nessun webhook in arrivo,
     * perché non c'è nessun pagamento nuovo da aspettare.
     */
    #[Test]
    public function chi_paga_gia_un_canone_lo_cambia_col_conguaglio(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => Plan::query()->where('code', 'gym_10')->sole()->getKey(),
            'stripe_subscription_id' => 'sub_esistente',
            'stripe_customer_id' => 'cus_esistente',
            'rinnova' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);

        $piano = $this->pianoSuMisura();
        $offerta = $this->offerta($piano, ['tenant_id' => $palestra->getKey()]);

        $dove = app(PianiInVendita::class)->attivaOfferta($titolare->fresh(), $offerta);

        // ⚠️ `null` = non c'era niente da aprire: è già fatto.
        $this->assertNull($dove);

        $this->assertSame([], $this->stripe->chiamateA('checkout.sessions.create'), 'ha aperto un secondo checkout');

        $aggiornamento = $this->stripe->ultima('subscriptions.update');

        $this->assertSame('sub_esistente', $aggiornamento[0]);
        $this->assertSame(89000, $aggiornamento[1]['items'][0]['price_data']['unit_amount']);
        $this->assertSame('create_prorations', $aggiornamento[1]['proration_behavior']);

        $this->assertSame(
            $piano->getKey(),
            PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->getKey())->sole()->plan_id,
        );

        $this->assertNotNull($offerta->refresh()->accettata_il);
    }

    // ═══════════════════════ quello che il link NON fa ═══════════════════════

    /**
     * 🚨 **Il piano su misura resta fuori listino.**
     *
     * ⛔ Metterlo fra i `pubblici()` per poterlo vendere vorrebbe dire offrire a
     * chiunque il prezzo cucito per uno. È il **token** ad autorizzare, non la
     * visibilità del piano.
     */
    #[Test]
    public function senza_il_link_quel_piano_non_si_compra(): void
    {
        [, $titolare] = $this->palestraConTitolare();

        $this->pianoSuMisura();

        $this->expectException(InvalidArgumentException::class);

        app(PianiInVendita::class)->apri($titolare, 'su_misura_prova');
    }

    /** ⛔ E nemmeno dal modulo d'iscrizione del sito. */
    #[Test]
    public function e_non_si_compra_nemmeno_iscrivendosi_dal_listino(): void
    {
        $this->pianoSuMisura();

        $this->assertNull(app(IscrizioneDalSito::class)->pianoComprabile('su_misura_prova'));

        $this->get('/iscriviti/su_misura_prova')->assertNotFound();
    }

    /** ⛔ Un link già usato non vale più: il piano è già attivo. */
    #[Test]
    public function un_link_gia_usato_non_vale_piu(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $offerta = $this->offerta($this->pianoSuMisura(), [
            'tenant_id' => $palestra->getKey(),
        ]);

        $offerta->segnaAccettata();

        $this->actingAs($titolare)
            ->post("/offerta/{$offerta->token}")
            ->assertSessionHas('avviso');

        $this->assertSame([], $this->stripe->chiamateA('checkout.sessions.create'));
    }

    /** ⛔ E nemmeno uno scaduto: un prezzo concordato non vale per sempre. */
    #[Test]
    public function un_link_scaduto_non_vale_piu(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $offerta = $this->offerta($this->pianoSuMisura(), [
            'tenant_id' => $palestra->getKey(),
            'scade_il' => now()->subDay(),
        ]);

        $this->actingAs($titolare)
            ->post("/offerta/{$offerta->token}")
            ->assertSessionHas('avviso');

        $this->assertSame([], $this->stripe->chiamateA('checkout.sessions.create'));
    }

    /**
     * 🚨 **Un'offerta intestata non funziona per un altro cliente.**
     *
     * ⛔ Senza questo controllo, il link concordato con una palestra
     * funzionerebbe per qualunque altra a cui venisse inoltrato — col prezzo
     * della prima.
     */
    #[Test]
    public function un_link_intestato_non_si_gira_a_un_altro(): void
    {
        [$palestra] = $this->palestraConTitolare();

        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');
        $suoTitolare = $this->creaUtente($altra, UserRole::GymAdmin, 'altro@esempio.test');

        $offerta = $this->offerta($this->pianoSuMisura(), ['tenant_id' => $palestra->getKey()]);

        $this->expectException(InvalidArgumentException::class);

        app(PianiInVendita::class)->attivaOfferta($suoTitolare->fresh(), $offerta);
    }

    /**
     * 💡 **La pagina si apre e ripete i numeri dell'accordo.**
     *
     * ⚠️ Il prezzo e i posti si rileggono **sul link**, non solo nella mail
     * in cui sono stati concordati: un pulsante «attiva» senza le cifre accanto
     * è un pulsante che nessuno preme.
     */
    #[Test]
    public function la_pagina_dell_offerta_mostra_cosa_si_sta_comprando(): void
    {
        [$palestra, $titolare] = $this->palestraConTitolare();

        $offerta = $this->offerta($this->pianoSuMisura(trainer: 30, iscritti: 400), [
            'tenant_id' => $palestra->getKey(),
        ]);

        $this->actingAs($titolare)
            ->get("/offerta/{$offerta->token}")
            ->assertOk()
            ->assertSee('890,00 €', escape: false)
            ->assertSee('400 iscritti', escape: false)
            ->assertSee('30 trainer', escape: false)
            ->assertSee('Vai al pagamento', escape: false);
    }

    /** 🚨 Un token inventato non riceve una pagina: riceve un 404. */
    #[Test]
    public function un_token_inventato_non_esiste(): void
    {
        $this->get('/offerta/'.str_repeat('a', 32))->assertNotFound();
    }

    // ═══════════════════════ chi non è ancora cliente ════════════════════════

    /**
     * 💡 **Un'offerta senza intestatario fa nascere lo spazio**, come
     * `/iscriviti`: chi ha scritto dal modulo di contatto non ha ancora un
     * account, e mandarlo prima a iscriversi al listino gli farebbe comprare il
     * piano sbagliato.
     */
    #[Test]
    public function dal_link_ci_si_puo_anche_iscrivere(): void
    {
        $piano = $this->pianoSuMisura();
        $offerta = $this->offerta($piano, ['email' => 'capo@palestranuova.test']);

        $this->post("/offerta/{$offerta->token}", [
            'attivita' => 'Palestra Nuova',
            'nome' => 'Giulia Ferri',
            'email' => 'capo@palestranuova.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accetto' => '1',
        ])->assertRedirect('https://checkout.stripe.test/finto/1');

        $utente = User::withoutGlobalScopes()->where('email', 'capo@palestranuova.test')->sole();

        $this->assertNotNull($utente->tenant_id);
        $this->assertSame($offerta->token, $this->stripe->ultima('checkout.sessions.create')[0]['metadata']['offerta']);
    }

    /**
     * ⛔ **Su un'offerta intestata non si crea nessun account.**
     *
     * 🚨 Quel cliente esiste già: un pulsante che fa nascere un secondo spazio
     * con lo stesso nome è il difetto che si scopre quando le fatture sono due.
     */
    #[Test]
    public function un_link_intestato_non_fa_nascere_un_doppione(): void
    {
        [$palestra] = $this->palestraConTitolare();

        $offerta = $this->offerta($this->pianoSuMisura(), ['tenant_id' => $palestra->getKey()]);

        $this->post("/offerta/{$offerta->token}", [
            'attivita' => 'Palestra Fotocopia',
            'nome' => 'Chi Passava',
            'email' => 'passante@esempio.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accetto' => '1',
        ])->assertRedirect('/admin/login');

        $this->assertFalse(
            User::withoutGlobalScopes()->where('email', 'passante@esempio.test')->exists(),
            'ha creato un account da un link intestato a un altro',
        );
    }
}
