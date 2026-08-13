<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Ai\Quota\MemberAiQuota;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * La quota in chiamate, con due contatori — G2 (D6, D7).
 *
 * 🚨 **Cosa prova questa classe, e non e' che i numeri tornino.** Prova le tre
 * cose che, sbagliate, **non danno errore**:
 *
 * 1. che una chiamata con allegato consumi **entrambi** i contatori — altrimenti
 *    chi ha ancora foto disponibili sfonda il totale senza che niente lo fermi;
 * 2. che il sotto-limite delle foto **non** blocchi le stime da testo — un
 *    utente che ha finito le foto e si vede negare anche il testo smette di
 *    credere a qualunque messaggio di quota;
 * 3. che `0` continui a valere **illimitato** su tutti e cinque i livelli.
 */
final class QuotaAChiamateTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function quota(): MemberAiQuota
    {
        return app(MemberAiQuota::class);
    }

    /** Scrive N chiamate finte a nome di questa persona. */
    private function chiamate(User $utente, AiFeature $funzione, int $quante): void
    {
        for ($i = 0; $i < $quante; $i++) {
            AiUsageLog::withoutGlobalScopes()->create([
                'tenant_id' => $utente->tenant_id,
                'user_id' => $utente->getKey(),
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'feature' => $funzione->value,
                // ⚠️ `ai_usage_logs` non ha `updated_at`: si scrive `created_at`
                // a mano, o `inMonth()` non trova niente.
                'created_at' => now(),
            ]);
        }
    }

    private function iscritto(int $chiamate, int $foto): User
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update([
            'ai_monthly_calls_per_member' => $chiamate,
            'ai_monthly_photo_calls_per_member' => $foto,
        ]);

        return $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test')->fresh();
    }

    // ───────────────────── il conteggio ─────────────────────

    #[Test]
    public function the_quota_counts_rows_not_tokens(): void
    {
        $utente = $this->iscritto(chiamate: 10, foto: 3);

        $this->chiamate($utente, AiFeature::FoodText, 4);

        /*
         * 🚨 Quattro chiamate sono quattro, quale che sia stato il loro costo in
         * token. E' tutto il senso di D6: i token sono l'unita' del fornitore,
         * le chiamate quella del cliente.
         */
        $this->assertSame(4, $this->quota()->usedThisMonth($utente));
        $this->assertSame(6, $this->quota()->remaining($utente));
    }

    #[Test]
    public function a_photo_burns_both_counters(): void
    {
        $utente = $this->iscritto(chiamate: 10, foto: 3);

        $this->chiamate($utente, AiFeature::FoodPhoto, 2);

        /*
         * 🚨 **Il sotto-limite e' dentro il totale, non accanto.** Se le foto
         * avessero un budget a parte, chi ha 10 chiamate e 3 foto potrebbe farne
         * 13 — e il piano sarebbe stato venduto per 10.
         */
        $this->assertSame(2, $this->quota()->usedThisMonth($utente, conFoto: true));
        $this->assertSame(2, $this->quota()->usedThisMonth($utente), 'la foto non ha toccato il totale');
        $this->assertSame(8, $this->quota()->remaining($utente));
        $this->assertSame(1, $this->quota()->remaining($utente, conFoto: true));
    }

    #[Test]
    public function the_pdf_import_counts_as_a_photo(): void
    {
        $utente = $this->iscritto(chiamate: 10, foto: 3);

        $this->chiamate($utente, AiFeature::PdfImport, 1);

        /*
         * ⚠️ **`PdfImport` non e' una foto ma costa come una foto**: passa da
         * `sonnet-5` con un documento allegato. Il listino lo chiama «di cui con
         * foto» e il codice conta i multimodali — la differenza va nella
         * direzione giusta, cioe' il cliente vede un limite piu' generoso di
         * quello applicato alle chiamate care.
         *
         * 🚨 Il piano diceva che `FoodPhoto` era l'unica funzione multimodale.
         * **Era falso**, ed e' stato corretto scrivendo G2.
         */
        $this->assertSame(1, $this->quota()->usedThisMonth($utente, conFoto: true));
    }

    // ───────────────────── il cancello ─────────────────────

    #[Test]
    public function running_out_of_photos_does_not_block_text(): void
    {
        $utente = $this->iscritto(chiamate: 10, foto: 2);

        $this->chiamate($utente, AiFeature::FoodPhoto, 2);

        // 🚨 Le foto sono finite…
        $this->assertFalse($this->quota()->hasQuotaLeft($utente, AiFeature::FoodPhoto));

        /*
         * …ma il testo no, e questo e' il punto. Un utente che ha ancora otto
         * chiamate e si vede negare anche una stima da testo smette di credere a
         * qualunque messaggio di quota — e ha ragione lui.
         */
        $this->assertTrue($this->quota()->hasQuotaLeft($utente, AiFeature::FoodText));
    }

    #[Test]
    public function running_out_of_calls_blocks_photos_too(): void
    {
        $utente = $this->iscritto(chiamate: 3, foto: 10);

        $this->chiamate($utente, AiFeature::FoodText, 3);

        /*
         * ⚠️ Il verso opposto **deve** bloccare: le foto rimaste sono 10, ma il
         * totale e' esaurito. Controllare solo il sotto-limite lascerebbe
         * sfondare il tetto generale a chi ha ancora foto disponibili.
         */
        $this->assertFalse($this->quota()->hasQuotaLeft($utente, AiFeature::FoodPhoto));
        $this->assertFalse($this->quota()->hasQuotaLeft($utente, AiFeature::FoodText));
    }

    #[Test]
    public function the_error_says_which_of_the_two_ran_out(): void
    {
        $utente = $this->iscritto(chiamate: 10, foto: 1);
        $this->chiamate($utente, AiFeature::FoodPhoto, 1);

        try {
            $this->quota()->assertWithinQuota($utente, AiFeature::FoodPhoto);
            $this->fail('la quota foto era esaurita e non ha lanciato');
        } catch (AiQuotaExceededException $e) {
            /*
             * 💡 «Hai finito le chiamate» a chi ne ha ancora nove ma ha finito
             * le foto e' un messaggio che sembra un guasto: quella persona **sa**
             * di non averle finite.
             */
            $this->assertTrue($e->soloFoto);
            $this->assertStringContainsString('foto', $e->getMessage());
            $this->assertSame(1, $e->capCalls);
        }
    }

    // ───────────────────── le convenzioni ─────────────────────

    #[Test]
    public function zero_means_unlimited_on_the_photo_counter_too(): void
    {
        // ⚠️ Il tetto generale sta largo di proposito: questo test misura il
        // sotto-limite delle foto, e con un generale stretto misurerebbe quello
        // — cioe' passerebbe o fallirebbe per la ragione sbagliata.
        $utente = $this->iscritto(chiamate: 100, foto: 0);

        $this->chiamate($utente, AiFeature::FoodPhoto, 50);

        // 🚨 `0` = illimitato. Vale sul secondo contatore come sul primo, o la
        // convenzione avrebbe due significati nella stessa catena.
        $this->assertNull($this->quota()->capFor($utente, conFoto: true));
        $this->assertNull($this->quota()->remaining($utente, conFoto: true));
        $this->assertTrue($this->quota()->hasQuotaLeft($utente, AiFeature::FoodPhoto));
    }

    #[Test]
    public function the_personal_photo_cap_wins_over_the_gym(): void
    {
        $utente = $this->iscritto(chiamate: 100, foto: 50);
        $utente->forceFill(['ai_monthly_photo_call_cap' => 2])->save();

        // ⚠️ Il livello 1 batte il 2 anche sul sotto-limite: le due catene sono
        // la stessa catena, non due meccanismi paralleli.
        $this->assertSame(2, $this->quota()->capFor($utente->fresh(), conFoto: true));
        $this->assertSame(100, $this->quota()->capFor($utente->fresh()));
    }

    #[Test]
    public function the_system_default_closes_the_chain_on_both_counters(): void
    {
        config([
            'ai.quota.default_monthly_calls_per_user' => 77,
            'ai.quota.default_monthly_photo_calls_per_user' => 7,
        ]);

        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->creaUtente($palestra, UserRole::Member, 'x@alfa.test')->fresh();

        /*
         * ⚠️ Il piano della palestra e' `gym`, che in `PlanSeeder` ha le sue
         * quote: si toglie l'abbonamento per arrivare davvero in fondo alla
         * catena, che e' quello che questo test vuole misurare.
         */
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $palestra->id)->delete();

        $this->assertSame(77, $this->quota()->capFor($utente));
        $this->assertSame(7, $this->quota()->capFor($utente, conFoto: true));
    }

    #[Test]
    public function last_month_does_not_count(): void
    {
        $utente = $this->iscritto(chiamate: 5, foto: 5);

        AiUsageLog::withoutGlobalScopes()->create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'feature' => AiFeature::FoodText->value,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay(),
        ]);

        // 💡 La quota e' **mensile**: il consumo del mese scorso non pesa su
        // questo, o nessuno tornerebbe mai sotto il tetto.
        $this->assertSame(0, $this->quota()->usedThisMonth($utente));
    }

    #[Test]
    public function the_percentage_is_null_when_there_is_no_cap(): void
    {
        $utente = $this->iscritto(chiamate: 0, foto: 0);

        // ⚠️ Mostrare «0%» a chi e' illimitato darebbe l'impressione di avere un
        // limite enorme invece di non averne.
        $this->assertNull($this->quota()->usedPercent($utente));
        $this->assertNull($this->quota()->usedPercent($utente, conFoto: true));
    }

    #[Test]
    public function someone_elses_calls_do_not_count(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update(['ai_monthly_calls_per_member' => 10, 'ai_monthly_photo_calls_per_member' => 5]);

        $uno = $this->creaUtente($palestra, UserRole::Member, 'uno@alfa.test')->fresh();
        $due = $this->creaUtente($palestra, UserRole::Member, 'due@alfa.test')->fresh();

        $this->chiamate($uno, AiFeature::FoodText, 9);

        /*
         * 🚨 **Il tetto e' di ciascuno** (C20). Era un pozzo comune, e la quarta
         * persona restava senza AI per il consumo di qualcun altro senza poterci
         * fare niente. Questo test difende quel cambio di rotta.
         */
        $this->assertSame(1, $this->quota()->remaining($uno));
        $this->assertSame(10, $this->quota()->remaining($due));
    }
}
