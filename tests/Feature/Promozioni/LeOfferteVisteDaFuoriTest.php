<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\OrigineAssegnazione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiCondizione;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Jobs\AvvisaLaPromozioneATutti;
use App\Mail\PromozionePerTeMail;
use App\Models\PacchettoGettoni;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozioneCondizione;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Push\PushSender;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 📣 **Le promozioni viste da fuori: app, avvisi, sito, codice al pagamento** —
 * 24/09/2026, P8 di `plan_promozioni.md` (opzioni 29, 37, 38).
 */
final class LeOfferteVisteDaFuoriTest extends TestCase
{
    use ChiamaComeApp;
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

    /** @param  list<array<string, mixed>>  $premi */
    private function promo(array $premi, array $extra = []): Promozione
    {
        $promozione = Promozione::query()->create(array_merge([
            'codice' => Promozione::nuovoCodice(),
            'nome' => 'Per te',
            'genere' => GenereDiPromozione::Gettoni,
            'attivazione' => ComeSiAttiva::Automatica,
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
        return app(CreaTenantPersonale::class)('Tizio Rossi', $email, ['password' => self::FAKE_PASSWORD])->fresh();
    }

    private function sconto(): array
    {
        return ['tipo' => TipoDiPremio::ScontoGettoni, 'tipo_sconto' => TipoDiSconto::Percentuale, 'valore' => 20];
    }

    // ═════════════════════════ l'app ═════════════════════════

    /** 👀 **Nell'app si vedono solo quelle che lo dicono**, e che sono per me. */
    #[Test]
    public function l_app_vede_le_offerte_visibili_e_sue(): void
    {
        $tizio = $this->libero();
        $visibile = $this->promo([$this->sconto()], ['nome' => 'Visibile', 'visibile_in' => ['app']]);
        $this->promo([$this->sconto()], ['nome' => 'Nascosta']);
        $mirata = $this->promo([$this->sconto()], ['nome' => 'Mirata', 'visibile_in' => ['app']]);
        PromozioneCondizione::query()->create(['promozione_id' => $mirata->getKey(), 'tipo' => TipoDiCondizione::GettoniComprati, 'parametri' => ['gettoni' => 500]]);

        $risposta = $this->comeApp($tizio)->getJson('/api/v1/promozioni')->assertOk();

        $this->assertSame([$visibile->codice], array_column($risposta->json('data'), 'codice'));
        $this->assertTrue($risposta->json('data.0.si_usa_pagando'));
    }

    /** 🔗 **L'anteprima di un link** aperto nell'app: vale, o dice perché no. */
    #[Test]
    public function l_anteprima_del_link(): void
    {
        $tizio = $this->libero();
        $promozione = $this->promo([$this->sconto()], ['attivazione' => ComeSiAttiva::Link]);

        $this->comeApp($tizio)->getJson("/api/v1/promozioni/{$promozione->codice}")
            ->assertOk()
            ->assertJsonPath('data.codice', $promozione->codice)
            ->assertJsonPath('data.attivabile', true);

        $this->comeApp($tizio)->getJson('/api/v1/promozioni/NONESISTE')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Questo link non porta a nessuna offerta.');
    }

    /** 🎁 **Attivare un regalo dall'app**, una volta sola. */
    #[Test]
    public function l_attivazione_di_un_regalo(): void
    {
        $tizio = $this->libero();
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 15]], ['attivazione' => ComeSiAttiva::Link]);

        $this->comeApp($tizio)->postJson("/api/v1/promozioni/{$promozione->codice}/attiva")
            ->assertCreated()
            ->assertJsonPath('data.gettoni', 15)
            ->assertJsonPath('data.saldo', 15);

        $this->comeApp($tizio)->postJson("/api/v1/promozioni/{$promozione->codice}/attiva")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hai già ricevuto questa offerta.');
    }

    /**
     * ⛔ **Niente codici sconto**: uno sconto col link si **attiva**, e poi il
     * pagamento lo usa da solo. Prima dell'attivazione non vale; il checkout
     * non accetta nessun codice.
     */
    #[Test]
    public function lo_sconto_col_link_si_attiva_e_si_applica_da_solo(): void
    {
        $tizio = $this->libero();
        $pacchetto = PacchettoGettoni::query()->inVendita()->orderByDesc('gettoni')->first();
        $promozione = $this->promo([$this->sconto()], ['attivazione' => ComeSiAttiva::Link]);

        // Un codice nel corpo del checkout non conta più: nessuno sconto.
        $this->comeApp($tizio)->postJson('/api/v1/billing/checkout', [
            'tipo' => 'gettoni',
            'gettoni' => $pacchetto->gettoni,
            'promozione' => $promozione->codice,
        ])->assertOk();
        $this->assertSame([], $this->stripe->chiamateA('coupons.create'));

        $this->comeApp($tizio)->postJson("/api/v1/promozioni/{$promozione->codice}/attiva")
            ->assertCreated()
            ->assertJsonPath('data.sconto_pronto', true);

        $this->comeApp($tizio)->postJson('/api/v1/billing/checkout', ['tipo' => 'gettoni', 'gettoni' => $pacchetto->gettoni])->assertOk();

        $this->assertSame((int) round($pacchetto->prezzo_cent * 0.2), $this->stripe->ultima('coupons.create')[0]['amount_off']);
    }

    /** 🔗 La pagina `/promo/{codice}` del sito: cos'è e come attivarla, senza attivare niente. */
    #[Test]
    public function la_pagina_del_link_sul_sito(): void
    {
        $promozione = $this->promo([$this->sconto()], ['nome' => 'Autunno', 'attivazione' => ComeSiAttiva::Link]);

        $this->get("/promo/{$promozione->codice}")->assertOk()->assertSee('Autunno')->assertSee('Attivala dal pannello');
        $this->get('/promo/NONESISTE')->assertOk()->assertSee('non è più disponibile');
        $this->assertSame(0, $promozione->fresh()->usata_volte);
    }

    // ═════════════════════════ gli avvisi ═════════════════════════

    /** 📣 **Un regalo arrivato avvisa** coi canali scelti. Opzione 37. */
    #[Test]
    public function il_regalo_avvisa_con_mail_e_notifica(): void
    {
        Mail::fake();
        $push = Mockery::spy(PushSender::class);
        $this->app->instance(PushSender::class, $push);

        $tizio = $this->libero();
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 10]], [
            'attivazione' => ComeSiAttiva::Massa,
            'avviso' => ['app', 'email'],
        ]);

        app(ConsegnaDeiPremi::class)->consegna($promozione, $tizio, OrigineAssegnazione::Massa, 'm:1');

        Mail::assertSent(PromozionePerTeMail::class, fn (PromozionePerTeMail $m): bool => $m->hasTo('tizio@esempio.test') && $m->ricevuta);
        $push->shouldHaveReceived('toUser')->once();
    }

    /** 🤫 Senza canali, in silenzio. */
    #[Test]
    public function senza_canali_nessun_avviso(): void
    {
        Mail::fake();

        $tizio = $this->libero();
        $promozione = $this->promo([['tipo' => TipoDiPremio::GettoniRegalo, 'gettoni' => 10]], ['attivazione' => ComeSiAttiva::Massa]);

        app(ConsegnaDeiPremi::class)->consegna($promozione, $tizio, OrigineAssegnazione::Massa, 'm:1');

        Mail::assertNothingSent();
    }

    /** 📣 «Avvisa i destinatari» per uno sconto: a chi la promozione prende. */
    #[Test]
    public function avvisa_i_destinatari_di_uno_sconto(): void
    {
        Mail::fake();

        $this->libero('uno@esempio.test');
        $this->libero('due@esempio.test');
        $promozione = $this->promo([$this->sconto()], ['avviso' => ['email']]);

        AvvisaLaPromozioneATutti::dispatchSync($promozione->getKey());

        Mail::assertSent(PromozionePerTeMail::class, 2);
        Mail::assertSent(PromozionePerTeMail::class, fn (PromozionePerTeMail $m): bool => ! $m->ricevuta);
    }

    // ═════════════════════════ il sito ═════════════════════════

    /** 🤝 `/amico/{codice}`: il nome di chi invita, e il codice da scrivere. */
    #[Test]
    public function la_pagina_dell_amico(): void
    {
        $codice = $this->libero()->codiceAmico();

        $this->get("/amico/{$codice}")->assertOk()->assertSee('Tizio ti ha invitato')->assertSee($codice);
        $this->get('/amico/ZZZZZZZZ')->assertOk()->assertSee('Questo codice non esiste');
    }

    /** 🌐 `/offerte`: solo quelle per tutti, senza condizioni, e che dicono «sito». */
    #[Test]
    public function il_sito_mostra_solo_le_offerte_pubbliche(): void
    {
        $this->promo([$this->sconto()], ['nome' => 'Pubblica', 'visibile_in' => ['sito']]);
        $this->promo([$this->sconto()], ['nome' => 'Solo nell app', 'visibile_in' => ['app']]);
        $mirata = $this->promo([$this->sconto()], ['nome' => 'Mirata', 'visibile_in' => ['sito']]);
        PromozioneCondizione::query()->create(['promozione_id' => $mirata->getKey(), 'tipo' => TipoDiCondizione::MaiCompratoGettoni]);

        $this->get('/offerte')->assertOk()->assertSee('Pubblica')->assertDontSee('Solo nell app')->assertDontSee('Mirata');
    }
}
