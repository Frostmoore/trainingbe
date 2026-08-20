<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Gli allenamenti della settimana nel consiglio del giorno — 20/08/2026.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * 📌 Richiesta del committente: *«passa anche gli ultimi allenamenti della
 * settimana al prompt»*.
 *
 * ⚠️ Ma soprattutto difende quello che **non** deve partire. `recent` contiene
 * anche il nome della scheda, ed è l'unico campo di quell'elenco che non deve
 * uscire dal server: *«da un programma post-infortunio si capisce cos'è successo
 * a chi lo esegue»* (§3.2 dell'informativa).
 *
 * 💡 Un nome come «Riabilitazione spalla — fase 2» è un dato sanitario travestito
 * da etichetta, e mandarlo a un modello sarebbe il modo più distratto di
 * trasferirlo.
 */
final class AllenamentiNelConsiglioTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    /**
     * 🚨 **Il doppio si tiene, non si richiede.**
     *
     * `aiFinta()` fa `app(FakeAiProvider::class)`, che **non e' un singleton**:
     * chiamarlo una seconda volta restituisce un'istanza nuova, con `calls`
     * vuoto. ⚠️ Il sintomo e' «il consiglio non ha chiamato il modello» su una
     * richiesta che invece e' andata benissimo.
     */
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
     * @return array<string, mixed>
     */
    private function contestoMandato(): array
    {
        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();

        $chiamate = array_values(array_filter(
            $this->finta->calls,
            static fn (array $c): bool => $c['method'] === 'dailyAdvice',
        ));

        $this->assertNotEmpty($chiamate, 'Il consiglio non ha chiamato il modello.');

        // 💡 `record()` salva il contesto **direttamente** in `args`, non dentro
        // una lista di parametri: `args[0]` non esiste.
        return $chiamate[0]['args'];
    }

    private function seduta(int $giorniFa, ?string $nomeScheda = null, ?int $minuti = 60): WorkoutSession
    {
        $piano = $nomeScheda === null ? null : WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'name' => $nomeScheda,
        ]);

        $inizio = now()->subDays($giorniFa)->setTime(18, 30);

        return WorkoutSession::create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'plan_id' => $piano?->getKey(),
            'started_at' => $inizio,
            'ended_at' => $minuti === null ? null : $inizio->copy()->addMinutes($minuti),
        ]);
    }

    #[Test]
    public function gli_allenamenti_della_settimana_arrivano_al_modello(): void
    {
        $this->seduta(1);
        $this->seduta(3);

        $contesto = $this->contestoMandato();

        $this->assertArrayHasKey('this_week', $contesto['training']);
        $this->assertCount(2, $contesto['training']['this_week']);
    }

    /** 💡 Con `day` e `time`, perché chi si allena la sera ha bisogni diversi. */
    #[Test]
    public function con_giorno_ora_e_durata(): void
    {
        $this->seduta(1, minuti: 75);

        $voce = $this->contestoMandato()['training']['this_week'][0];

        $this->assertArrayHasKey('day', $voce);
        $this->assertSame('18:30', $voce['time']);
        $this->assertSame(75, $voce['duration_minutes']);
    }

    /**
     * ══ 🚨 IL TEST CHE CONTA PIÙ DI TUTTI ═══════════════════════════════════
     *
     * ⚠️ Il nome della scheda **non deve uscire dal server**. È già la regola di
     * §3.2 dell'informativa e dell'accordo con le palestre, e qui c'era il modo
     * più facile di violarla senza accorgersene: `recent` il nome ce l'ha già
     * dentro, e sarebbe bastato passare l'array così com'era.
     */
    #[Test]
    public function ma_il_nome_della_scheda_non_parte_mai(): void
    {
        $this->seduta(1, nomeScheda: 'Riabilitazione spalla — fase 2');

        $contesto = $this->contestoMandato();
        $serializzato = json_encode($contesto, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(
            'Riabilitazione',
            (string) $serializzato,
            'Da un programma post-infortunio si capisce cosa e successo a chi lo esegue.',
        );

        $this->assertArrayNotHasKey('name', $contesto['training']['this_week'][0]);
    }

    /** ⚠️ Sette giorni: quello di dieci giorni fa non racconta questa settimana. */
    #[Test]
    public function quello_di_dieci_giorni_fa_resta_fuori(): void
    {
        $this->seduta(10);

        $this->assertSame([], $this->contestoMandato()['training']['this_week']);
    }

    /**
     * 🚨 Una seduta ancora aperta non ha una durata: ce l'ha «finora», e cresce
     * mentre il modello legge. ⚠️ E entrerebbe nell'hash della cache, quindi il
     * consiglio si rigenererebbe a ogni apertura della schermata.
     */
    #[Test]
    public function una_seduta_ancora_aperta_resta_fuori(): void
    {
        $this->seduta(0, minuti: null);

        $this->assertSame([], $this->contestoMandato()['training']['this_week']);
    }

    /**
     * 💡 Elenco vuoto e non chiave assente: il prompt (regola 8-bis) dice che
     * vuoto vuol dire «non ha registrato niente», **non** «non si è allenato».
     * Una chiave che sparisce lascerebbe il modello a indovinare quale dei due.
     */
    #[Test]
    public function senza_allenamenti_la_chiave_ce_lo_stesso_ed_e_vuota(): void
    {
        $contesto = $this->contestoMandato();

        $this->assertArrayHasKey('this_week', $contesto['training']);
        $this->assertSame([], $contesto['training']['this_week']);
    }

    /** ⚠️ I due conteggi di prima non si toccano: servono a un'altra domanda. */
    #[Test]
    public function i_conteggi_restano_dov_erano(): void
    {
        $this->seduta(1);

        $training = $this->contestoMandato()['training'];

        $this->assertArrayHasKey('last_30_days', $training);
        $this->assertArrayHasKey('days_since_last', $training);
    }
}
