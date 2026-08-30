<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\FoodEntry;
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
     * Scrive una voce nel diario.
     *
     * ⚠️ `eaten_at` alla **mezzanotte** del giorno, come fa l'app davvero:
     * scrivere qui un'ora plausibile renderebbe il test piu' facile del
     * mondo reale, ed e' proprio l'ora che non c'e'.
     */
    private function segna(string $giorno, string $pasto, int $kcal, int $proteine = 0): void
    {
        FoodEntry::create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => Carbon::parse($giorno, 'Europe/Rome')->startOfDay(),
            'meal' => $pasto,
            'description' => 'Qualcosa',
            'kcal' => $kcal,
            'protein' => $proteine,
            'carbs' => 0,
            'fat' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function contestoRicevuto(): array
    {
        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?last_event_at='.urlencode(Carbon::now()->toIso8601String()))
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
     */
    #[Test]
    public function il_modello_vede_a_che_ora_e_stato_scritto_ogni_pasto(): void
    {
        $this->alle('2026-08-31 10:05');

        $this->segna('2026-08-31', 'breakfast', 400, 20);
        $this->segna('2026-08-31', 'lunch', 700, 45);
        $this->segna('2026-08-31', 'dinner', 700, 50);

        $this->alle('2026-08-31 10:20');

        $contesto = $this->contestoRicevuto();

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

        // 💡 E l'ora di adesso, per il confronto.
        $this->assertSame('10:20', $contesto['time']);
    }

    /**
     * ⚠️ **L'ora e' quella dell'ultimo gesto, non del primo.**
     *
     * 💡 Chi aggiunge il pane alla cena alle 21:40 sta ancora cenando: prendere
     * la prima voce direbbe che quella cena e' vecchia di un'ora.
     */
    #[Test]
    public function di_un_pasto_scritto_a_pezzi_vale_l_ora_dell_ultimo(): void
    {
        $this->alle('2026-08-31 20:30');
        $this->segna('2026-08-31', 'dinner', 600);

        $this->alle('2026-08-31 21:40');
        $this->segna('2026-08-31', 'dinner', 150);

        $this->alle('2026-08-31 22:00');

        $pasti = collect($this->contestoRicevuto()['meals'])->keyBy('meal');

        $this->assertSame(750, $pasti['dinner']['kcal']);
        $this->assertSame('21:40', $pasti['dinner']['scritto_alle']);
    }

    /** ⛔ I pasti vuoti non entrano: una riga a zero non e' un'informazione. */
    #[Test]
    public function i_pasti_senza_niente_dentro_non_arrivano(): void
    {
        $this->alle('2026-08-31 13:00');
        $this->segna('2026-08-31', 'lunch', 700);

        $pasti = collect($this->contestoRicevuto()['meals']);

        $this->assertCount(1, $pasti);
        $this->assertSame('lunch', $pasti->first()['meal']);
    }

    // ───────────────────────── la settimana ─────────────────────────

    /**
     * 📅 La settimana del cibo, compressa e **senza oggi**.
     *
     * ⛔ Oggi sta gia' in `totals` e in `meals`: ripeterlo darebbe al modello
     * due versioni della stessa giornata, una completa e una da mettere in fila
     * con le altre.
     */
    #[Test]
    public function la_settimana_del_cibo_non_comprende_oggi(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->segna('2026-08-29', 'lunch', 1800, 90);
        $this->segna('2026-08-30', 'lunch', 2200, 110);
        $this->segna('2026-08-31', 'lunch', 700, 45);

        $contesto = $this->contestoRicevuto();

        $giorni = collect($contesto['week_food'])->pluck('kcal', 'd');

        $this->assertSame([
            '2026-08-30' => 2200,
            '2026-08-29' => 1800,
        ], $giorni->all(), 'La settimana deve escludere oggi ed essere dal piu\' recente.');

        // 💡 E oggi resta dov\'era.
        $this->assertSame(700.0, round((float) $contesto['totals']['kcal'], 1));
    }

    /** ⛔ Piu' indietro della finestra non si guarda. */
    #[Test]
    public function oltre_la_settimana_non_si_guarda(): void
    {
        $this->alle('2026-08-31 13:00');

        $this->segna('2026-08-10', 'lunch', 3000);
        $this->segna('2026-08-30', 'lunch', 2200);

        $giorni = collect($this->contestoRicevuto()['week_food'])->pluck('d');

        $this->assertSame(['2026-08-30'], $giorni->all());
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

        $this->assertDatabaseMissing('ai_advices', ['body' => '95.85']);

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
