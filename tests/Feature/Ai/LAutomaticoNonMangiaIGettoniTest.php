<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚨 **Quello che parte da solo non si paga coi gettoni** — 20/09/2026.
 *
 * ── 📌 Da dove nasce ───────────────────────────────────────────────────────
 *
 * Il committente, rileggendo la tabella «gratis o Plus» del sito: *«le chiamate
 * automatiche non sono attive neanche se hai i gettoni per i non abbonati»*.
 *
 * ⛔ **E il codice faceva il contrario.** `CancelloDeiGettoni` ha in testa,
 * dal 31/08, la tabella che divide il modello commerciale in due righe:
 *
 * | | Chi paga |
 * |---|---|
 * | quello che parte **da solo** | la quota inclusa dell'abbonamento |
 * | quello che **chiedi tu** | i gettoni, sempre |
 *
 * 🚨 Ma la riga di sopra non era scritta da nessuna parte: una chiamata
 * automatica che non trovava quota **cadeva sui gettoni**. Chi non è abbonato e
 * aveva comprato dieci gettoni per fotografare i piatti se li vedeva consumare
 * dal consiglio del giorno, che non aveva chiesto.
 *
 * 💡 Ed è il genere di difetto che non dà errore: la chiamata riesce, il saldo
 * cala, e lo scopre solo chi si segna i numeri.
 */
final class LAutomaticoNonMangiaIGettoniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Una persona senza abbonamento, con dei gettoni comprati. */
    private function senzaAbbonamento(): User
    {
        $utente = app(CreaTenantPersonale::class)(
            'Chi Non Paga',
            'nonpaga@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        app(PortafoglioGettoni::class)->accredita($utente, 100, nota: 'prova');

        return $utente->fresh();
    }

    #[Test]
    public function senza_abbonamento_una_chiamata_automatica_non_parte_nemmeno_coi_gettoni(): void
    {
        $utente = $this->senzaAbbonamento();
        $prima = app(PortafoglioGettoni::class)->saldo($utente);

        $this->assertGreaterThan(0, $prima, 'la premessa del test: i gettoni ci sono');

        try {
            app(CancelloDeiGettoni::class)->apri($utente, AiFeature::DailyAdvice, aRichiesta: false);
            $this->fail('il consiglio automatico è partito a spese dei gettoni');
        } catch (AiQuotaExceededException) {
            // 💡 È la risposta giusta: «l'AI automatica non è compresa», non
            // «hai finito i gettoni». Chi non è abbonato non ha una quota.
        }

        $this->assertSame($prima, app(PortafoglioGettoni::class)->saldo($utente->fresh()), 'ha speso dei gettoni');
    }

    /**
     * 💡 **Ma quello che chiede lui sì**: è esattamente ciò per cui i gettoni
     * sono stati comprati.
     */
    #[Test]
    public function senza_abbonamento_quello_che_chiede_lui_si_paga_a_gettoni(): void
    {
        $utente = $this->senzaAbbonamento();

        $this->assertTrue(
            app(CancelloDeiGettoni::class)->apri($utente, AiFeature::FoodText, aRichiesta: true),
            'una richiesta fatta a mano deve passare dai gettoni',
        );
    }

    /**
     * 🚨 **E chi l'AI ce l'ha per contratto continua ad avere l'automatico**,
     * anche quando la quota del mese è finita.
     *
     * ⚠️ È la differenza fra «non hai comprato questa funzione» e «hai
     * raggiunto un tetto»: la seconda si può sfondare a gettoni, la prima no.
     * 💡 E vale anche per chi il posto l'ha **ricevuto** dalla palestra o dal
     * trainer: non paga lui, ma l'automatico gli spetta.
     */
    #[Test]
    public function con_l_ai_del_piano_l_automatico_puo_finire_sui_gettoni(): void
    {
        $palestra = $this->creaPalestra();
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@esempio.test');
        $iscritto->accendiLAi();

        // ⚠️ `1` e non `0`: `0` vuol dire **illimitato**, che è l'opposto.
        $iscritto->forceFill(['ai_monthly_call_cap' => 1])->save();

        $this->gettoniATutti($palestra, 50);

        $iscritto = $iscritto->fresh();

        // La quota inclusa c'è ancora: la chiamata automatica non tocca i gettoni.
        $this->assertFalse(
            app(CancelloDeiGettoni::class)->apri($iscritto, AiFeature::DailyAdvice, aRichiesta: false),
        );

        /*
         * ⚠️ **La quota si consuma scrivendo in `ai_usage_logs`**, non
         * chiamando `consuma()`: quello scala i gettoni. È la riga che ogni
         * chiamata vera al modello lascia dietro di sé, e `remaining()` conta
         * quelle.
         */
        app(AiUsageRecorder::class)->record(
            AiCallContext::for($iscritto, AiFeature::DailyAdvice),
            provider: 'finto',
            model: 'finto',
            inputTokens: 10,
            outputTokens: 10,
            cacheReadTokens: 0,
            durationMs: 1,
            success: true,
        );

        // …e quando è finita, l'automatico può continuare a gettoni.
        $this->assertTrue(
            app(CancelloDeiGettoni::class)->apri($iscritto->fresh(), AiFeature::DailyAdvice, aRichiesta: false),
            'a chi ha l\'AI nel piano il tetto raggiunto non spegne l\'automatico',
        );
    }
}
