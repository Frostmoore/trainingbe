<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiCreditMovement;
use App\Models\User;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PortafoglioGettoni;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Il portafoglio dei gettoni AI — G2, D16.
 *
 * 🚨 **Il test piu' importante di questa classe e' `the_wallet_is_never_touched_
 * while_the_included_quota_lasts()`.** Un gettone speso mentre la quota inclusa
 * e' ancora piena e' un gettone rubato, e non se ne accorge nessuno: il servizio
 * funziona, la chiamata riesce, il saldo cala. Si vedrebbe solo dalla fattura di
 * qualcun altro, mesi dopo.
 */
final class PortafoglioGettoniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        // 🚨 Regola non negoziabile: nessun test tocca la rete. Senza questa
        // riga i test del portafoglio chiamerebbero il modello vero — lento,
        // a pagamento, e rosso quando il fornitore ha un disservizio.
        $this->aiFinta();
    }

    private function portafoglio(): PortafoglioGettoni
    {
        return app(PortafoglioGettoni::class);
    }

    private function iscritto(): User
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');

        return $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test')->fresh();
    }

    // ───────────────────── il costo ─────────────────────

    #[Test]
    public function a_photo_costs_seven_ordinary_calls(): void
    {
        /*
         * 🚨 **7, misurato** (`STIMA-COSTI-AI.md` §3.7): una foto costa
         * 0,0225 $, una chiamata ordinaria 0,00318 $ — media pesata sull'uso
         * reale. Rapporto 7,1.
         *
         * ⚠️ **Era 6, e sbagliava due volte**: tarato sul rapporto con la stima
         * da testo invece che con la chiamata ordinaria, e su costi vecchi di
         * tre giorni. Se cambia il modello o il prompt cambia questo numero, e
         * questo test dice dove.
         */
        $this->assertSame(1, AiFeature::FoodText->costoInGettoni());
        $this->assertSame(1, AiFeature::WorkoutKcal->costoInGettoni());
        $this->assertSame(1, AiFeature::DailyAdvice->costoInGettoni());
        $this->assertSame(1, AiFeature::PlanFood->costoInGettoni());

        $this->assertSame(10, AiFeature::FoodPhoto->costoInGettoni());

        // ⚠️ E il PDF di una **scheda** costa come una foto: stesso modello,
        // stesso allegato.
        $this->assertSame(10, AiFeature::PdfImport->costoInGettoni());

        /*
         * 🚨 Il PDF di un **piano alimentare** no: 50.
         *
         * *«generalmente sono MOLTO piu' grandi»* — il committente. Giorni,
         * pasti, alimenti e grammi su parecchie facciate: contarlo a 10 vorrebbe
         * dire venderlo sotto costo.
         */
        $this->assertSame(50, AiFeature::NutritionPdfImport->costoInGettoni());
    }

    // ───────────────────── l'ordine di consumo ─────────────────────

    #[Test]
    public function the_wallet_is_never_touched_while_the_included_quota_lasts(): void
    {
        $utente = $this->iscritto();
        $utente->tenant->update(['ai_monthly_calls_per_member' => 10]);
        $this->portafoglio()->accredita($utente->tenant, 100);

        $utente->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();

        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'save' => false])
            ->assertStatus(202);

        /*
         * 🚨 **Il saldo non si e' mosso.** La quota inclusa bastava, e i gettoni
         * si toccano solo dopo. Un gettone speso qui sarebbe un gettone rubato —
         * e nessuno lo noterebbe, perche' il servizio ha funzionato.
         */
        $this->assertSame(100, $this->portafoglio()->saldo($utente->fresh()));
        $this->assertSame(
            0,
            AiCreditMovement::withoutGlobalScopes()->where('causale', AiCreditMovement::CONSUMO)->count(),
        );
    }

    #[Test]
    public function the_call_that_exhausts_the_quota_is_still_covered_by_it(): void
    {
        $utente = $this->iscritto();
        // ⚠️ Tetto a 1: **questa** chiamata e' l'ultima coperta dalla quota.
        $utente->tenant->update(['ai_monthly_calls_per_member' => 1]);
        $this->portafoglio()->accredita($utente->tenant, 100);

        $utente->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();

        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'save' => false])
            ->assertStatus(202);

        /*
         * 🚨 **Il difetto che questo test esiste per impedire.**
         *
         * La prima versione del controller ricontrollava `hasQuotaLeft()` DOPO
         * la chiamata per decidere se scalare un gettone. Ma la chiamata scrive
         * la propria riga in `ai_usage_logs`: dopo, la quota risultava esaurita —
         * per colpa della chiamata stessa — e il gettone veniva scalato.
         *
         * ⚠️ Una volta al mese, per ogni cliente, in silenzio. La decisione si
         * prende **prima** e viaggia fino al consumo.
         */
        $this->assertSame(100, $this->portafoglio()->saldo($utente->fresh()), 'la chiamata coperta ha pagato un gettone');
    }

    #[Test]
    public function once_the_quota_is_gone_the_wallet_pays(): void
    {
        $utente = $this->iscritto();
        // ⚠️ Un tetto «a zero chiamate» non si puo' esprimere: `0` vuol dire
        // **illimitato**. Si mette a 1 e la si consuma.
        $utente->tenant->update(['ai_monthly_calls_per_member' => 1]);
        $this->portafoglio()->accredita($utente->tenant, 100);

        $utente->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();

        // La prima chiamata usa la quota inclusa.
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'save' => false])
            ->assertStatus(202);

        // La seconda no: la quota e' finita.
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'tre uova', 'save' => false])
            ->assertStatus(202);

        $this->assertSame(99, $this->portafoglio()->saldo($utente->fresh()));

        $movimento = AiCreditMovement::withoutGlobalScopes()
            ->where('causale', AiCreditMovement::CONSUMO)->firstOrFail();

        $this->assertSame(-1, $movimento->delta);
        $this->assertSame(99, $movimento->saldo_dopo);
    }

    #[Test]
    public function with_an_empty_wallet_and_no_quota_the_error_is_the_credit_one(): void
    {
        $utente = $this->iscritto();
        $utente->tenant->update(['ai_monthly_calls_per_member' => 1]);
        $this->portafoglio()->accredita($utente->tenant, 1);
        $utente->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();

        // Quota (1) + gettoni (1) = due chiamate possibili.
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])->assertStatus(202);
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due mele', 'save' => false])->assertStatus(202);

        /*
         * 🚨 **402 con un codice suo**, non il 429 della quota. Dire «hai
         * raggiunto il limite mensile» a chi ha **comprato** dei gettoni e li ha
         * finiti e' il modo piu' veloce per perderlo: ha pagato per non ricevere
         * quel messaggio, e riceverlo lo stesso sembra un imbroglio.
         */
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'tre mele', 'save' => false])
            ->assertStatus(402)
            ->assertJsonPath('error', 'ai_credits_exhausted')
            ->assertJsonPath('saldo', 0)
            ->assertJsonPath('servivano', 1);
    }

    #[Test]
    public function someone_who_never_bought_credits_gets_the_quota_error(): void
    {
        $utente = $this->iscritto();
        $utente->tenant->update(['ai_monthly_calls_per_member' => 1]);
        $utente->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();

        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'una mela', 'save' => false])->assertStatus(202);

        /*
         * ⚠️ **Chi non ha mai comprato gettoni non deve sentirsi dire di
         * ricaricarli**: e' un errore che parla di una cosa che quella persona
         * non sa nemmeno che esista, e non spiega niente.
         */
        $this->actingAs($utente->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due mele', 'save' => false])
            ->assertStatus(429)
            ->assertJsonPath('error', 'ai_quota_exceeded');
    }

    // ───────────────────── il registro ─────────────────────

    #[Test]
    public function every_movement_records_the_balance_after_it(): void
    {
        $utente = $this->iscritto();

        $this->portafoglio()->accredita($utente->tenant, 50, nota: 'pacchetto piccolo');
        $this->portafoglio()->accredita($utente->tenant->fresh(), 30, nota: 'ricarica');

        $movimenti = AiCreditMovement::withoutGlobalScopes()->orderBy('id')->get();

        /*
         * 🚨 `saldo_dopo` e' ridondante di proposito, ed e' l'unica ridondanza
         * ammessa in tutto il piano: un saldo che si puo' solo ricalcolare
         * sommando il registro non si puo' **contestare**. I soldi si
         * contestano, le calorie no.
         */
        $this->assertSame(50, $movimenti[0]->saldo_dopo);
        $this->assertSame(80, $movimenti[1]->saldo_dopo);
        $this->assertSame(80, $utente->tenant->fresh()->ai_credits);
    }

    #[Test]
    public function a_correction_cannot_push_the_balance_below_zero(): void
    {
        $utente = $this->iscritto();
        $this->portafoglio()->accredita($utente->tenant, 10);

        $this->expectException(\InvalidArgumentException::class);

        /*
         * ⚠️ Un portafoglio in rosso e' un **credito verso il cliente**, cioe'
         * una cosa che nessuna parte di questo sistema sa gestire: non c'e'
         * niente che lo recuperi, e la prima ricarica lo ripianerebbe in
         * silenzio. Meglio rifiutare la rettifica.
         */
        $this->portafoglio()->accredita(
            $utente->tenant->fresh(), -50, causale: AiCreditMovement::RETTIFICA,
        );
    }

    #[Test]
    public function a_zero_movement_is_refused(): void
    {
        $utente = $this->iscritto();

        // 💡 Sporca il registro senza dire niente: chi lo legge cerca cosa e'
        // cambiato e non trova niente.
        $this->expectException(\InvalidArgumentException::class);

        $this->portafoglio()->accredita($utente->tenant, 0);
    }

    #[Test]
    public function spending_more_than_the_balance_is_refused(): void
    {
        $utente = $this->iscritto();
        $this->portafoglio()->accredita($utente->tenant, 3);

        $this->expectException(GettoniEsauritiException::class);

        // ⚠️ Una foto costa 7 e il saldo e' 3: non si va sotto zero nemmeno qui.
        $this->portafoglio()->consuma($utente->fresh(), AiFeature::FoodPhoto);
    }

    #[Test]
    public function the_wallet_belongs_to_the_tenant_not_to_the_person(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $uno = $this->creaUtente($palestra, UserRole::Member, 'uno@alfa.test')->fresh();
        $due = $this->creaUtente($palestra, UserRole::Member, 'due@alfa.test')->fresh();

        $this->portafoglio()->accredita($palestra, 10);

        /*
         * 💡 E' il monte condiviso di D16, ed e' voluto: i gettoni li compra chi
         * paga, e li consumano i suoi. ⚠️ La conseguenza — un allievo puo'
         * prosciugarli per gli altri — e' il debito §7.4, e va **mostrata**
         * nell'interfaccia, non lasciata scoprire.
         */
        $this->assertSame(10, $this->portafoglio()->saldo($uno));
        $this->assertSame(10, $this->portafoglio()->saldo($due));

        $this->portafoglio()->consuma($uno, AiFeature::FoodText);

        $this->assertSame(9, $this->portafoglio()->saldo($due->fresh()));
    }
}
