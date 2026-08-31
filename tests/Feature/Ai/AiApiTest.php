<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\AiCreditMovement;
use App\Models\AiUsageLog;
use App\Models\FoodEntry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\PortafoglioGettoni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
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

        /*
         * 🎟️ **I gettoni per le richieste fatte a mano** — 3b-AE, 31/08/2026.
         *
         * ⛔ Da oggi una stima da testo o da foto non guarda piu' la quota
         * inclusa: si paga a gettoni. Questi test parlano d'altro, e senza
         * credito fallirebbero con un 402 che non c'entra niente.
         *
         * 💡 Che il cancello commerciale funzioni e' provato dove deve esserlo,
         * in `PortafoglioGettoniTest` e `CicloDeiGettoniTest`.
         */
        $this->dagliGettoni($this->alfa);

        /*
         * ⚠️ **E anche Beta.** `usage_is_isolated_between_gyms` fa chiamare
         * l'iscritto dell'altra palestra: senza credito la sua chiamata prende
         * 402, non consuma niente, e il test misura l'isolamento fra un
         * contatore e **il nulla**.
         */
        $this->dagliGettoni($this->beta);

        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        // 🚨 S9.1 — senza consenso esplicito niente esce verso Anthropic, e
        // ogni rotta AI risponde 403. Qui lo si concede perché questi test
        // parlano d'altro; ⚠️ che il cancello funzioni è provato altrove, in
        // `ConsentApiTest`, e **deve** restare provato lì: se il consenso
        // arrivasse di serie da `creaUtente()`, il giorno in cui il cancello si
        // rompesse non se ne accorgerebbe nessuno.
        $this->iscritto->accendiLAi();
    }

    private function comeIscritto(?User $u = null): static
    {
        return $this->comeApp($u ?? $this->iscritto);
    }

    /**
     * Accoda una stima e restituisce il risultato — FASE 9.
     *
     * 🚨 **Dalla FASE 9 `POST /ai/food/text` non chiama piu' il modello**: apre
     * il cancello, scrive una riga e accoda. La stima si legge dopo, da
     * `GET /ai/food/stime/{id}`.
     *
     * 💡 Nei test la coda e' `sync` (`phpunit.xml`), quindi il lavoro gira
     * dentro la `POST` e il risultato c'e' gia' alla riga dopo. ⚠️ In produzione
     * no: chi legge questo aiuto non deve credere che sia istantaneo.
     *
     * @param  array<string, mixed>  $dati
     * @return array<string, mixed> `estimate`, `warnings`, `entries`, `saved`
     */
    private function stimaDaTesto(array $dati, ?User $chi = null): array
    {
        $id = $this->comeIscritto($chi)
            ->postJson('/api/v1/ai/food/text', $dati)
            ->assertStatus(202)
            ->json('data.id');

        return $this->comeIscritto($chi)
            ->getJson('/api/v1/ai/food/stime/'.$id)
            ->assertOk()
            ->assertJsonPath('data.stato', 'pronta')
            ->json('data.risultato');
    }

    /**
     * Conferma una stima, che dalla FASE 9 e' **l'unico** modo di far entrare
     * una voce in diario da una stima AI.
     *
     * @param  array<string, mixed>  $stima
     */
    private function conferma(array $stima, string $fonte = 'ai_text', ?User $chi = null): TestResponse
    {
        return $this->comeIscritto($chi)->postJson('/api/v1/ai/food/confirm', [
            'items' => $stima['estimate']['items'],
            'source' => $fonte,
            'meal' => 'lunch',
        ]);
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

        /*
         * 🆕 **Due passi invece di uno, dalla FASE 9**: prima la stima (che ora
         * nasce in coda), poi la conferma. ⚠️ Il `save: true` che faceva tutto
         * in una volta non esiste piu' — vedi `StimaInCodaTest`.
         */
        $stima = $this->stimaDaTesto(['text' => 'pasta al pomodoro', 'meal' => 'lunch']);

        $this->assertSame(0.85, $stima['estimate']['confidence']);
        $this->assertFalse($stima['saved']);
        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());

        $this->conferma($stima)
            ->assertCreated()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.description', 'Pasta');

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

        $stima = $this->stimaDaTesto(['text' => 'un panino', 'save' => false]);

        $this->assertFalse($stima['saved']);
        $this->assertSame([], $stima['entries']);
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

        $stima = $this->stimaDaTesto(['text' => 'un cucchiaio d\'olio']);

        $this->conferma($stima)
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

    /**
     * 🚨 **Scrivere la cache costa 1,25 volte l'input**, ed e' la voce piu' cara
     * delle tre.
     *
     * ── Il buco, trovato il 13/08/2026 ──────────────────────────────────────
     *
     * Nei log c'era una riga con `input_tokens = 12` e `cache_read_tokens = 0`
     * per una chiamata con un prompt da **cinquemila** token: quei cinquemila
     * erano finiti nella voce che non guardavamo, e per il contatore quella
     * chiamata era costata **niente**.
     *
     * ⚠️ **Da qui discende che una chiamata che SCRIVE la cache costa il 25% in
     * piu' di una senza cache.** Conviene solo se qualcuno la legge entro cinque
     * minuti: su un'installazione con un utente solo la cache costa, con traffico
     * vero paga dieci volte tanto.
     */
    #[Test]
    public function writing_the_cache_costs_more_than_plain_input(): void
    {
        $recorder = app(AiUsageRecorder::class);

        // 1.000.000 di token in ingresso: 100.000 millicent a listino.
        $pieno = $recorder->costMillicents('claude-haiku-4-5', 1_000_000, 0);

        // Gli stessi token, ma scritti in cache: 1,25x.
        $scrittura = $recorder->costMillicents('claude-haiku-4-5', 0, 0, 0, 1_000_000);

        // E letti dalla cache: un decimo.
        $lettura = $recorder->costMillicents('claude-haiku-4-5', 0, 0, 1_000_000);

        $this->assertSame(100_000, $pieno);
        $this->assertSame(125_000, $scrittura);
        $this->assertSame(10_000, $lettura);

        $this->assertGreaterThan($pieno, $scrittura, 'Scrivere la cache costa PIU\' di non usarla.');
    }

    /**
     * 🚨 **La contabilità conta tutti e quattro i tipi di token.**
     *
     * Fino al 13/08/2026 `billableTokens()` faceva `input + output`: ignorava sia
     * la lettura sia — molto peggio — la **creazione** della cache. Una chiamata
     * con un prompt da cinquemila token risultava costata dodici.
     *
     * ⚠️ **G2 ha cambiato il presupposto di questo test, non la sua unità.**
     * Prima diceva «il *tetto mensile* deve vedere i token della cache», e la
     * ragione era che da lì passava ciò che protegge la fattura. Da G2 il tetto
     * conta **chiamate** (D6): i token non lo toccano più.
     *
     * 🚨 Ma la ragione originale **non è sparita, si è spostata**: chi protegge
     * la fattura adesso è la contabilità, `AiUsageLog::tokensForUser()`, ed è lì
     * che i token della cache devono continuare a comparire. Un `billableTokens()`
     * che li ignorasse renderebbe di nuovo invisibile la parte più cara — solo
     * che invece di sballare un tetto sballerebbe i costi.
     *
     * 💡 Quindi verifica **entrambe** le cose, che ora sono due: i token per la
     * contabilità, le chiamate per la quota.
     */
    #[Test]
    public function the_accounting_counts_the_cache_tokens_and_the_quota_counts_calls(): void
    {
        $riga = AiUsageLog::create([
            'tenant_id' => $this->alfa->id,
            'user_id' => $this->iscritto->getKey(),
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'feature' => AiFeature::FoodText->value,
            'input_tokens' => 12,
            'output_tokens' => 150,
            'cache_read_tokens' => 0,
            'cache_creation_tokens' => 5068,
            'cost_millicents' => 0,
            'duration_ms' => 900,
            'success' => true,
            'created_at' => now(),
        ]);

        $this->assertSame(5230, $riga->billableTokens());

        $this->assertSame(
            5230,
            AiUsageLog::tokensForUser((int) $this->iscritto->getKey()),
            'La contabilita\' non vede i token della cache: sono quelli che si pagano di piu\'.',
        );

        // 🚨 La quota, invece, vede **una** chiamata — quanti che siano i suoi token.
        $this->assertSame(1, app(MemberAiQuota::class)->usedThisMonth($this->iscritto));
    }

    /**
     * Una chiamata **automatica**, che e' l'unica che passa ancora dalla quota.
     *
     * ══ 🚨 PERCHE' SERVE, DA 3b-AE ═══════════════════════════════════════
     *
     * 📌 *«tutte le richieste all'ai non automatiche devono costare GETTONI»*.
     *
     * ⛔ `POST /ai/food/text` e' una richiesta fatta a mano: da oggi **non
     * guarda la quota**, quindi non puo' piu' dimostrare che la quota si
     * esaurisce. ⚠️ Non era la quota a essere sbagliata: era la funzione scelta
     * per dimostrarla.
     */
    private function chiamataAutomatica(?User $chi = null): TestResponse
    {
        return $this->comeIscritto($chi)
            ->postJson('/api/v1/ai/scheda/progresso', [
                'automatica' => true,
                'esercizi' => [[
                    'id' => 7,
                    'nome' => 'Panca piana',
                    'sedute' => [
                        ['data' => '2026-08-01', 'carico' => 60.0, 'ripetizioni' => 8],
                        ['data' => '2026-08-08', 'carico' => 62.5, 'ripetizioni' => 8],
                    ],
                ]],
            ]);
    }

    /**
     * Rimette la palestra nello stato di chi i gettoni non li ha mai avuti.
     *
     * ══ 🚨 SERVONO TUTTE E DUE LE RIGHE ══════════════════════════════════
     *
     * ⛔ **Il saldo a zero non basta.** Il ripiego cortese di
     * `CancelloDeiGettoni` guarda anche il **registro**: chi ha un movimento in
     * `ai_credit_movements` riceve il 402 dei gettoni, non il 429 della quota.
     *
     * 💡 Ed e' giusto cosi': dire «hai finito il mese» a chi i gettoni li ha
     * avuti e li ha finiti lo manderebbe ad aspettare trenta giorni per una
     * cosa che si risolve ricaricando.
     *
     * ⚠️ Il `setUp` accredita — da 3b-AE serve a quasi tutti gli altri test —
     * quindi il registro non e' piu' vuoto, e la premessa va ricreata.
     */
    private function comeSeIGettoniNonFosseroMaiEsistiti(): void
    {
        $this->alfa->forceFill(['ai_credits' => 0])->save();

        AiCreditMovement::withoutGlobalScopes()
            ->where('tenant_id', $this->alfa->getKey())
            ->delete();
    }

    /** Il contatore di una palestra non vede quello di un'altra. */
    #[Test]
    public function usage_is_isolated_between_gyms(): void
    {
        $this->aiFinta();

        $altroIscritto = $this->creaUtente($this->beta, UserRole::Member, 'anna@beta.test');
        $altroIscritto->accendiLAi();

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
    public function it_refuses_when_the_member_ran_out_of_calls(): void
    {
        $finta = $this->aiFinta();

        $this->alfa->update(['ai_monthly_calls_per_member' => 2]);

        $this->comeSeIGettoniNonFosseroMaiEsistiti();

        // Prima chiamata: una su due. ⚠️ **Automatica** — 3b-AE.
        $this->chiamataAutomatica()->assertOk();

        // Seconda: il tetto e' raggiunto.
        $this->chiamataAutomatica()->assertOk();

        $chiamateFinora = count($finta->calls);

        $this->chiamataAutomatica()
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

        $this->alfa->update(['ai_monthly_calls_per_member' => 0]);
        $this->assertNull($quota->capFor($iscritto->refresh()));
        $this->assertNull($quota->remaining($iscritto));
        $this->assertNull($quota->usedPercent($iscritto));

        $this->alfa->update(['ai_monthly_calls_per_member' => null]);
        $this->assertSame(
            (int) config('ai.quota.default_monthly_calls_per_user'),
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

        $this->alfa->update(['ai_monthly_calls_per_member' => 50]);
        $this->iscritto->forceFill(['ai_monthly_call_cap' => 500])->save();

        $this->assertSame(500, $quota->capFor($this->iscritto->refresh()));

        // E `0` sulla persona vale «illimitato», anche se la palestra ha un tetto.
        $this->iscritto->forceFill(['ai_monthly_call_cap' => 0])->save();

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
    public function one_member_burning_calls_does_not_starve_another(): void
    {
        $this->aiFinta();

        $this->alfa->update(['ai_monthly_calls_per_member' => 2]);

        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'altro@alfa.test');
        $altro->accendiLAi();

        $this->comeSeIGettoniNonFosseroMaiEsistiti();

        // Il primo esaurisce il proprio tetto.
        // ⚠️ **Automatiche** — 3b-AE: sono le sole che consumano la quota.
        $this->chiamataAutomatica()->assertOk();
        $this->chiamataAutomatica()->assertOk();
        $this->chiamataAutomatica()->assertStatus(429);

        // Il secondo non se ne accorge nemmeno.
        $this->chiamataAutomatica($altro)->assertOk();
    }

    #[Test]
    public function the_app_can_ask_how_much_is_left(): void
    {
        $this->aiFinta();

        $this->alfa->update(['ai_monthly_calls_per_member' => 10]);

        /*
         * 🚨 **Il saldo lo fissa questo test.** Dal `setUp` arrivano dei gettoni
         * — servono a quasi tutti gli altri, che da 3b-AE pagano ogni stima —
         * ma qui il numero mostrato *e'* la cosa che si sta provando: ereditarlo
         * vorrebbe dire scriverlo in due posti e vederlo cambiare da solo.
         */
        $this->alfa->forceFill(['ai_credits' => 0])->save();

        /*
         * ⚠️ **Automatica** — 3b-AE: con il portafoglio a zero, una stima da
         * testo prenderebbe 402 e il contatore resterebbe fermo. Qui serve una
         * chiamata che la quota la consumi davvero.
         */
        $this->chiamataAutomatica()->assertOk();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/usage')
            ->assertOk()
            // ⚠️ Le chiavi `*_tokens` restano per l'app gia' installata, e da
            // G2 portano **chiamate**: rinominarle spegnerebbe la barra dei
            // consumi su ogni telefono non ancora aggiornato.
            ->assertJsonPath('data.cap_tokens', 10)
            ->assertJsonPath('data.used_tokens', 1)
            ->assertJsonPath('data.remaining_tokens', 9)
            // 🆕 E i nomi veri, che l'app nuova legge.
            ->assertJsonPath('data.cap_calls', 10)
            ->assertJsonPath('data.used_calls', 1)
            ->assertJsonPath('data.remaining_calls', 9)
            ->assertJsonPath('data.ai_credits', 0);
    }

    /**
     * 🚨 **A chi è abbonato il saldo non si mostra** — 16/08/2026, sera.
     *
     * ── Il conto che chiunque farebbe ─────────────────────────────────────
     *
     * Fino a questa modifica `gettoni_disponibili` era `quota del mese +
     * gettoni comprati`. ⚠️ Sommandoli, chi ha un abbonamento vedeva **quante
     * chiamate gli restano incluse** — e quel numero, accanto al listino
     * pubblicato, si trasforma in una divisione che chiunque sa fare: comprare
     * un pacchetto costa meno che abbonarsi.
     *
     * 💡 **E non si manda zero, si dice di non mostrare niente**: uno zero
     * accanto a un'AI che funziona è una contraddizione, e chi lo legge pensa
     * che sia rotta.
     */
    #[Test]
    public function a_subscriber_is_not_shown_a_balance(): void
    {
        $this->alfa->update(['ai_monthly_calls_per_member' => 10]);

        /*
         * 🚨 **Il saldo lo fissa questo test.** Dal `setUp` arrivano dei gettoni
         * — servono a quasi tutti gli altri, che da 3b-AE pagano ogni stima —
         * ma qui il numero mostrato *e'* la cosa che si sta provando: ereditarlo
         * vorrebbe dire scriverlo in due posti e vederlo cambiare da solo.
         */
        $this->alfa->forceFill(['ai_credits' => 0])->save();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/usage')
            ->assertOk()
            // La dotazione inclusa esiste...
            ->assertJsonPath('data.remaining_calls', 10)
            // ...ma non compare come credito, e il contatore resta spento.
            // 📌 Il campo `mostra_gettoni` non esiste più: il contatore si
            // vede sempre, anche a zero. Un contatore che a volte c'è e a
            // volte no è peggio di uno zero — chi lo cerca e non lo trova
            // pensa che sia rotto.
            ->assertJsonPath('data.gettoni_disponibili', 0)
            ->assertJsonMissingPath('data.mostra_gettoni');
    }

    /** 💡 Chi invece i gettoni li ha **comprati** continua a vederli: sono suoi. */
    #[Test]
    public function someone_who_bought_credits_still_sees_them(): void
    {
        $this->alfa->update(['ai_monthly_calls_per_member' => 10]);

        /*
         * 🚨 **Il saldo lo fissa questo test.** Dal `setUp` arrivano dei gettoni
         * — servono a quasi tutti gli altri, che da 3b-AE pagano ogni stima —
         * ma qui il numero mostrato *e'* la cosa che si sta provando: ereditarlo
         * vorrebbe dire scriverlo in due posti e vederlo cambiare da solo.
         */
        $this->alfa->forceFill(['ai_credits' => 0])->save();

        app(PortafoglioGettoni::class)->accredita($this->alfa, 42);

        $this->comeIscritto()
            ->getJson('/api/v1/ai/usage')
            ->assertOk()
            ->assertJsonPath('data.gettoni_disponibili', 42);
    }

    // ───────────────────────── consiglio ─────────────────────────

    /**
     * 🚨 **La cache e' sulla FASCIA** — 3b-AB, 30/08/2026.
     *
     * ⚠️ Era sull'hash del contesto, e quel commento chiamava pregio quello che
     * era il costo: *«si rigenera quando l'utente mangia o si allena»* voleva
     * dire **a ogni pasto**. 💡 Adesso dentro una fascia il consiglio e' uno; le
     * fasce sono in `ConsiglioAFasceTest`.
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

    /**
     * ⛔ **Un pasto NON rende vecchio il consiglio** — 3b-AB, 30/08/2026.
     *
     * ══ 🚨 QUESTO TEST DICEVA IL CONTRARIO, E CERTIFICAVA LA SPESA ════════
     *
     * Si chiamava `a_new_meal_makes_the_advice_stale` e pretendeva **due**
     * righe e una chiamata in piu'. ⚠️ Era coerente con il codice di allora, ed
     * e' proprio questo il punto: un test verde puo' fotografare un costo
     * scambiandolo per un requisito.
     *
     * 📌 Il committente: *«se ad esempio sono le 9:35 e io registro gli
     * alimenti alle 9:41, si usa lo slot delle 9 fino alle 14:01»*.
     *
     * 💡 Sei pasti al giorno erano **sei chiamate al modello**, tutte
     * automatiche e nessuna chiesta da nessuno.
     */
    #[Test]
    public function a_new_meal_does_not_regenerate_within_the_same_slot(): void
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
            ->assertJsonPath('data.cached', true);

        $this->assertSame($primeChiamate, count($finta->calls), 'Il pasto ha fatto ripartire il modello dentro la stessa fascia.');
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    /**
     * 🚨 **Il passare del tempo NON rigenera il consiglio** — difetto riferito
     * provando l'app il 12/08/2026.
     *
     * *«Sulla pagina Oggi non mi mostra sempre il consiglio del giorno. Io direi
     * che una volta salvato lo dovrebbe mostrare sempre finché non cambia
     * qualcosa.»*
     *
     * `time` cambia ogni minuto e `day_progress_pct` quasi altrettanto: finché
     * entravano nell'hash, **ogni apertura della schermata era una chiamata AI
     * nuova**. Il consiglio non era «a volte assente» — era ogni volta diverso,
     * e spariva del tutto quando la quota finiva o la chiamata tardava.
     *
     * ⚠️ L'ora **resta nel prompt**: al modello serve. Quello che non deve fare
     * è invalidare la cache.
     */
    #[Test]
    public function time_passing_does_not_regenerate_the_advice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00', 'UTC'));

        $finta = $this->aiFinta()->willReturnAdvice('Bevi piu\' acqua.');

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', false);

        $chiamate = count($finta->calls);

        /*
         * Sei ore dopo, senza aver mangiato né allenato niente.
         *
         * 🚨 **E adesso questo attraversa anche una fascia** (a Roma sono le
         * 11:00 e poi le 17:00): il test non prova più solo che l'orologio non
         * conta, ma che **una fascia nuova senza notizie non costa niente** —
         * 3b-AB.
         *
         * ⚠️ `last_event_at` è quello di prima, ed è il punto: l'app dice «da
         * allora non è successo nulla».
         */
        Carbon::setTestNow(Carbon::parse('2026-08-12 15:00:00', 'UTC'));

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice?last_event_at='.urlencode('2026-08-12T08:00:00+00:00'))
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.body', 'Bevi piu\' acqua.');

        $this->assertSame(
            $chiamate,
            count($finta->calls),
            'Il consiglio si e\' rigenerato solo perche\' e\' passato del tempo.',
        );
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());

        Carbon::setTestNow();
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

    // ───────── S8.2: il contesto arriva dall'app ─────────

    /**
     * 🚨 **Senza questo il consiglio del giorno era muto per tutti.**
     *
     * Da S5 il peso non sta piu' sul server, quindi `computedTargets()` non
     * calcola piu' niente: il modello riceveva le calorie assunte e **nessun
     * numero con cui confrontarle**. Adesso il fabbisogno lo calcola l'app e
     * lo manda, e il server lo **inoltra**.
     */
    #[Test]
    public function the_target_computed_by_the_app_reaches_the_model(): void
    {
        $ai = $this->aiFinta();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice?target_kcal=2100&target_protein_g=150&target_carbs_g=210&target_fat_g=70')
            ->assertOk();

        $contesto = collect($ai->calls)
            ->firstWhere('method', 'dailyAdvice')['args'];

        $this->assertSame(2100.0, $contesto['targets']['kcal']);
        $this->assertSame(150.0, $contesto['targets']['protein_g']);

        // 💡 Dice al modello **da dove viene il numero**: «calcolato sui tuoi
        // dati» e «prescritto dal tuo trainer» meritano un tono diverso.
        $this->assertSame('app', $contesto['targets']['source']);
    }

    /**
     * 🚨 **Il server lo inoltra e NON lo conserva.**
     *
     * E' la differenza fra «passare per» e «tenere», ed e' tutta la fase S5.
     * L'unica traccia che resta e' l'**hash** del contesto — un digest, non un
     * dato: da 2100 non si torna indietro.
     */
    #[Test]
    public function the_forwarded_target_is_never_written_down(): void
    {
        $this->aiFinta();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice?target_kcal=2100')
            ->assertOk();

        $riga = AiAdvice::withoutGlobalScopes()->firstOrFail();

        $this->assertStringNotContainsString('2100', $riga->context_hash);
        $this->assertStringNotContainsString('2100', (string) $riga->toJson());
    }

    /**
     * ⚠️ **Un numero assurdo non entra nel prompt.**
     *
     * Il server non puo' verificare che il target sia vero — il peso da cui
     * nasce non ce l'ha — ma un `target_kcal` di 40.000 non produrrebbe un
     * errore: produrrebbe un **consiglio alimentare assurdo**, detto con la
     * stessa sicurezza di uno giusto.
     */
    #[Test]
    public function an_absurd_target_is_refused(): void
    {
        $this->aiFinta();

        $this->comeIscritto()
            ->getJson('/api/v1/ai/advice?target_kcal=40000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_kcal']);
    }

    // ───────────────────── la foto ha lo stesso trattamento ─────────────────

    /**
     * 🚨 **Testo e foto passano dallo STESSO prompt di sistema.**
     *
     * Non e' scontato e vale la pena provarlo: due prompt diversi produrrebbero
     * due comportamenti diversi a parita' di alimento, e la differenza si
     * scoprirebbe solo confrontando due voci nate dalle due strade.
     */
    #[Test]
    public function the_photo_uses_the_same_system_prompt_as_the_text(): void
    {
        $finta = $this->aiFinta();

        $this->comeIscritto()->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false]);

        $this->comeIscritto()->postJson('/api/v1/ai/food/photo', [
            'photo' => UploadedFile::fake()->image('piatto.jpg', 400, 400),
            'save' => false,
        ])->assertStatus(202);

        $chiamate = collect($finta->calls)->pluck('method');

        $this->assertTrue($chiamate->contains('foodFromText'));
        $this->assertTrue($chiamate->contains('foodFromImage'));
    }

    /**
     * ⚠️ **Il doppio non deve produrre un'unita' vietata.**
     *
     * `FakeAiProvider::foodFromImage()` restituiva `unit => 'porzione'`, che in
     * produzione e' un errore grave del validatore: il doppio insegnava ai test
     * una forma che il sistema rifiuta. Un doppio che mente e' peggio di nessun
     * doppio, perche' i test restano verdi mentre provano la cosa sbagliata.
     */
    #[Test]
    public function the_fake_photo_estimate_would_survive_the_validator(): void
    {
        $this->aiFinta();

        $id = $this->comeIscritto()->postJson('/api/v1/ai/food/photo', [
            'photo' => UploadedFile::fake()->image('piatto.jpg', 400, 400),
            'save' => false,
        ])->assertStatus(202)->json('data.id');

        $stima = $this->comeIscritto()
            ->getJson('/api/v1/ai/food/stime/'.$id)
            ->assertOk()
            ->assertJsonPath('data.stato', 'pronta')
            ->json('data.risultato');

        $this->assertSame([], $stima['warnings']);
        $this->assertSame('g', $stima['estimate']['items'][0]['unit']);
        $this->assertSame('per_100g', $stima['estimate']['items'][0]['basis']);
    }

    // ───────────────────── conferma della stima (A4.8) ─────────────────────

    /**
     * 🚨 **Il flusso vero dell'app**, in due tempi: prima la stima senza
     * scrivere, poi la conferma di chi ha guardato i numeri.
     *
     * ── Perche' esiste ──────────────────────────────────────────────────────
     *
     * Provando l'app il 12/08/2026 il committente ha chiesto perche' il modello
     * non avesse capito che una cotoletta e' impanata. Il modello **lo aveva
     * scritto** nella propria `note` — *«non e' stato specificato se sono
     * panate»* — e nessuno la leggeva: l'app scriveva in diario e basta.
     *
     * ⚠️ `FoodEstimate` lo diceva dal primo giorno: *«sotto una soglia l'app
     * deve chiedere conferma invece di scrivere nel diario»*. Era una regola in
     * un docblock, e nessuna riga di codice la eseguiva.
     */
    #[Test]
    public function a_confirmed_estimate_becomes_diary_entries(): void
    {
        $this->aiFinta()->willReturnFood(FoodEstimate::fromArray([
            'items' => [
                ['name' => 'Cotoletta di pollo impanata', 'qty' => 200, 'unit' => 'g', 'grams' => 200, 'kcal' => 340, 'protein' => 32, 'carbs' => 12, 'fat' => 16],
            ],
            'confidence' => 0.9,
            'note' => 'Impanatura e olio assorbito compresi.',
        ]));

        // 1. La stima: niente in diario, ma la nota e la confidenza arrivano.
        //    🆕 Dalla FASE 9 passa dalla coda, e la nota sopravvive al viaggio.
        $risultato = $this->stimaDaTesto(['text' => 'una cotoletta di pollo impanata', 'save' => false]);

        $this->assertFalse($risultato['saved']);
        $this->assertSame('Impanatura e olio assorbito compresi.', $risultato['estimate']['note']);

        $stima = $risultato['estimate']['items'];

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());

        // 2. La conferma: adesso si scrive.
        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'meal' => 'lunch',
                'items' => $stima,
            ])
            ->assertCreated()
            ->assertJsonPath('data.saved', true)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.description', 'Cotoletta di pollo impanata');

        $voce = FoodEntry::withoutGlobalScopes()->firstOrFail();

        // 🚨 L'origine sopravvive alla conferma: senza, ogni voce nascerebbe
        // `manual` e il giorno che un modello peggiora non si saprebbe piu'
        // quali voci rifare — che e' esattamente cio' per cui `FoodSource`
        // esiste.
        $this->assertSame('ai_text', $voce->source->value);
        $this->assertSame('Cotoletta di pollo impanata', $voce->ai_raw['name'] ?? null);
        $this->assertSame(12.0, (float) $voce->carbs);
    }

    /**
     * 🚨 **La conferma non chiama il modello e non consuma quota.**
     *
     * La chiamata e' gia' stata pagata dalla stima: far pagare anche la
     * conferma vorrebbe dire far pagare due volte lo stesso pasto, e con la
     * quota al limite si arriverebbe all'assurdo di aver speso i token per una
     * stima che poi non si puo' salvare.
     */
    #[Test]
    public function confirming_costs_nothing(): void
    {
        $this->aiFinta();

        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'items' => [['name' => 'Mela', 'grams' => 150, 'kcal' => 78]],
            ])
            ->assertCreated();

        $this->assertSame(
            0,
            AiUsageLog::withoutGlobalScopes()->count(),
            'La conferma ha contato come una chiamata AI: sarebbe far pagare due volte lo stesso pasto.',
        );
    }

    /**
     * ⚠️ La conferma sta dietro `ai.consent` come tutto il resto del flusso.
     *
     * Non perche' mandi qualcosa a Anthropic — non manda niente — ma perche'
     * una voce che entra in diario dopo la revoca farebbe sembrare che la
     * revoca non abbia funzionato. E' il difetto #3 del 12/08, che riguardava
     * l'interfaccia e non il server: qui si chiude anche dal lato server.
     */
    #[Test]
    public function without_consent_nothing_can_be_confirmed(): void
    {
        $senzaConsenso = $this->creaUtente($this->alfa, UserRole::Member, 'senza@alfa.test');

        $this->comeApp($senzaConsenso)
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'items' => [['name' => 'Mela', 'grams' => 150, 'kcal' => 78]],
            ])
            ->assertForbidden();

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

    /**
     * 🚨 Solo le due fonti AI.
     *
     * Accettare `plan` da qui lascerebbe marchiare «dal piano alimentare» una
     * voce che nel piano non c'e', e l'aderenza al piano — che per una palestra
     * e' *la* domanda — diventerebbe un numero falso.
     */
    #[Test]
    public function a_confirmation_cannot_forge_its_own_origin(): void
    {
        foreach (['manual', 'plan', 'favorite'] as $fonte) {
            $this->comeIscritto()
                ->postJson('/api/v1/ai/food/confirm', [
                    'source' => $fonte,
                    'items' => [['name' => 'Mela', 'grams' => 150, 'kcal' => 78]],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['source']);
        }

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }

    /**
     * ⚠️ **I numeri corretti a mano vincono.**
     *
     * E' il senso stesso del foglio di conferma: se si potessero solo accettare
     * o rifiutare i numeri del modello, il pulsante «precisa» non servirebbe a
     * niente.
     */
    #[Test]
    public function the_numbers_edited_by_hand_are_the_ones_that_get_saved(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'meal' => 'dinner',
                'items' => [
                    ['name' => 'Cotoletta di pollo', 'qty' => 250, 'unit' => 'g', 'grams' => 250, 'kcal' => 475, 'protein' => 40, 'carbs' => 15, 'fat' => 20],
                ],
            ])
            ->assertCreated();

        $voce = FoodEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(250.0, (float) $voce->grams);
        $this->assertSame(475.0, (float) $voce->kcal);

        // 🚨 E i valori per 100 g si derivano lo stesso: senza, correggere la
        // quantita' dopo aver confermato non ricalcolerebbe niente (difetto #9).
        $this->assertSame(190.0, (float) $voce->kcal_100);
    }

    /**
     * 🚨 **Un pasto e' una cosa sola.** Se una voce non passa, non ne entra
     * nessuna: mezza cena in diario e' un totale sbagliato che non dichiara di
     * esserlo.
     */
    #[Test]
    public function a_meal_is_written_whole_or_not_at_all(): void
    {
        $this->comeIscritto()
            ->postJson('/api/v1/ai/food/confirm', [
                'source' => 'ai_text',
                'items' => [
                    ['name' => 'Pasta', 'grams' => 80, 'kcal' => 280],
                    ['name' => 'Sugo', 'grams' => 100, 'kcal' => -5],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.kcal']);

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->count());
    }
}
