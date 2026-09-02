<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Cosa vede davvero il modello quando scrive il consiglio — 31/08/2026.
 *
 * ══ 📌 IL DIFETTO CHE QUESTO FILE CHIUDE ══════════════════════════════════
 *
 * 📌 Il committente: *«Io sono uno che si programma i pasti e se oggi ho gia'
 * segnato tutto quello che mangero' alle 10 di mattina il consiglio del giorno
 * mi dice che ho gia' assunto 1800 kcal e sono solo le 10... e' ovvio che non
 * puo' essere cosi'»*.
 *
 * ⛔ **E il modello non aveva modo di accorgersene.** Riceveva `totals` (un
 * totale) e `meals_logged` (un conteggio): da li' una cena scritta alle 10 del
 * mattino e' identica a una cena mangiata. 🚨 Il consiglio non era generico:
 * era **falso**, e detto con sicurezza.
 *
 * ══ 🚨 PERCHE' `created_at` E NON `eaten_at` ══════════════════════════════
 *
 * Perche' `eaten_at` **non contiene l'ora**: l'app manda
 * `selectedDate.toIso8601String()`, cioe' la mezzanotte del giorno che si sta
 * guardando. ⛔ Tutte le voci di oggi hanno la stessa ora, e quell'ora e'
 * 00:00.
 *
 * 💡 Questi test guardano il **contesto che arriva al modello**, non il testo
 * che ne esce: il testo lo decide un modello, il contesto lo decidiamo noi — ed
 * e' l'unica delle due cose che si puo' provare.
 *
 * ══ 🚨 DA I5 IL CIBO ARRIVA DALL'APP, E QUI SI PROVA IL SETACCIO ═════════
 *
 * ⛔ Fino al 03/09/2026 questi test scrivevano righe in `food_entries` e
 * lasciavano che il server costruisse `meals` e `week_food`. Dopo I2.5 quella
 * tabella non riceve piu' niente: il diario vive sul telefono.
 *
 * 💡 Quindi qui si manda il payload **come lo manda l'app**, e si prova cio' che
 * il server fa ancora: farlo passare, tagliarlo, e **scartare in silenzio** cio'
 * che non riconosce. ⚠️ Che l'app lo costruisca bene — l'ora dell'ultimo gesto,
 * oggi fuori dalla settimana, la finestra di sette giorni — lo prova
 * `cibo_per_il_consiglio_test.dart`, con gli stessi numeri.
 */
final class ContestoDelConsiglioTest extends TestCase
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

        $this->finta = $this->aiFinta()->willReturnAdvice('Un consiglio.');

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');

        // 🚨 Il fuso si fissa: `scritto_alle` e' un'ora **locale**, e senza
        // questo il test direbbe cose diverse su macchine diverse.
        $this->iscritto->forceFill(['timezone' => 'Europe/Rome'])->save();
        $this->iscritto->accendiLAi();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function alle(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, 'Europe/Rome')->utc());
    }

    /**
     * Un pasto di oggi, **come lo manda l'app**.
     *
     * ⚠️ `scritto_alle` e' un'ora **gia' locale**: il telefono il fuso ce l'ha
     * addosso, e il server non lo riconverte. Vedi `cibo_per_il_consiglio.dart`.
     *
     * @return array<string, mixed>
     */
    private function pasto(string $pasto, int $kcal, int $proteine = 0, string $scrittoAlle = '13:00'): array
    {
        return [
            'meal' => $pasto,
            'kcal' => $kcal,
            'p' => $proteine,
            'c' => 0,
            'f' => 0,
            'scritto_alle' => $scrittoAlle,
        ];
    }

    /**
     * Un giorno della settimana passata, come lo manda l'app.
     *
     * @return array<string, mixed>
     */
    private function giornoPassato(string $giorno, int $kcal, int $proteine = 0): array
    {
        return ['d' => $giorno, 'kcal' => $kcal, 'p' => $proteine, 'c' => 0, 'f' => 0];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function contestoRicevuto(array $extra = []): array
    {
        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query([
                'last_event_at' => Carbon::now()->toIso8601String(),
                ...$extra,
            ]))
            ->assertOk();

        $chiamata = collect($this->finta->calls)->firstWhere('method', 'dailyAdvice');

        $this->assertNotNull($chiamata, 'Il modello non e\' stato chiamato: il contesto non esiste.');

        return $chiamata['args'];
    }

    // ───────────────────────── i pasti ─────────────────────────

    /**
     * 🎯 **Il caso del committente**: alle 10:20 la cena e' gia' scritta.
     *
     * 🚨 Il modello deve poter vedere che quella cena e' stata **scritta** alle
     * 10:05, non mangiata: e' l'unica differenza fra un consiglio giusto e uno
     * che dice *«hai gia' assunto 1.800 kcal e sono le 10»*.
     *
     * ⚠️ Da I5 l'ora la calcola l'app (`scrittaIl` dell'ultima voce del pasto):
     * qui si prova che **arriva intatta al modello**, che e' l'altra meta'.
     */
    #[Test]
    public function il_modello_vede_a_che_ora_e_stato_scritto_ogni_pasto(): void
    {
        $this->alle('2026-08-31 10:20');

        $contesto = $this->contestoRicevuto([
            'meals' => [
                $this->pasto('breakfast', 400, 20, '10:05'),
                $this->pasto('lunch', 700, 45, '10:05'),
                $this->pasto('dinner', 700, 50, '10:05'),
            ],
        ]);

        $pasti = collect($contesto['meals'])->keyBy('meal');

        $this->assertSame(700, $pasti['dinner']['kcal']);
        $this->assertSame(50, $pasti['dinner']['p']);

        /*
         * 🚨 **10:05, non 00:00 e non 08:05.** Il primo sarebbe `eaten_at`, che
         * non contiene l'ora; il secondo sarebbe UTC, e il modello ragionerebbe
         * su un'ora che a Roma non e' mai stata.
         */
        $this->assertSame('10:05', $pasti['dinner']['scritto_alle']);
        $this->assertSame('10:05', $pasti['breakfast']['scritto_alle']);

        // 💡 E l'ora di adesso, che invece la sa il server.
        $this->assertSame('10:20', $contesto['time']);
    }

    /**
     * ⛔ **Una voce senza `meal` non passa**, e non lo dice a nessuno.
     *
     * 🚨 E' la regola del setaccio: il modello legge `meals` come un elenco
     * attribuito ai pasti, e una riga senza etichetta non si puo' attribuire.
     * ⚠️ Scartare in silenzio e' voluto — un client modificato non deve poter
     * far fallire la richiesta di chi non c'entra niente.
     */
    #[Test]
    public function una_voce_senza_pasto_non_arriva_al_modello(): void
    {
        $this->alle('2026-08-31 13:00');

        $contesto = $this->contestoRicevuto([
            'meals' => [
                ['kcal' => 900, 'p' => 40, 'scritto_alle' => '13:00'],
                $this->pasto('lunch', 700, 45),
            ],
        ]);

        $pasti = collect($contesto['meals']);

        $this->assertCount(1, $pasti);
        $this->assertSame('lunch', $pasti->first()['meal']);

        // 💡 E il conteggio segue cio' che e' passato, non cio' che e' arrivato.
        $this->assertSame(1, $contesto['meals_logged']);
    }

    /**
     * 🚨 **I totali arrivano dall'app**, e sono il numero con cui il modello
     * confronta il target.
     *
     * ⛔ Nascevano da `DiaryService::forDate()`, cioe' da `food_entries`: dopo
     * I2.5 quella lettura risponde **zero senza un errore**, e uno zero
     * credibile non si distingue da una giornata a digiuno.
     */
    #[Test]
    public function i_totali_di_oggi_arrivano_dall_app(): void
    {
        $this->alle('2026-08-31 13:00');

        $contesto = $this->contestoRicevuto([
            'eaten_kcal' => 700,
            'eaten_protein_g' => 45,
            'eaten_carbs_g' => 80,
            'eaten_fat_g' => 22,
        ]);

        $this->assertSame(700.0, (float) $contesto['totals']['kcal']);
        $this->assertSame(45.0, (float) $contesto['totals']['protein']);
    }

    /**
     * ⚠️ **Senza niente si manda zero, non `null`.**
     *
     * 💡 E' quello che rispondeva il server per chi non aveva registrato nulla,
     * ed e' la risposta giusta: il modello distingue «non ho segnato niente» da
     * «ho mangiato zero» guardando `meals`, che nel primo caso e' vuoto.
     */
    #[Test]
    public function senza_cibo_i_totali_sono_zero_e_i_pasti_vuoti(): void
    {
        $this->alle('2026-08-31 13:00');

        $contesto = $this->contestoRicevuto();

        $this->assertSame(0.0, (float) $contesto['totals']['kcal']);
        $this->assertSame([], $contesto['meals']);
        $this->assertSame(0, $contesto['meals_logged']);
    }

    // ───────────────────────── la settimana ─────────────────────────

    /**
     * 📅 La settimana del cibo, compressa, arriva **come l'app l'ha composta**.
     *
     * ⛔ Che oggi non ci sia e che l'ordine parta dal piu' recente lo decide
     * l'app (`cibo_per_il_consiglio.dart`), e lo prova il test Dart con gli
     * stessi numeri. 🚨 Qui si prova che il setaccio **non li rimescola**: un
     * riordino qui dentro sarebbe una seconda regola sulla stessa cosa.
     */
    #[Test]
    public function la_settimana_del_cibo_arriva_intatta(): void
    {
        $this->alle('2026-08-31 13:00');

        $contesto = $this->contestoRicevuto([
            'week_food' => [
                $this->giornoPassato('2026-08-30', 2200, 110),
                $this->giornoPassato('2026-08-29', 1800, 90),
            ],
            'eaten_kcal' => 700,
        ]);

        $giorni = collect($contesto['week_food'])->pluck('kcal', 'd');

        $this->assertSame([
            '2026-08-30' => 2200,
            '2026-08-29' => 1800,
        ], $giorni->all(), 'Il setaccio ha cambiato l\'ordine o i numeri.');

        // 💡 E oggi resta dov'era: in `totals`, non nella settimana.
        $this->assertSame(700.0, round((float) $contesto['totals']['kcal'], 1));
    }

    /**
     * ⛔ **Il tetto del setaccio**: oltre 30 voci si taglia.
     *
     * 💡 Sette giorni di dati sono al massimo una decina di righe. Il tetto non
     * serve all'uso normale: serve perche' senza, un client modificato potrebbe
     * allegare mille voci a una richiesta che finisce in un prompt pagato a
     * token.
     */
    #[Test]
    public function la_settimana_ha_un_tetto(): void
    {
        $this->alle('2026-08-31 13:00');

        $troppi = [];

        for ($i = 1; $i <= 50; $i++) {
            $troppi[] = $this->giornoPassato(sprintf('2026-07-%02d', $i % 28 + 1), 2000);
        }

        $contesto = $this->contestoRicevuto(['week_food' => $troppi]);

        $this->assertCount(30, $contesto['week_food']);
    }

    // ───────────────────────── il corpo ─────────────────────────

    /**
     * 🚨 **TDEE e peso arrivano dall'app**, perche' il server non li ha piu'.
     *
     * ⚠️ Il peso che esce dal telefono e' un cambio della decisione S5, chiesto
     * dal committente. 💡 Il server lo **inoltra e non lo conserva**, come fa
     * gia' con sonno e HRV — che sono dati dell'art. 9.
     */
    #[Test]
    public function tdee_e_peso_arrivano_al_modello(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query([
                'last_event_at' => Carbon::now()->toIso8601String(),
                'tdee_kcal' => 2840.4,
                'weight_kg' => 95.85,
                'target_kcal' => 2300,
            ]))
            ->assertOk();

        $contesto = collect($this->finta->calls)->firstWhere('method', 'dailyAdvice')['args'];

        $this->assertSame(2840, $contesto['tdee_kcal']);
        $this->assertSame(95.9, $contesto['weight_kg']);
    }

    /**
     * ⛔ **E non si conserva niente.** Il peso passa e sparisce: se un domani
     * comparisse una colonna, questo test non se ne accorgerebbe — ma il
     * contesto in `ai_advices` si', ed e' li' che si guarderebbe per primo.
     */
    #[Test]
    public function il_peso_non_finisce_in_nessuna_colonna(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query([
                'last_event_at' => Carbon::now()->toIso8601String(),
                'weight_kg' => 95.85,
            ]))
            ->assertOk();

        /*
         * 🎉 **E da I5.3 la prova e' piu' forte di prima.**
         *
         * ⛔ Qui c'era anche `assertDatabaseMissing('ai_advices', ['body' => …])`:
         * cercava il peso dentro il **testo del consiglio**. Quella colonna non
         * esiste piu' — il testo sta sul telefono — quindi la domanda «il peso e'
         * finito nel consiglio salvato?» non si puo' nemmeno porre.
         *
         * 💡 Resta il controllo che conta: **niente** della riga contiene quel
         * numero, in nessuna colonna, comprese quelle che qualcuno aggiungera'
         * domani.
         */
        $riga = AiAdvice::withoutGlobalScopes()->firstOrFail();

        $this->assertStringNotContainsString('95.85', json_encode($riga->toArray()) ?: '');
    }

    /**
     * 🚨 **Le bruciate della settimana NON dipendono dal consenso al sonno.**
     *
     * ⛔ Sono due cose diverse: `sleep_ai_consent_at` copre i dati del sensore —
     * sonno, HRV, battito. Le calorie bruciate con il sonno non c'entrano, e
     * spegnerle con lo stesso interruttore vorrebbe dire togliere a chi ha
     * detto no al sonno anche il quadro delle calorie, senza che nessuno
     * capisca perche'.
     */
    #[Test]
    public function bruciate_e_peso_della_settimana_passano_anche_senza_il_consenso_al_sonno(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->assertNull($this->iscritto->fresh()->sleep_ai_consent_at, 'Il test parte dal caso senza consenso.');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query([
                'last_event_at' => Carbon::now()->toIso8601String(),
                'week_burned' => [['day' => '2026-08-30', 'v' => 620]],
                'week_weight' => [['day' => '2026-08-30', 'v' => 95.9]],
                'week_sleep' => [['day' => '2026-08-30', 'hours' => 7.5]],
            ]))
            ->assertOk();

        $contesto = collect($this->finta->calls)->firstWhere('method', 'dailyAdvice')['args'];

        $this->assertSame(620, $contesto['week_burned'][0]['v']);
        $this->assertSame(95.9, $contesto['week_weight'][0]['v']);

        // ⛔ Il sonno invece no: quello il consenso lo vuole davvero.
        $this->assertArrayNotHasKey('week_sleep', $contesto);
    }

    /**
     * ⛔ **Una serie senza giorno non entra.**
     *
     * 💡 Il modello legge queste serie come una sequenza nel tempo: un valore
     * senza giorno non si puo' mettere in fila, e messo in fila a caso produce
     * un confronto sbagliato che sembra giusto.
     */
    #[Test]
    public function una_voce_senza_giorno_non_entra(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query([
                'last_event_at' => Carbon::now()->toIso8601String(),
                'week_burned' => [['v' => 620], ['day' => '2026-08-30', 'v' => 500]],
            ]))
            ->assertOk();

        $contesto = collect($this->finta->calls)->firstWhere('method', 'dailyAdvice')['args'];

        $this->assertCount(1, $contesto['week_burned']);
        $this->assertSame(500, $contesto['week_burned'][0]['v']);
    }
}
