<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * «AI illimitata» vuol dire anche gettoni illimitati — 02/09/2026.
 *
 * ══ 📌 IL DIFETTO ═════════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«gli utenti che hanno ai illimitata devono avere anche
 * GETTONI illimitati, non 0 gettoni»*.
 *
 * 🚨 **E' nato con 3b-AE, in silenzio.** Finche' tutto passava dalla quota, la
 * concessione bastava a se stessa: nessun tetto, nessuna spesa. Da quando le
 * richieste fatte a mano si pagano **solo** a gettoni, quella stessa
 * concessione consegnava un **402 al primo alimento scritto**.
 *
 * ⛔ E il pannello continuava a promettere *«Nessun tetto mensile, foto
 * comprese. Il costo lo paghiamo noi»*. Il pulsante non era rotto: era
 * diventato **bugiardo**, che e' peggio — chi lo tocca crede di aver dato una
 * cosa che non ha dato.
 *
 * ══ 💡 PERCHE' UN FILE SUO ════════════════════════════════════════════════
 *
 * Perche' questa e' l'unica eccezione che viene **prima di tutte le altre**,
 * PDF compresi. ⚠️ Sepolta fra i test del ciclo dei gettoni sarebbe una riga
 * in mezzo a trenta; qui il nome del file dice cosa non deve tornare a
 * rompersi.
 */
final class AiIllimitataNonPagaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->tester = $this->creaUtente($this->palestra, UserRole::Member, 'prova@alfa.test');
        $this->tester->accendiLAi();
    }

    /**
     * Quello che scrive l'azione «AI illimitata» del pannello.
     *
     * 🚨 **Tutti e tre i campi**, come li scrive lei: `ai_enabled_override` è il
     * cancello (*se* l'AI spetta) e i due tetti sono la quota (*quante*
     * chiamate). ⛔ Scriverne solo due qui vorrebbe dire provare una
     * concessione che nel pannello non esiste.
     *
     * 💡 `forceFill` perché i due tetti sono fuori da `$fillable` di proposito:
     * una concessione non si assegna in massa da una richiesta HTTP.
     */
    private function dagliLIllimitata(): void
    {
        $this->tester->forceFill([
            'ai_enabled_override' => true,
            'ai_monthly_call_cap' => 0,
            'ai_monthly_photo_call_cap' => 0,
        ])->save();
    }

    private function saldo(): int
    {
        return (int) $this->palestra->fresh()->ai_credits;
    }

    private function stimaUnAlimento(): TestResponse
    {
        return $this->comeApp($this->tester->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'save' => false]);
    }

    // ───────────────────── il caso segnalato ─────────────────────

    /**
     * 🎯 **Il caso del committente, riga per riga.**
     *
     * Portafoglio a zero, nessun abbonamento, «AI illimitata» accesa: la stima
     * deve **partire**.
     */
    #[Test]
    public function con_l_ai_illimitata_e_zero_gettoni_la_stima_parte(): void
    {
        $this->dagliLIllimitata();

        $this->assertSame(0, $this->saldo(), 'Il test parte davvero da zero gettoni.');

        $this->stimaUnAlimento()->assertStatus(202);

        // ⛔ E il saldo resta a zero: non si e' scalato niente, e non e' andato
        // sotto zero.
        $this->assertSame(0, $this->saldo());
    }

    /**
     * ⛔ **Senza la concessione, la stessa persona prende 402.**
     *
     * 🚨 È la premessa che rende vero il test qui sopra: senza questa riga,
     * quello passerebbe anche se il cancello si fosse aperto per un motivo
     * qualunque — un piano con l'AI, un default di sistema, una quota residua.
     *
     * 💡 È la lezione già pagata due volte in due giorni: *«un test che non
     * fissa la propria premessa non prova quello che dice di provare»*.
     */
    #[Test]
    public function senza_la_concessione_la_stessa_persona_e_fermata(): void
    {
        $this->stimaUnAlimento()
            ->assertStatus(402)
            ->assertJsonPath('error', 'ai_credits_exhausted');
    }

    // ───────────────────── e vale per tutto ─────────────────────

    /** 📸 Anche la foto, che di gettoni ne vorrebbe dieci. */
    #[Test]
    public function anche_la_foto_che_ne_costerebbe_dieci(): void
    {
        $this->dagliLIllimitata();

        $this->comeApp($this->tester->fresh())
            ->postJson('/api/v1/ai/food/photo', [
                'photo' => UploadedFile::fake()->image('piatto.jpg'),
            ])
            ->assertStatus(202);

        $this->assertSame(0, $this->saldo());
    }

    /**
     * 🚨 **E anche «Rigenera» sul consiglio**, che è una richiesta fatta a mano.
     */
    #[Test]
    public function anche_rigenera_il_consiglio(): void
    {
        $this->dagliLIllimitata();

        $this->comeApp($this->tester->fresh())
            ->getJson('/api/v1/ai/advice?manuale=1')
            ->assertOk();

        $this->assertSame(0, $this->saldo());
    }

    /**
     * ⛔ **Solo il tetto della PERSONA, non quello della palestra.**
     *
     * 🚨 `capFor()` torna «senza tetto» anche quando lo zero arriva dalla
     * palestra. Se il cancello guardasse quello, **una palestra che toglie il
     * tetto ai propri iscritti regalerebbe a tutti i gettoni a spese nostre** —
     * e ce ne accorgeremmo dalla fattura del fornitore, non da qui.
     *
     * 💡 È la differenza fra una concessione che qualcuno ha **dato**, con un
     * nome nel registro, e un tetto che una palestra si è configurata da sola.
     */
    #[Test]
    public function il_tetto_della_palestra_non_regala_gettoni(): void
    {
        // ⚠️ La palestra dichiara «nessun tetto» per i suoi iscritti…
        $this->palestra->forceFill([
            'ai_monthly_calls_per_member' => 0,
            'ai_monthly_photo_calls_per_member' => 0,
        ])->save();

        // …ma questa persona la concessione personale non ce l'ha.
        $this->stimaUnAlimento()
            ->assertStatus(402)
            ->assertJsonPath('error', 'ai_credits_exhausted');
    }

    /**
     * ⚠️ **Mezza concessione non è una concessione.**
     *
     * 💡 Una foto consuma tutte e due le dimensioni: con il solo tetto generale
     * a zero, la foto continua a pagarsi. È il verso prudente, ed è anche
     * quello coerente col pannello — l'azione scrive **sempre** tutti e due.
     */
    #[Test]
    public function per_la_foto_servono_tutti_e_due_i_tetti(): void
    {
        $this->tester->forceFill([
            'ai_enabled_override' => true,
            'ai_monthly_call_cap' => 0,
            // ⛔ Questo no.
            'ai_monthly_photo_call_cap' => 5,
        ])->save();

        // 💡 La stima da testo passa: per quella il tetto generale basta.
        $this->stimaUnAlimento()->assertStatus(202);

        $this->comeApp($this->tester->fresh())
            ->postJson('/api/v1/ai/food/photo', [
                'photo' => UploadedFile::fake()->image('piatto.jpg'),
            ])
            ->assertStatus(402);
    }
}
