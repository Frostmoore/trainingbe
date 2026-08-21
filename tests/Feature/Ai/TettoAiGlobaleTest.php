<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Il tetto globale delle chiamate AI — FASE 8.2, 21/08/2026.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * 📌 Il committente: *«se 4000000 utenti chiedono insieme di aggiungere un
 * piatto con l'ai?»*.
 *
 * Il tetto che c'era — `throttle:ai`, 6 al minuto — è **per utente**: ferma una
 * persona che insiste e non fa niente contro mille che ne fanno una a testa.
 * ⚠️ Con `pm.max_children = 6` e chiamate da ~3 secondi, bastano due richieste
 * AI al secondo perché il dominio sia pieno — e allora **non si ferma l'AI: si
 * ferma il sito**.
 *
 * 💡 Questi test guardano la cosa da fuori, come la vede un client: quante
 * richieste passano, e cosa riceve chi non passa.
 */
final class TettoAiGlobaleTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    private FakeAiProvider $finta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finta = $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');
        $this->iscritto->registraConsenso('ai_consent_at', true);
    }

    /**
     * Occupa a mano gli slot, come farebbero richieste ancora in corso.
     *
     * 💡 È l'unico modo di provare la **contemporaneità** in un test che di
     * processi ne ha uno: gli slot occupati *sono* le altre richieste.
     *
     * @return list<\Illuminate\Contracts\Cache\Lock>
     */
    private function occupa(int $quanti): array
    {
        $presi = [];

        for ($i = 1; $i <= $quanti; $i++) {
            $lucchetto = Cache::lock('ai:slot:'.$i, 30);
            $this->assertTrue($lucchetto->get(), "Lo slot $i doveva essere libero.");
            $presi[] = $lucchetto;
        }

        return $presi;
    }

    #[Test]
    public function con_gli_slot_liberi_la_richiesta_passa(): void
    {
        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();
    }

    #[Test]
    public function con_tutti_gli_slot_occupati_risponde_429_e_non_chiama_il_modello(): void
    {
        $this->occupa(config('ai.concorrenza.slot'));

        $risposta = $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertStatus(429);

        $risposta->assertJsonPath('code', 'ai_troppo_carico');

        /*
         * 🚨 **`Retry-After` non è cortesia**: senza, un client che riprova
         * subito torna su un tetto ancora pieno e trasforma un rallentamento in
         * un martellamento. 💡 Il valore è la durata tipica di una chiamata,
         * cioè quanto ci vuole perché uno slot si liberi davvero.
         */
        $this->assertNotEmpty($risposta->headers->get('Retry-After'));

        // ⚠️ E soprattutto: il modello **non è stato chiamato**. Un tetto che
        // respinge dopo aver speso non sarebbe un tetto.
        $this->assertSame(0, $this->quanteChiamate());
    }

    #[Test]
    public function con_uno_slot_libero_passa_ancora(): void
    {
        // 💡 Occupati tutti tranne l'ultimo: si prova che il tetto conta, non
        // che si chiude al primo occupato.
        $this->occupa(((int) config('ai.concorrenza.slot')) - 1);

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();
    }

    #[Test]
    public function lo_slot_si_libera_a_richiesta_finita(): void
    {
        $utente = $this->iscritto->fresh();

        /*
         * ⚠️ Tre richieste **in fila** devono passare tutte: il tetto è sulla
         * contemporaneità, non sul totale. 🚨 Se lo slot non venisse rilasciato,
         * la quarta chiamata di fila fallirebbe — ed è il difetto che il
         * `finally` nel middleware previene.
         */
        for ($i = 0; $i < 4; $i++) {
            $this->comeApp($utente)->getJson('/api/v1/ai/advice')->assertOk();
        }
    }

    #[Test]
    public function a_zero_slot_il_tetto_e_spento(): void
    {
        // 💡 L'interruttore serve: se il tetto un giorno desse problemi in
        // produzione, si spegne da `.env` senza rimettere mano al codice.
        config()->set('ai.concorrenza.slot', 0);

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();
    }

    private function quanteChiamate(): int
    {
        return count(array_filter(
            $this->finta->calls,
            static fn (array $c): bool => $c['method'] === 'dailyAdvice',
        ));
    }
}
