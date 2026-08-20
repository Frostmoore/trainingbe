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
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function contestoMandato(array $query = []): array
    {
        $url = '/api/v1/ai/advice'.($query === [] ? '' : '?'.http_build_query($query));

        $this->comeApp($this->iscritto->fresh())
            ->getJson($url)
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

    // ─────────────── il tipo, che lo sa solo il telefono ───────────────

    /**
     * 📌 *«il tipo di allenamento deve partire: se il mio allenamento e' Pesi
     * questo deve passare»*.
     *
     * 💡 Sul server il tipo **non esiste**: l'unico posto dove esiste «Pesi» e'
     * l'orologio, e quello sta sul telefono.
     */
    #[Test]
    public function il_tipo_arriva_dal_telefono_e_si_attacca_alla_seduta(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);
        $seduta = $this->seduta(1);

        $voce = $this->contestoMandato([
            'training_types' => [$seduta->getKey() => 'STRENGTH_TRAINING'],
        ])['training']['this_week'][0];

        $this->assertSame('STRENGTH_TRAINING', $voce['type']);
    }

    /**
     * ══ 🚨 IL FILTRO CHE TIENE IN PIEDI LA PROMESSA ═══════════════════════
     *
     * ⚠️ Il server accetta solo `/^[A-Z_]{2,48}$/`. E' quella regex a garantire
     * che da qui non possa uscire **testo libero**: e' la ragione per cui e'
     * stato scelto il codice dell'orologio invece del nome della scheda.
     *
     * 🚨 Se questo test diventa rosso, diventano false T17 e la \S3.3-ter
     * dell'informativa.
     */
    #[Test]
    public function un_tipo_che_e_testo_libero_viene_rifiutato(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);
        $seduta = $this->seduta(1);

        $contesto = $this->contestoMandato([
            'training_types' => [$seduta->getKey() => 'Riabilitazione spalla — fase 2'],
        ]);

        $this->assertArrayNotHasKey('type', $contesto['training']['this_week'][0]);
        $this->assertStringNotContainsString(
            'Riabilitazione',
            (string) json_encode($contesto, JSON_UNESCAPED_UNICODE),
        );
    }

    /** 🚨 Senza il consenso separato il campo si scarta, come il recupero. */
    #[Test]
    public function senza_consenso_il_tipo_non_entra(): void
    {
        $seduta = $this->seduta(1);

        $voce = $this->contestoMandato([
            'training_types' => [$seduta->getKey() => 'RUNNING'],
        ])['training']['this_week'][0];

        $this->assertArrayNotHasKey('type', $voce);
    }

    /**
     * ⚠️ Un tipo per una seduta che non esiste **non parte**: la lista bianca e'
     * sull'`id`, non sulla buona fede del client.
     */
    #[Test]
    public function un_tipo_per_una_seduta_di_nessuno_non_entra(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);
        $this->seduta(1);

        $voce = $this->contestoMandato([
            'training_types' => [999999 => 'RUNNING'],
        ])['training']['this_week'][0];

        $this->assertArrayNotHasKey('type', $voce);
    }

    /**
     * 💡 Chiave assente e non `null`: un `null` direbbe al modello «di questo so
     * che non ha un tipo», che e' falso. La verita' e' «non lo so», e il prompt
     * (regola 8-bis) dice di non indovinare.
     */
    #[Test]
    public function senza_tipo_la_chiave_non_ce_proprio(): void
    {
        $this->seduta(1);

        $this->assertArrayNotHasKey('type', $this->contestoMandato()['training']['this_week'][0]);
    }

    // ─────────────── le serie della settimana ───────────────

    /**
     * 📌 *«sonno dell'ultima settimana, hrv e battito a riposo dell'ultima
     * settimana, allenamenti dell'ultima settimana con tipo e kcal bruciate»*.
     */
    #[Test]
    public function le_serie_della_settimana_arrivano_al_modello(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $contesto = $this->contestoMandato([
            'week_sleep' => [['day' => '19/8', 'hours' => '6.9', 'deep_min' => 70]],
            'week_hrv' => [['day' => '19/8', 'v' => 48]],
            'week_resting_hr' => [['day' => '19/8', 'v' => 54]],
            'week_workouts' => [
                ['day' => '19/8', 'minutes' => 62, 'type' => 'Pesi', 'kcal' => 680],
            ],
        ]);

        $this->assertSame('6.9', (string) $contesto['week_sleep'][0]['hours']);
        $this->assertSame(48, $contesto['week_hrv'][0]['v']);
        $this->assertSame('Pesi', $contesto['week_workouts'][0]['type']);
        $this->assertSame(680, $contesto['week_workouts'][0]['kcal']);
    }

    /**
     * ══ 🚨 LA LISTA BIANCA VALE ANCHE DENTRO GLI ELENCHI ═════════════════
     *
     * ⚠️ `RECUPERO` filtrava i nomi di primo livello, e bastava finche' i valori
     * erano numeri. Qui arrivano **elenchi di oggetti**, e un elenco e' un posto
     * in cui si puo' infilare qualunque cosa.
     */
    #[Test]
    public function i_campi_non_previsti_non_passano(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $contesto = $this->contestoMandato([
            'week_workouts' => [[
                'day' => '19/8',
                'minutes' => 62,
                'scheda' => 'Riabilitazione spalla',
                'note' => 'mal di schiena da tre giorni',
            ]],
        ]);

        $voce = $contesto['week_workouts'][0];

        $this->assertArrayNotHasKey('scheda', $voce);
        $this->assertArrayNotHasKey('note', $voce);
        $this->assertStringNotContainsString(
            'Riabilitazione',
            (string) json_encode($contesto, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * 💡 Una voce senza `day` non si puo' mettere in fila: il modello legge
     * queste serie come una sequenza nel tempo.
     */
    #[Test]
    public function una_voce_senza_giorno_si_scarta(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $contesto = $this->contestoMandato([
            'week_hrv' => [['v' => 48], ['day' => '19/8', 'v' => 50]],
        ]);

        $this->assertCount(1, $contesto['week_hrv']);
    }

    /** 🚨 Senza consenso separato non parte niente della settimana. */
    #[Test]
    public function senza_consenso_la_settimana_non_parte(): void
    {
        $contesto = $this->contestoMandato([
            'week_sleep' => [['day' => '19/8', 'hours' => '6.9']],
        ]);

        $this->assertArrayNotHasKey('week_sleep', $contesto);
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
