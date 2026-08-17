<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\StripeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * L'orecchio di Stripe — 17/08/2026.
 *
 * ── 📌 Cosa NON c'e' ancora ────────────────────────────────────────────────
 *
 * Nessun flusso: non si vende niente, non si accredita niente. Il committente
 * ha chiesto **solo l'impianto**, e questo file prova l'impianto.
 *
 * 🚨 **Nessun test qui tocca la rete.** Nel `.env` di questa macchina ci sono
 * chiavi **live**: la verifica della firma e' un calcolo locale, e la firma dei
 * finti eventi la produciamo noi con un segreto di prova. Se un giorno un test
 * di questo file cominciasse a chiamare l'API di Stripe, muoverebbe soldi veri.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Un segreto di prova: non e' quello vero e non deve esserlo. */
    private const SEGRETO = 'whsec_prova_non_e_un_segreto_vero';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.webhook_secret', self::SEGRETO);
    }

    /**
     * 🚨 **Senza segreto configurato si chiude, non si passa.**
     *
     * ⚠️ La tentazione, quando manca una configurazione, e' saltare il
     * controllo «tanto in sviluppo non serve». Qui vorrebbe dire lasciare
     * aperto un indirizzo pubblico che un giorno accreditera' denaro: chiunque
     * potrebbe inventarsi un pagamento riuscito mandando un JSON.
     */
    #[Test]
    public function without_a_configured_secret_nothing_is_accepted(): void
    {
        config()->set('services.stripe.webhook_secret', null);

        $this->postJson('/stripe/webhook', ['type' => 'checkout.session.completed'])
            ->assertStatus(503);

        $this->assertSame(0, StripeEvent::query()->count());
    }

    /**
     * 🚨 **La firma e' l'unica cosa che autentica un webhook.**
     *
     * Non c'e' sessione, non c'e' utente, non c'e' cookie: chi manda questa
     * richiesta e' un server. ⚠️ Senza la verifica, l'indirizzo sarebbe un modo
     * per far credere al sistema che un pagamento e' andato a buon fine.
     */
    #[Test]
    public function an_unsigned_event_is_refused(): void
    {
        $this->mandaGrezzo(
            json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed']),
            null,
        )->assertStatus(400);

        $this->assertSame(0, StripeEvent::query()->count());
    }

    /** ⚠️ E nemmeno una firma **inventata**, che e' il caso vero di un attacco. */
    #[Test]
    public function a_forged_signature_is_refused(): void
    {
        $corpo = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed']);

        $this->mandaGrezzo($corpo, 't='.time().',v1='.str_repeat('a', 64))
            ->assertStatus(400);

        $this->assertSame(0, StripeEvent::query()->count());
    }

    /**
     * 🚨 **Una firma vecchia non vale.**
     *
     * ⚠️ Senza la finestra temporale, chi intercetta una richiesta valida una
     * volta puo' rigiocarla per sempre: la firma resterebbe corretta.
     */
    #[Test]
    public function a_signature_from_last_week_is_refused(): void
    {
        $corpo = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed']);

        $this->mandaGrezzo($corpo, $this->firma($corpo, time() - 604800))
            ->assertStatus(400);

        $this->assertSame(0, StripeEvent::query()->count());
    }

    #[Test]
    public function a_properly_signed_event_is_recorded(): void
    {
        $this->mandaEvento('evt_123', 'checkout.session.completed')->assertOk();

        $riga = StripeEvent::query()->sole();

        $this->assertSame('evt_123', $riga->event_id);
        $this->assertSame('checkout.session.completed', $riga->type);

        // 💡 Il corpo si conserva per intero: il giorno in cui un pagamento
        // viene contestato, quello che conta e' cosa ha detto Stripe.
        $this->assertSame('evt_123', $riga->payload['id']);

        // 📌 Nessun flusso attaccato ancora: l'evento e' registrato e **non
        // lavorato**, e le due cose devono restare distinguibili.
        $this->assertNull($riga->processed_at);
    }

    /**
     * 🚨 **Lo stesso evento due volte si registra una volta sola.**
     *
     * ── Perche' questo test vale piu' degli altri ─────────────────────────
     *
     * Stripe **rimanda** lo stesso evento quando la nostra risposta tarda, o
     * torna un errore, o si perde per strada. Non e' un guasto: e' il
     * contratto.
     *
     * ⚠️ Il giorno in cui a questo controller si attacchera' l'accredito dei
     * gettoni, senza questa difesa **ogni ritentativo accrediterebbe di nuovo**
     * — e nessuno se ne accorgerebbe, perche' il cliente e' contento e il saldo
     * cresce. Lo si scoprirebbe dal bilancio.
     *
     * 💡 E la seconda volta si risponde `200`: a Stripe va detto «ce l'ho», o
     * continua a riprovare per ore.
     */
    #[Test]
    public function the_same_event_twice_is_stored_once(): void
    {
        $this->mandaEvento('evt_ripetuto', 'checkout.session.completed')->assertOk();
        $this->mandaEvento('evt_ripetuto', 'checkout.session.completed')->assertOk();

        $this->assertSame(1, StripeEvent::query()->count());
    }

    /** 💡 Due eventi diversi restano due. */
    #[Test]
    public function two_different_events_are_two_rows(): void
    {
        $this->mandaEvento('evt_uno', 'checkout.session.completed')->assertOk();
        $this->mandaEvento('evt_due', 'payment_intent.succeeded')->assertOk();

        $this->assertSame(2, StripeEvent::query()->count());
    }

    // ───────────────────────── aiutanti ─────────────────────────

    private function mandaEvento(string $id, string $tipo): TestResponse
    {
        $corpo = json_encode([
            'id' => $id,
            'object' => 'event',
            'type' => $tipo,
            'created' => time(),
            'data' => ['object' => ['id' => 'cs_prova']],
        ]);

        return $this->mandaGrezzo($corpo, $this->firma($corpo, time()));
    }

    private function mandaGrezzo(string $corpo, ?string $firma): TestResponse
    {
        $intestazioni = ['CONTENT_TYPE' => 'application/json'];

        if ($firma !== null) {
            $intestazioni['HTTP_STRIPE_SIGNATURE'] = $firma;
        }

        return $this->call('POST', '/stripe/webhook', server: $intestazioni, content: $corpo);
    }

    /**
     * La firma come la calcola Stripe: `HMAC-SHA256` su «istante.corpo».
     *
     * 💡 Si ricostruisce qui invece di sostituire la libreria con un doppio:
     * cosi' il test prova la **verifica vera**, non un finto che risponde di si'.
     */
    private function firma(string $corpo, int $istante): string
    {
        $firma = hash_hmac('sha256', "{$istante}.{$corpo}", self::SEGRETO);

        return "t={$istante},v1={$firma}";
    }
}
