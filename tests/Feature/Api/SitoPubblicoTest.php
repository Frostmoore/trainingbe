<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il sito pubblico — F9 della Parte B.
 *
 * ⚠️ **È l'unica cosa di tutto il piano che non è né app né pannello.** Fino al
 * 13/08/2026 l'indirizzo principale del prodotto mostrava `view('welcome')` —
 * la pagina di benvenuto di Laravel, mai toccata, con il logo del framework.
 */
class SitoPubblicoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    #[Test]
    public function the_home_page_speaks_to_the_three_audiences(): void
    {
        $r = $this->get('/')->assertOk();

        // F9.1 — tre percorsi: persone, trainer indipendenti, palestre.
        $r->assertSee('Per chi si allena')
            ->assertSee('Per i trainer indipendenti')
            ->assertSee('Per le palestre');

        $r->assertDontSee('Laravel', escape: false);
    }

    /**
     * 🚨 **Il listino viene dal database, non dal template.**
     *
     * Un prezzo scritto in una vista è un prezzo che un giorno dirà una cosa
     * diversa da quella che il sistema fattura — e lo si scopre da un cliente
     * arrabbiato, non da un test.
     */
    #[Test]
    public function the_prices_come_from_the_database(): void
    {
        Plan::query()->where('code', Plan::PLUS)->update(['price_cents' => 777]);

        $this->get('/')->assertOk()->assertSee('7,77');
    }

    /**
     * 💡 **E il listino dice la verità sull'AI.**
     *
     * `ai_enabled` è la stessa colonna che il cancello `RequirePlanWithAi` legge
     * per rispondere `403`. Se il sito la scrivesse a mano, il giorno in cui i
     * due divergessero il prodotto prometterebbe una cosa e ne farebbe un'altra.
     */
    #[Test]
    public function the_page_tells_the_truth_about_the_ai(): void
    {
        $this->get('/')->assertOk()->assertSee(// ⚠️ Senza l'apostrofo: Blade lo scrive come `&#039;`, e cercarlo in
            // chiaro farebbe fallire un test che sta verificando tutt'altro.
            'Niente funzioni con l', escape: false);

        // ⚠️ **Tutti** i piani, non solo il gratuito: la frase compare anche
        // sul «Trainer gratuito», e accendendo l'AI solo al primo il test
        // fallirebbe per un piano che non stava verificando.
        Plan::query()->update(['ai_enabled' => true]);

        $this->get('/')->assertOk()->assertDontSee(// ⚠️ Senza l'apostrofo: Blade lo scrive come `&#039;`, e cercarlo in
            // chiaro farebbe fallire un test che sta verificando tutt'altro.
            'Niente funzioni con l', escape: false);
    }

    /**
     * 🚨 **I numeri della quota vengono dalle stesse colonne del cancello** — G10.2.
     *
     * ⚠️ È la stessa classe di errore del prezzo, un piano più in là: «quota AI
     * inclusa» non è una promessa verificabile, **«400 richieste al mese» sì** —
     * e il giorno in cui il listino dicesse 400 e `MemberAiQuota` ne concedesse
     * 200, il cliente lo scoprirebbe alla duecentounesima.
     */
    #[Test]
    public function the_price_list_says_how_many_ai_calls_are_included(): void
    {
        Plan::query()->where('kind', PlanKind::Gym->value)->update([
            'ai_enabled' => true,
            'ai_monthly_calls_per_member' => 373,
            'ai_monthly_photo_calls_per_member' => 41,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('373')
            ->assertSee('41');
    }

    /**
     * 💡 **I due tetti di una palestra sono due, e si moltiplicano** — D5.
     *
     * Chi legge solo «fino a 10 trainer» si fa il conto sbagliato: quello che
     * compra sono 10 × il tetto per trainer.
     */
    #[Test]
    public function a_gym_sees_both_of_its_caps(): void
    {
        Plan::query()->where('kind', PlanKind::Gym->value)->update([
            'max_trainers' => 7,
            'max_members_per_trainer' => 23,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Fino a 7 trainer')
            ->assertSee('Fino a 23 iscritti per trainer');
    }

    /**
     * 🚨 **Il sito non promette una funzione che non esiste più** — 14/08/2026.
     *
     * ⚠️ Diceva «li assegni dal telefono», e dal 14/08 dal pannello **non si
     * assegna più niente**: un programma si consegna via chat cifrata (D4). Una
     * promessa che il prodotto non mantiene è peggio di una funzione mancante,
     * perché si scopre dopo aver pagato.
     */
    #[Test]
    public function the_site_does_not_promise_assignment(): void
    {
        $r = $this->get('/')->assertOk();

        $r->assertDontSee('li assegni');

        // 💡 E dice la cosa che al trainer interessa davvero sapere prima di
        // pagare: R8 — un programma consegnato non gli si può togliere.
        $r->assertSee('non spariscono', escape: false);
    }

    // ─────────────────── L'atterraggio di un invito — F6.2 ───────────────────

    #[Test]
    public function a_valid_invite_says_who_invited_you(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna Trainer', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $invito = app(InvitiDelTrainer::class)->invita($trainer);

        $this->get('/invito/'.$invito->token)
            ->assertOk()
            ->assertSee('Anna Trainer');
    }

    /**
     * 🚨 **Un `GET` non consuma l'invito.**
     *
     * ⚠️ I servizi di messaggistica aprono i link da soli per mostrare
     * l'anteprima: un `GET` che riscattasse brucerebbe l'invito **prima** che il
     * destinatario lo abbia nemmeno visto.
     */
    #[Test]
    public function opening_the_link_does_not_burn_the_invite(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $invito = app(InvitiDelTrainer::class)->invita($trainer);

        $this->get('/invito/'.$invito->token)->assertOk();
        $this->get('/invito/'.$invito->token)->assertOk();

        $this->assertTrue($invito->fresh()->eValido());
    }

    /** ⚠️ Un solo messaggio per scaduto, usato, revocato e inesistente. */
    #[Test]
    public function every_dead_invite_says_the_same_thing(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $scaduto = app(InvitiDelTrainer::class)->invita($trainer);
        $scaduto->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get('/invito/'.$scaduto->token)
            ->assertOk()
            ->assertSee('non è più valido', escape: false)
            // 🚨 E non dice chi aveva invitato: un invito morto non deve
            // continuare a rivelare il nome di chi l'ha mandato.
            ->assertDontSee('Anna');

        $this->get('/invito/'.str_repeat('z', 32))
            ->assertOk()
            ->assertSee('non è più valido', escape: false);
    }

    /** Un token malformato non arriva nemmeno al controller. */
    #[Test]
    public function a_malformed_token_is_not_a_route(): void
    {
        $this->get('/invito/troppo-corto')->assertNotFound();
    }
}
