<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\AiUsageLog;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\Quota\MemberAiQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * B6.6 — il layer AI.
 *
 * 🚨 **Nessuno di questi test tocca la rete.** Un test che chiama un modello
 * vero e' lento, costa, e fallisce quando il fornitore ha un disservizio — cioe'
 * proprio quando serve sapere se il *nostro* codice funziona. Qui si prova tutto
 * cio' che e' nostro: conteggio dei token, costo, quote, cache, isolamento.
 */
class AiApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $alfa;

    private Tenant $beta;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        // 🚨 S9.1 — senza consenso esplicito niente esce verso Anthropic, e
        // ogni rotta AI risponde 403. Qui lo si concede perché questi test
        // parlano d'altro; ⚠️ che il cancello funzioni è provato altrove, in
        // `ConsentApiTest`, e **deve** restare provato lì: se il consenso
        // arrivasse di serie da `creaUtente()`, il giorno in cui il cancello si
        // rompesse non se ne accorgerebbe nessuno.
        $this->iscritto->registraConsenso('ai_consent_at', true);
    }

    private function comeIscritto(?User $u = null): static
    {
        return $this->comeApp($u ?? $this->iscritto);
    }

    // ───────────────────────── cibo ─────────────────────────

    #[Test]
    public function it_turns_a_sentence_into_diary_entries(): void
    {
        $this->aiFinta()->willReturnFood(FoodEstimate::fromArray([
            'items' => [
                ['name' => 'Pasta', 'qty' => 80, 'unit' => 'g', 'grams' => 80, 'kcal' => 280, 'protein' => 10, 'carbs' => 56, 'fat' => 1],
                ['name' => 'Sugo di pomodoro', 'qty' => 100, 'unit' => 'g', 'grams' => 100, 'kcal' => 60, 'protein' => 2, 'carbs' => 8, 'fat' => 3],
            ],
            'confidence' => 0.85,
        ]));

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/text', ['text' => 'pasta al pomodoro', 'meal' => 'lunch'])
            ->assertCreated()
            ->assertJsonPath('data.saved', true)
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.description', 'Pasta')
            ->assertJsonPath('data.estimate.confidence', 0.85);

        $this->assertSame(2, FoodEntry::withoutGlobalScopes()->count());
        $this->assertSame('ai_text', FoodEntry::withoutGlobalScopes()->first()->source->value);
    }

    /**
     * `save: false` restituisce la stima senza scrivere.
     *
     * Serve all'app per far confermare una stima poco sicura invece di
     * registrarla in silenzio: una stima sbagliata accettata senza guardare si
     * scopre settimane dopo, quando i totali non tornano.
     */
    #[Test]
    public function it_can_estimate_without_saving(): void
    {
        $this->aiFinta();

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/text', ['text' => 'un panino', 'save' => false])
            ->assertOk()
            ->assertJsonPath('data.saved', false)
            ->assertJsonCount(0, 'data.entries');

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

    /**
     * 🚨 I grammi del modello vincono sulla tabella di `FoodUnit`.
     *
     * Un cucchiaio d'olio pesa 14 g, non 15: la tabella deterministica ne conosce
     * uno solo perche' non sa di che alimento si parla, il modello si'.
     */
    #[Test]
    public function the_grams_from_the_model_win_over_the_unit_table(): void
    {
        $this->aiFinta()->willReturnFood(FoodEstimate::fromArray([
            'items' => [['name' => 'Olio EVO', 'qty' => 1, 'unit' => 'cucchiaio', 'grams' => 14, 'kcal' => 124]],
            'confidence' => 0.9,
        ]));

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/text', ['text' => 'un cucchiaio d\'olio'])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.grams', 14.0);
    }

    // ───────────────────────── metering ─────────────────────────

    #[Test]
    public function every_call_is_metered(): void
    {
        $this->aiFinta();

        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'mela']);

        $riga = AiUsageLog::withoutGlobalScopes()->first();

        $this->assertNotNull($riga, 'Una chiamata AI non e\' finita nel contatore.');
        $this->assertSame($this->alfa->id, $riga->tenant_id);
        $this->assertSame($this->iscritto->id, $riga->user_id);
        $this->assertSame(AiFeature::FoodText, $riga->feature);
        $this->assertTrue($riga->success);
        $this->assertGreaterThan(0, $riga->billableTokens());
    }

    /**
     * 🚨 **Anche le chiamate fallite si contano.**
     *
     * Una richiesta rifiutata dopo aver consumato token di input e' comunque
     * costata: registrare solo i successi produce un totale che non corrisponde
     * alla fattura, e a quel punto nessuno si fida piu' del contatore.
     */
    #[Test]
    public function a_failed_call_is_metered_too(): void
    {
        $this->aiFinta()->willThrow(new AiUnavailableException);

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/text', ['text' => 'mela'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'ai_unavailable');

        $riga = AiUsageLog::withoutGlobalScopes()->first();

        $this->assertNotNull($riga);
        $this->assertFalse($riga->success);
        $this->assertSame('fake_error', $riga->error_code);
    }

    /**
     * Il costo si congela alla chiamata e non si ricalcola dai listini di oggi.
     */
    #[Test]
    public function it_computes_the_cost_from_the_price_list(): void
    {
        $recorder = app(AiUsageRecorder::class);

        // claude-haiku-4-5: 100.000 millesimi di centesimo per milione in
        // ingresso, 500.000 in uscita.
        // 1.000.000 in + 1.000.000 out = 100.000 + 500.000 = 600.000
        $this->assertSame(600_000, $recorder->costMillicents('claude-haiku-4-5', 1_000_000, 1_000_000));

        // La lettura dalla cache costa un decimo dell'input: e' il motivo per cui
        // il prompt caching e' il risparmio piu' grande della piattaforma.
        $this->assertSame(10_000, $recorder->costMillicents('claude-haiku-4-5', 0, 0, 1_000_000));

        // Un modello senza listino costa zero e non fa esplodere niente: uno
        // nuovo messo in `.env` deve funzionare subito.
        $this->assertSame(0, $recorder->costMillicents('modello-mai-visto', 1_000_000, 1_000_000));
    }

    /** Il contatore di una palestra non vede quello di un'altra. */
    #[Test]
    public function usage_is_isolated_between_gyms(): void
    {
        $this->aiFinta();

        $altroIscritto = $this->creaUtente($this->beta, UserRole::Member, 'anna@beta.test');
        $altroIscritto->registraConsenso('ai_consent_at', true);

        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'mela']);
        $this->comeIscritto($altroIscritto)->postJson('/api/v1/ai/food/text', ['text' => 'pera']);

        $this->assertSame(620, AiUsageLog::tokensForTenant($this->alfa->id));
        $this->assertSame(620, AiUsageLog::tokensForTenant($this->beta->id));
        $this->assertSame(2, AiUsageLog::withoutGlobalScopes()->count());
    }

    // ───────────────────────── quote ─────────────────────────

    /**
     * 🚨 **Il limite si controlla PRIMA della chiamata.**
     *
     * Controllarlo dopo vorrebbe dire aver gia' pagato i token che si sta
     * rifiutando di concedere.
     */
    #[Test]
    public function it_refuses_when_the_member_ran_out_of_tokens(): void
    {
        $finta = $this->aiFinta();

        $this->alfa->update(['ai_monthly_tokens_per_member' => 1000]);

        // Prima chiamata: 620 token, sotto il tetto.
        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'mela'])->assertCreated();

        // Seconda: si arriva a 1240, sopra.
        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'pera'])->assertCreated();

        $chiamateFinora = count($finta->calls);

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/text', ['text' => 'banana'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'ai_quota_exceeded')
            ->assertJsonStructure(['error', 'message', 'resets_at']);

        $this->assertSame(
            $chiamateFinora,
            count($finta->calls),
            'Il modello e\' stato chiamato lo stesso: la quota sta chiudendo la porta dopo aver pagato.',
        );
    }

    /**
     * `cap = 0` significa **illimitato esplicito**, `null` significa
     * «prendi il default di sistema». Sono due cose diverse.
     */
    #[Test]
    public function zero_means_unlimited_and_null_means_default(): void
    {
        $quota = app(MemberAiQuota::class);
        $iscritto = $this->iscritto->refresh();

        $this->alfa->update(['ai_monthly_tokens_per_member' => 0]);
        $this->assertNull($quota->capFor($iscritto->refresh()));
        $this->assertNull($quota->remaining($iscritto));
        $this->assertNull($quota->usedPercent($iscritto));

        $this->alfa->update(['ai_monthly_tokens_per_member' => null]);
        $this->assertSame(
            (int) config('ai.quota.default_monthly_tokens_per_user'),
            $quota->capFor($iscritto->refresh()),
        );
    }

    /**
     * 🚨 **Il tetto della singola persona vince su quello della palestra.**
     *
     * E' cio' che permette di sbloccare qualcuno senza alzare il tetto a tutti:
     * senza, l'unica leva sarebbe alzarlo per l'intera palestra, e il costo
     * massimo del mese si moltiplicherebbe per il numero di iscritti.
     */
    #[Test]
    public function a_members_own_cap_wins_over_the_gyms(): void
    {
        $quota = app(MemberAiQuota::class);

        $this->alfa->update(['ai_monthly_tokens_per_member' => 5_000]);
        $this->iscritto->forceFill(['ai_monthly_token_cap' => 50_000])->save();

        $this->assertSame(50_000, $quota->capFor($this->iscritto->refresh()));

        // E `0` sulla persona vale «illimitato», anche se la palestra ha un tetto.
        $this->iscritto->forceFill(['ai_monthly_token_cap' => 0])->save();

        $this->assertNull($quota->capFor($this->iscritto->refresh()));
    }

    /**
     * 🚨 **Il consumo di uno non toglie niente agli altri.**
     *
     * E' il motivo per cui la quota e' passata da palestra a iscritto: con il
     * pozzo comune, la quarta persona della palestra trovava le funzioni AI
     * spente per il consumo delle prime tre.
     */
    #[Test]
    public function one_member_burning_tokens_does_not_starve_another(): void
    {
        $this->aiFinta();

        $this->alfa->update(['ai_monthly_tokens_per_member' => 1000]);

        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'altro@alfa.test');
        $altro->registraConsenso('ai_consent_at', true);

        // Il primo esaurisce il proprio tetto.
        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'mela'])->assertCreated();
        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'pera'])->assertCreated();
        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'banana'])->assertStatus(429);

        // Il secondo non se ne accorge nemmeno.
        $this->comeIscritto($altro)
            ->postJson('/api/v1/ai/food/text', ['text' => 'mela'])
            ->assertCreated();
    }

    #[Test]
    public function the_app_can_ask_how_much_is_left(): void
    {
        $this->aiFinta();

        $this->alfa->update(['ai_monthly_tokens_per_member' => 10_000]);

        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'mela']);

        $this->comeIscritto()
            ->getJson('/api/v1/ai/usage')
            ->assertOk()
            ->assertJsonPath('data.cap_tokens', 10_000)
            ->assertJsonPath('data.used_tokens', 620)
            ->assertJsonPath('data.remaining_tokens', 9380);
    }

    // ───────────────────────── consiglio ─────────────────────────

    /**
     * 🚨 La cache e' su un hash del contesto, e **la data ne fa parte**.
     *
     * Da qui discendono due cose senza nessun cron: il consiglio si rigenera a
     * mezzanotte, e si rigenera quando l'utente mangia o si allena.
     */
    #[Test]
    public function the_advice_is_generated_once_for_the_same_context(): void
    {
        $finta = $this->aiFinta()->willReturnAdvice('Mangia piu\' proteine.');

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Mangia piu\' proteine.');

        $chiamate = count($finta->calls);

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', true);

        $this->assertSame($chiamate, count($finta->calls), 'Il consiglio e\' stato rigenerato senza motivo.');
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    /** Se la giornata cambia, il consiglio si rifa'. */
    #[Test]
    public function a_new_meal_makes_the_advice_stale(): void
    {
        $finta = $this->aiFinta();

        $this->comeIscritto()->getJson('/api/v1/ai/advice')->assertOk();
        $primeChiamate = count($finta->calls);

        $this->comeIscritto()->postJson('/api/v1/food-entries', [
            'description' => 'Pollo', 'meal' => 'lunch', 'kcal' => 400, 'grams' => 250,
        ])->assertCreated();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', false);

        $this->assertGreaterThan($primeChiamate, count($finta->calls));
        $this->assertSame(2, AiAdvice::withoutGlobalScopes()->count());
    }

    /**
     * L'hash non deve dipendere dall'ordine delle chiavi.
     *
     * Senza `ksort`, due contesti identici darebbero hash diversi e la cache non
     * funzionerebbe **mai** — un guasto che non da' nessun errore, solo una
     * bolletta.
     */
    #[Test]
    public function the_context_hash_ignores_key_order(): void
    {
        $this->assertSame(
            AiAdvice::hashOf(['a' => 1, 'b' => 2]),
            AiAdvice::hashOf(['b' => 2, 'a' => 1]),
        );
    }

    // ───────────────────────── accesso ─────────────────────────

    #[Test]
    public function the_ai_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/ai/food/text', ['text' => 'mela'])->assertUnauthorized();
        $this->getJson('/api/v1/ai/advice')->assertUnauthorized();
    }
}
