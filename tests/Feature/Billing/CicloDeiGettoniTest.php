<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * 🎯 Il ciclo di vita di un gettone comprato, **da capo a fondo e via HTTP**.
 *
 * ── 🚨 Perche' via HTTP e non sui servizi ─────────────────────────────────
 *
 * `PortafoglioGettoniTest` prova il portafoglio; `QuotaAChiamateTest` prova la
 * quota. ⚠️ **Nessuno dei due passa dal `middleware`**, e il cancello
 * `RequirePlanWithAi` gira **prima** del controller: un difetto che vive li' e'
 * invisibile a entrambi.
 *
 * 🎯 La domanda a cui questa classe risponde e' quella del committente, parola
 * per parola: *«se uno compra 100 gettoni deve avere l'AI abilitata per X
 * chiamate in base ai gettoni, poi deve tornare disabilitata»*.
 *
 * ── Le cinque condizioni che devono valere TUTTE ──────────────────────────
 *
 * | # | Condizione |
 * |---|---|
 * | 1 | comprare gettoni **abilita** l'AI a chi il piano non gliela da' |
 * | 2 | la quota **inclusa** si consuma per prima: un gettone speso a quota piena e' rubato |
 * | 3 | ogni chiamata scala **esattamente** il suo costo (1, o 7 se multimodale) |
 * | 4 | finiti i gettoni si torna a **rifiutare**, e con il messaggio giusto |
 * | 5 | una chiamata **fallita** non si paga |
 */
final class CicloDeiGettoniTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
        $this->iscritto->registraConsenso('ai_consent_at', true);
    }

    /** Il tenant compra gettoni: e' il gesto che tutta questa classe verifica. */
    private function compra(int $gettoni): void
    {
        $this->palestra->forceFill(['ai_credits' => $gettoni])->save();
        $this->iscritto = $this->iscritto->fresh();
    }

    /** Azzera la quota inclusa: da qui in poi paga il portafoglio. */
    private function quotaFinita(): void
    {
        // ⚠️ `1` e non `0`: `0` vuol dire **illimitato**, che e' l'opposto.
        $this->iscritto->forceFill(['ai_monthly_call_cap' => 1])->save();

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'la chiamata inclusa', 'save' => false])
            ->assertOk();

        $this->iscritto = $this->iscritto->fresh();
    }

    private function saldo(): int
    {
        return app(PortafoglioGettoni::class)->saldo($this->iscritto->fresh());
    }

    private function stima(): void
    {
        $this->aiFinta()->willReturnFood(FoodEstimate::fromArray([
            'items' => [['name' => 'Mela', 'qty' => 1, 'unit' => 'pz', 'grams' => 150, 'kcal' => 80, 'protein' => 0, 'carbs' => 20, 'fat' => 0]],
            'confidence' => 0.9,
        ]));
    }

    // ═══════════════ 1. Comprare gettoni abilita l'AI ═══════════════

    /**
     * 🚨 **LA domanda del committente.**
     *
     * Un tenant sul piano `free` — cioe' chiunque non abbia comprato un
     * abbonamento — compra 100 gettoni. Deve poterli usare.
     *
     * ⚠️ Se questo test e' rosso, **i gettoni non si possono vendere**: il
     * cancello `ai.plan` risponde `403` prima che il portafoglio venga
     * interrogato, e chi ha pagato non ottiene niente.
     */
    #[Test]
    public function buying_credits_enables_the_ai_for_a_plan_without_it(): void
    {
        $senzaAi = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $senzaAi->forceFill(['ai_credits' => 100])->save();

        // Il piano di questo tenant non comprende l'AI.
        Plan::query()->update(['ai_enabled' => false]);
        $utente = $this->creaUtente($senzaAi, UserRole::Member, 'compra@beta.test')->fresh();
        $utente->registraConsenso('ai_consent_at', true);

        /*
         * 🚨 **La premessa si verifica, non si assume.**
         *
         * Senza questa riga il test sarebbe passato **anche se il cancello
         * avesse lasciato passare per un altro motivo** — per esempio perche'
         * la palestra di prova ha un abbonamento a un piano con l'AI. E' gia'
         * successo due volte in due giorni: un test che non fissa la propria
         * premessa non prova quello che dice di provare.
         */
        $this->assertFalse(
            app(PianoAttivo::class)->haLaAi($utente),
            'la premessa non regge: questo utente ha gia\' l\'AI dal piano, e il test non prova niente',
        );

        $this->stima();

        $this->comeApp($utente->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertOk();
    }

    /**
     * 🚨 **La falla opposta, e costava piu' del difetto che si correggeva.**
     *
     * Aperto il cancello a chi ha gettoni, il piano `free` ha
     * `ai_monthly_calls_per_member = null` — «non decide questo livello» — e la
     * catena scendeva fino al **default di sistema: 400 chiamate**.
     *
     * ⚠️ Risultato: chi comprava **un** gettone si portava a casa 400 chiamate
     * gratis prima di spenderlo. La correzione di un difetto ne apriva uno piu'
     * caro, e il sintomo sarebbe stato «funziona benissimo».
     */
    #[Test]
    public function credits_do_not_come_with_free_included_calls(): void
    {
        $tenant = $this->creaPalestra('Gamma', 'gamma', 'GAMM2345');
        $tenant->forceFill(['ai_credits' => 10])->save();

        Plan::query()->update(['ai_enabled' => false]);

        $utente = $this->creaUtente($tenant, UserRole::Member, 'gamma@esempio.test')->fresh();
        $utente->registraConsenso('ai_consent_at', true);

        $this->stima();

        $this->comeApp($utente->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertOk();

        /*
         * 🎯 **La prima chiamata paga.** Se il saldo fosse ancora 10, vorrebbe
         * dire che quella chiamata l'ha coperta una quota «inclusa» in un piano
         * che l'AI non comprende — cioe' un regalo che nessuno ha deciso di
         * fare.
         */
        $this->assertSame(9, app(PortafoglioGettoni::class)->saldo($utente->fresh()));
    }

    /**
     * 🚨 **Lo spegnimento esplicito vince anche sui gettoni.**
     *
     * ⚠️ Un interruttore che si riaccende da solo perche' la palestra ha
     * ricaricato non e' un interruttore. Chi ha spento l'AI a una persona dal
     * pannello l'ha fatto per una ragione — un abuso, una richiesta, una prova —
     * e quella ragione non scade con un bonifico.
     */
    #[Test]
    public function switching_the_ai_off_for_a_person_beats_the_wallet(): void
    {
        $this->compra(100);

        $this->iscritto->forceFill(['ai_enabled_override' => false])->save();

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_without_ai');

        $this->assertSame(100, $this->saldo());
    }

    /**
     * 💡 **L'app deve mostrare i pulsanti a chi ha pagato.**
     *
     * `UserResource.ai_enabled` e' la bandierina che l'app legge per decidere se
     * disegnare le funzioni AI. ⚠️ Se dicesse «no» a chi ha comprato gettoni,
     * quello avrebbe pagato e non vedrebbe nemmeno dove usarli — e il server
     * direbbe di si' a una chiamata che l'app non fa partire.
     */
    #[Test]
    public function the_app_is_told_that_the_ai_is_available(): void
    {
        $tenant = $this->creaPalestra('Delta', 'delta', 'DELT2345');
        Plan::query()->update(['ai_enabled' => false]);

        $utente = $this->creaUtente($tenant, UserRole::Member, 'delta@esempio.test')->fresh();

        $this->comeApp($utente)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', false);

        $tenant->forceFill(['ai_credits' => 50])->save();

        $this->comeApp($utente->fresh())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', true);
    }

    // ═══════════════ 2. La quota inclusa viene prima ═══════════════

    #[Test]
    public function while_the_included_quota_lasts_no_credit_is_spent(): void
    {
        $this->compra(100);
        $this->stima();

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertOk();

        /*
         * 🚨 **Un gettone speso a quota piena e' un gettone rubato**, e non se
         * ne accorge nessuno: la chiamata riesce, il servizio funziona, il saldo
         * cala. Si scopre solo dalla fattura di qualcun altro, mesi dopo.
         */
        $this->assertSame(100, $this->saldo());
    }

    #[Test]
    public function the_call_that_uses_up_the_quota_is_still_covered_by_it(): void
    {
        $this->compra(100);
        $this->stima();

        // ⚠️ Il difetto vero che questa riga difende: `assertQuota()` decideva
        // *prima*, ma il consumo ricontrollava *dopo* — quando la riga in
        // `ai_usage_logs` era gia' scritta. La chiamata coperta dalla quota
        // veniva addebitata lo stesso.
        $this->quotaFinita();

        $this->assertSame(100, $this->saldo());
    }

    // ═══════════════ 3. Ogni chiamata scala il suo costo ═══════════════

    #[Test]
    public function once_the_quota_is_gone_each_call_costs_exactly_one(): void
    {
        $this->compra(10);
        $this->stima();
        $this->quotaFinita();

        for ($i = 1; $i <= 3; $i++) {
            $this->comeApp($this->iscritto->fresh())
                ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
                ->assertOk();

            $this->assertSame(10 - $i, $this->saldo(), "dopo {$i} chiamate il saldo non torna");
        }
    }

    #[Test]
    public function a_photo_costs_seven_and_the_balance_says_so(): void
    {
        // 💡 Misurato, non scelto: una foto costa ~7 volte una chiamata
        // ordinaria (`STIMA-COSTI-AI.md`).
        $this->assertSame(7, AiFeature::FoodPhoto->costoInGettoni());
        $this->assertSame(1, AiFeature::FoodText->costoInGettoni());
    }

    // ═══════════════ 4. Finiti i gettoni si torna a rifiutare ═══════════════

    #[Test]
    public function when_the_credits_run_out_the_ai_goes_back_to_refusing(): void
    {
        $this->compra(2);
        $this->stima();
        $this->quotaFinita();

        // Le due che i gettoni coprono.
        foreach ([1, 2] as $n) {
            $this->comeApp($this->iscritto->fresh())
                ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
                ->assertOk();
        }

        $this->assertSame(0, $this->saldo());

        /*
         * 🎯 **La seconda meta' della domanda**: «poi deve tornare
         * disabilitata». `402 Payment Required` e non `403`: chi ha finito i
         * gettoni non ha perso il diritto all'AI, ha finito il credito — e le
         * due cose portano a due pulsanti diversi nell'app.
         */
        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertStatus(402)
            ->assertJsonPath('error', 'ai_credits_exhausted')
            ->assertJsonPath('saldo', 0)
            ->assertJsonPath('servivano', 1);
    }

    /**
     * 🚨 **Il saldo si guarda contro il costo di QUESTA chiamata, non contro zero.**
     *
     * ⚠️ Un controllo `saldo > 0` lascerebbe partire una foto con 5 gettoni in
     * cassa e finirebbe a -2. E' il caso in cui un carattere sbagliato costa
     * denaro vero.
     */
    #[Test]
    public function a_photo_is_refused_when_the_credits_are_not_enough_for_it(): void
    {
        $this->compra(5);
        $this->quotaFinita();

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/photo', ['photo' => UploadedFile::fake()->image('piatto.jpg')])
            ->assertStatus(402)
            ->assertJsonPath('error', 'ai_credits_exhausted')
            ->assertJsonPath('saldo', 5)
            // 💡 «te ne servono 7 e ne hai 5» invece del generico «non bastano»:
            // e' l'unico messaggio che dice **quanto** ricaricare.
            ->assertJsonPath('servivano', 7);

        $this->assertSame(5, $this->saldo());
    }

    /**
     * ⚠️ **La corsa fra due chiamate, e la scelta che ne esce.**
     *
     * Fra il controllo (`assertQuota`) e il consumo (dopo la risposta del
     * fornitore) c'e' una finestra: due chiamate della stessa persona possono
     * passare entrambe il controllo con un gettone solo in cassa.
     *
     * 🚨 **Il saldo non va sotto zero** — lo impedisce `consuma()`, con
     * transazione e `lockForUpdate`. La seconda trova la cassa vuota, e il
     * controller **non rilancia**: la chiamata al fornitore l'abbiamo gia'
     * pagata noi, e buttarne via la risposta per un centesimo farebbe
     * arrabbiare un cliente per un servizio che ha gia' funzionato.
     *
     * 💡 La perdita e' quindi **al massimo una chiamata per corsa**, e va
     * saputa: questo test la fissa perche' resti una scelta e non diventi una
     * sorpresa.
     */
    #[Test]
    public function a_race_can_cost_at_most_one_call_and_never_goes_negative(): void
    {
        $this->compra(1);
        $this->stima();
        $this->quotaFinita();

        $portafoglio = app(PortafoglioGettoni::class);

        $portafoglio->consuma($this->iscritto->fresh(), AiFeature::FoodText);

        try {
            $portafoglio->consuma($this->iscritto->fresh(), AiFeature::FoodText);
            $this->fail('il secondo consumo doveva essere rifiutato');
        } catch (GettoniEsauritiException $e) {
            $this->assertSame(0, $e->saldo);
        }

        $this->assertSame(0, $this->saldo());
    }

    #[Test]
    public function a_balance_too_small_for_a_photo_still_pays_for_text(): void
    {
        $this->compra(3);
        $this->stima();
        $this->quotaFinita();

        // ⚠️ 3 gettoni non bastano per una foto (7) ma bastano per un testo (1).
        // Un controllo che guardasse «saldo > 0» invece del **costo di questa
        // chiamata** lascerebbe partire la foto e andrebbe in negativo.
        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertOk();

        $this->assertSame(2, $this->saldo());
    }

    #[Test]
    public function the_balance_never_goes_below_zero(): void
    {
        $this->compra(1);
        $this->stima();
        $this->quotaFinita();

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertOk();

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])
            ->assertStatus(402);

        // 🚨 Su un saldo di soldi, «di solito non succede» non e' una risposta.
        $this->assertSame(0, $this->saldo());
        $this->assertGreaterThanOrEqual(0, $this->saldo());
    }

    // ═══════════════ 5. Una chiamata fallita non si paga ═══════════════

    #[Test]
    public function a_failed_call_does_not_cost_a_credit(): void
    {
        $this->compra(5);
        $this->quotaFinita();

        // Il fornitore rifiuta: e' un guasto nostro o suo, non del cliente.
        $this->aiFinta()->willThrow(new \RuntimeException('il fornitore ha detto di no'));

        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false]);

        /*
         * 🚨 **Far pagare i propri guasti al cliente e' il modo piu' rapido di
         * perderlo.** Il consumo avviene **dopo** che la chiamata e' riuscita,
         * ed e' l'unico ordine accettabile.
         */
        $this->assertSame(5, $this->saldo());
    }

    // ═══════════════ Il portafoglio e' del tenant ═══════════════

    #[Test]
    public function two_members_of_the_same_gym_draw_from_the_same_wallet(): void
    {
        $this->compra(3);
        $this->stima();
        $this->quotaFinita();

        $altro = $this->creaUtente($this->palestra, UserRole::Member, 'altro@alfa.test');
        $altro->registraConsenso('ai_consent_at', true);
        $altro->forceFill(['ai_monthly_call_cap' => 1])->save();

        $this->comeApp($altro->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'inclusa', 'save' => false])
            ->assertOk();

        $this->comeApp($altro->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'a gettoni', 'save' => false])
            ->assertOk();

        /*
         * 💡 **I gettoni sono un monte condiviso, ed e' voluto** (D16): la quota
         * inclusa resta un tetto per persona, quindi il servizio di base non si
         * puo' prosciugare. I gettoni sono un extra che il trainer ha comprato e
         * di cui decide lui.
         *
         * ⚠️ Ma la conseguenza va **mostrata**: senza il consumo per allievo
         * (G11.3) il trainer sa solo che sono finiti.
         */
        $this->assertSame(2, $this->saldo());
    }
}
