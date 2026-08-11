<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * S9.1 / S9.2 — consensi e maggiore età.
 *
 * 🎯 **Quello che questi test presidiano non è una funzione: è una base
 * giuridica.** Se saltano, il trattamento resta tecnicamente funzionante e
 * giuridicamente scoperto — che è il modo peggiore di essere rotti, perché
 * nessuno se ne accorge finché non arriva qualcuno a chiedere.
 */
class ConsentApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    // ───────────────────── lo sbarramento dei maggiorenni ─────────────────────

    /**
     * 🚨 Senza la dichiarazione non ci si iscrive.
     *
     * ⚠️ **`accepted` e non `boolean`**: un `false` deve **fallire**, non
     * essere registrato come «ha detto di no e comunque entra».
     */
    #[Test]
    public function there_is_no_registration_without_declaring_being_an_adult(): void
    {
        $this->postJson('/api/v1/auth/register', $this->iscrizione(['age_confirmed' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['age_confirmed']);

        $this->postJson('/api/v1/auth/register', $this->iscrizione(exclude: 'age_confirmed'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['age_confirmed']);

        $this->assertNull(User::where('email', 'nuovo@alfa.test')->first());
    }

    #[Test]
    public function there_is_no_registration_without_accepting_the_terms(): void
    {
        $this->postJson('/api/v1/auth/register', $this->iscrizione(['terms_accepted' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms_accepted']);
    }

    /**
     * 🚨 **La dichiarazione si conserva con la data**, non come un booleano.
     *
     * L'art. 7(1) chiede di poter *dimostrare* che è stata data: un `true`
     * senza data non dice quando, quindi non dice nemmeno a quale versione
     * dell'informativa si riferisse.
     */
    #[Test]
    public function the_declaration_is_kept_with_its_moment(): void
    {
        $this->postJson('/api/v1/auth/register', $this->iscrizione())->assertCreated();

        $nuovo = User::withoutGlobalScopes()->where('email', 'nuovo@alfa.test')->firstOrFail();

        $this->assertNotNull($nuovo->age_confirmed_at);
        $this->assertNotNull($nuovo->terms_accepted_at);
        $this->assertTrue($nuovo->haDichiaratoMaggioreEta());
    }

    /**
     * ⚠️ **I consensi facoltativi NON si danno all'iscrizione.**
     *
     * Un consenso richiesto per potersi iscrivere non è «liberamente dato»
     * (art. 7(4)), e quindi non è consenso. Chi si registra parte con **tutti e
     * due a `null`**, e l'app funziona lo stesso.
     */
    #[Test]
    public function signing_up_grants_no_optional_consent(): void
    {
        $this->postJson('/api/v1/auth/register', $this->iscrizione())->assertCreated();

        $nuovo = User::withoutGlobalScopes()->where('email', 'nuovo@alfa.test')->firstOrFail();

        $this->assertNull($nuovo->health_consent_at);
        $this->assertNull($nuovo->ai_consent_at);
    }

    // ───────────────────────── i consensi ─────────────────────────

    #[Test]
    public function consents_start_empty_and_can_be_granted_one_at_a_time(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/account/consents')
            ->assertOk()
            ->assertJsonPath('data.health', null)
            ->assertJsonPath('data.ai', null);

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/consents', ['health' => true])
            ->assertOk()
            ->assertJsonPath('data.ai', null);

        $this->assertNotNull($this->iscritto->refresh()->health_consent_at);
        $this->assertNull($this->iscritto->ai_consent_at);
    }

    /**
     * 🚨 **Revocare costa esattamente quanto concedere** (art. 7(3)).
     *
     * Stessa chiamata, stesso campo, `false` invece di `true`. Un consenso che
     * si dà con un tocco e si toglie scrivendo un'email non è liberamente
     * revocabile — e quindi, a rigore, non è mai stato valido.
     */
    #[Test]
    public function revoking_costs_exactly_as_much_as_granting(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertOk();

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/consents', ['ai' => false])
            ->assertOk()
            ->assertJsonPath('data.ai', null);

        $this->assertNull($this->iscritto->refresh()->ai_consent_at);
    }

    /**
     * ⚠️ Da qui non si tocca né la maggiore età né le condizioni d'uso.
     *
     * Non sono consensi revocabili: revocare le condizioni d'uso significa
     * cancellare l'account, che è un'altra porta e un'altra conferma.
     */
    #[Test]
    public function the_age_declaration_cannot_be_rewritten_from_here(): void
    {
        $this->iscritto->forceFill(['age_confirmed_at' => now()])->save();
        $quando = $this->iscritto->refresh()->age_confirmed_at;

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/consents', [
                'age_confirmed_at' => null,
                'terms_accepted_at' => null,
            ])
            ->assertOk();

        $this->assertEquals($quando, $this->iscritto->refresh()->age_confirmed_at);
    }

    // ───────────────────── il cancello sull'AI ─────────────────────

    /**
     * 🚨 **Il test che tiene in piedi la base giuridica del trasferimento.**
     *
     * Quello che parte verso Anthropic non torna indietro: è un trasferimento a
     * un terzo fuori dall'Unione, e da ciò che una persona mangia si inferisce
     * il suo stato di salute (CGUE C-184/20). Senza consenso esplicito non ha
     * **nessuna** base giuridica.
     */
    #[Test]
    public function without_consent_nothing_leaves_towards_the_ai(): void
    {
        $this->aiFinta();

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/ai/advice')
            ->assertForbidden()
            ->assertJsonPath('code', 'ai_consent_required');

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ai_consent_required');
    }

    /**
     * 💡 **403 con un codice, non 401.** L'app deve distinguere «rifai
     * l'accesso» da «serve il consenso»: un 401 la manderebbe al login, dove la
     * persona rifarebbe l'accesso e ritroverebbe lo stesso errore.
     */
    #[Test]
    public function granting_consent_opens_the_ai(): void
    {
        $this->aiFinta();

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertOk();

        $this->comeApp($this->iscritto->refresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova'])
            ->assertSuccessful();
    }

    /**
     * 🚨 **E revocare richiude la porta subito**, non al prossimo accesso.
     */
    #[Test]
    public function revoking_closes_the_ai_again(): void
    {
        $this->aiFinta();

        $this->comeApp($this->iscritto)->patchJson('/api/v1/account/consents', ['ai' => true]);
        $this->comeApp($this->iscritto)->patchJson('/api/v1/account/consents', ['ai' => false]);

        $this->comeApp($this->iscritto->refresh())
            ->getJson('/api/v1/ai/advice')
            ->assertForbidden();
    }

    // ───────────────────────── interni ─────────────────────────

    /** @return array<string, mixed> */
    private function iscrizione(array $sovrascrivi = [], ?string $exclude = null): array
    {
        $dati = [
            'join_code' => 'ALFA2345',
            'name' => 'Nuovo Iscritto',
            'email' => 'nuovo@alfa.test',
            'username' => 'nuovoiscritto',
            'password' => TestCase::FAKE_PASSWORD,
            'password_confirmation' => TestCase::FAKE_PASSWORD,
            'age_confirmed' => true,
            'terms_accepted' => true,
            ...$sovrascrivi,
        ];

        if ($exclude !== null) {
            unset($dati[$exclude]);
        }

        return $dati;
    }
}
