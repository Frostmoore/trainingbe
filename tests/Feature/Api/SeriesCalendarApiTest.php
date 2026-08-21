<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Training\SeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * C3 e C4 — le serie per i grafici e il calendario.
 *
 * Sono la stessa materia letta in due modi, e i test più importanti non
 * riguardano i numeri ma **le regole di aggregazione**: sono quelle che, se
 * sbagliate, producono un grafico credibile e falso.
 */
class SeriesCalendarApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    /**
     * Il giorno locale della persona, non quello di Greenwich.
     *
     * ── 🚨 Il difetto che questo helper chiude (13/08/2026, 00:20) ───────────
     *
     * Questi test costruivano gli istanti con `Carbon::today()`, che e'
     * **mezzanotte UTC**. Con l'utente su `Europe/Rome` (UTC+2) c'e' una
     * finestra di due ore, fra la mezzanotte locale e quella di Greenwich, in
     * cui «oggi» sono due giorni diversi: il test scriveva un allenamento «5
     * giorni fa» in UTC e il server ne contava 6 in locale.
     *
     * ⚠️ **Passavano per ventidue ore su ventiquattro.** Un test cosi' non e'
     * verde: e' verde quasi sempre, il che e' peggio, perche' il giorno che
     * diventa rosso nessuno crede che sia lui ad avere ragione.
     *
     * 💡 E' esattamente la classe di difetto chiusa da A3 — vedi
     * `GiornoLocale` — che nei test era rimasta.
     */
    private function oggi(int $giorniFa = 0): Carbon
    {
        return $this->iscritto->giornoDiOggi()->menoGiorni($giorniFa)->locale();
    }

    private function mangia(Carbon $quando, int $kcal, ?User $chi = null, ?float $proteine = null): FoodEntry
    {
        $chi ??= $this->iscritto;

        return $this->ctx()->runAs($chi->tenant, fn () => FoodEntry::create([
            'user_id' => $chi->getKey(),
            'eaten_at' => $quando,
            'meal' => MealType::Lunch,
            'description' => 'Pasto',
            'kcal' => $kcal,
            'protein' => $proteine,
        ]));
    }

    private function allena(Carbon $inizio, int $minuti = 60): WorkoutSession
    {
        return $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->getKey(),
            'started_at' => $inizio,
            'ended_at' => $inizio->copy()->addMinutes($minuti),
        ]));
    }

    /*
     * ⚠️ Qui c'era `pesa()`, e i due test sulla serie del peso. Cancellati in
     * S5.4: `body_metrics` non esiste piu' e la serie la costruisce l'app dal
     * proprio archivio (`weightSeriesProvider`).
     *
     * 🚨 L'helper era rimasto orfano dopo la cancellazione dei test: PHP non se
     * ne accorge, perche' risolve la classe solo quando il codice gira. Un
     * riferimento a un modello inesistente dentro un metodo mai chiamato passa
     * la suite e **esplode il giorno che qualcuno riusa l'helper**.
     */

    // ───────────────────────── C3: calorie ─────────────────────────

    #[Test]
    public function the_calorie_series_has_one_slot_per_day_even_when_empty(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 2000);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'day')
            ->assertJsonCount(7, 'data.labels')
            ->assertJsonCount(7, 'data.consumed');

        // A differenza del peso, qui la griglia dei giorni serve: il grafico a
        // barre confronta giorni consecutivi, e un giorno saltato deve vedersi.
        $this->assertSame(2000, $risposta->json('data.consumed.6'));
        $this->assertSame(0, $risposta->json('data.consumed.0'));
    }

    /**
     * 🚨 **La regola che protegge dall'illusione di essere in deficit.**
     *
     * Dividere per 7 quando si è registrato 3 giorni su 7 non dà la media della
     * settimana: dà un numero più basso, e fa credere di aver mangiato meno di
     * quanto si è mangiato.
     */
    #[Test]
    public function averages_only_count_the_days_with_data(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 2000);
        $this->mangia($this->oggi(1)->setTime(13, 0), 2400);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        // (2000 + 2400) / 2 = 2200, non / 7 = 629.
        $this->assertSame(2200, $risposta->json('data.averages.consumed'));
        $this->assertSame(2, $risposta->json('data.averages.days_with_data'));
    }

    /**
     * 🚨 Il valore manuale è una dichiarazione complessiva, non un contributo.
     * Sommarlo alle sessioni raddoppierebbe la giornata di chi corregge il
     * numero dopo essersi allenato.
     */
    #[Test]
    public function the_manual_burn_replaces_the_sessions_it_does_not_add_up(): void
    {
        $this->allena($this->oggi()->setTime(18, 0));

        $this->ctx()->runAs($this->alfa, fn () => DailyBurn::create([
            'user_id' => $this->iscritto->getKey(),
            'date' => $this->iscritto->giornoDiOggi()->etichetta,
            'kcal' => 800,
        ]));

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        $this->assertSame(800, $risposta->json('data.burned.6'));
    }

    #[Test]
    public function beyond_three_months_it_aggregates_by_month_as_a_daily_average(): void
    {
        // Due giorni nello stesso mese: la media al giorno è la loro media, non
        // la somma. Una somma mensile accanto a barre giornaliere sembra
        // un'esplosione di calorie.
        $this->mangia($this->oggi()->setTime(13, 0), 2000);
        $this->mangia($this->oggi(1)->setTime(13, 0), 3000);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=365')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'month');

        $ultimo = count($risposta->json('data.consumed')) - 1;

        $this->assertSame(2500, $risposta->json("data.consumed.{$ultimo}"));
    }

    #[Test]
    public function the_ninety_two_day_threshold_is_the_line_between_days_and_months(): void
    {
        $this->assertSame(92, SeriesService::GIORNI_PRIMA_DI_AGGREGARE);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=90')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'day');
    }

    #[Test]
    public function days_zero_means_the_whole_history_and_does_not_scroll(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 2000);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=0')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'month')
            // «Tutto» non ha un periodo precedente: non c'è niente prima di
            // tutto, e l'app deve poter spegnere il pulsante senza conoscere
            // questa regola.
            ->assertJsonPath('data.can_go_back', false);
    }

    #[Test]
    public function the_offset_scrolls_by_whole_windows(): void
    {
        $this->mangia($this->oggi(8)->setTime(13, 0), 1800);

        // offset 1 con days 7 = la settimana prima, non «un giorno prima».
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7&offset=1')
            ->assertOk();

        $this->assertContains(1800, $risposta->json('data.consumed'));

        $questaSettimana = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        $this->assertNotContains(1800, $questaSettimana->json('data.consumed'));
    }

    #[Test]
    public function an_unknown_metric_is_rejected(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=umore&days=7')
            ->assertStatus(422)
            ->assertJsonValidationErrors('metric');
    }

    #[Test]
    public function an_unsupported_window_is_rejected_not_silently_changed(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=13')
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }

    #[Test]
    public function the_series_of_a_gym_mate_never_leak_in(): void
    {
        $compagno = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
        $this->mangia($this->oggi()->setTime(13, 0), 9999, $compagno);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        $this->assertNotContains(9999, $risposta->json('data.consumed'));
    }

    // ───────────────────────── C4: calendario ─────────────────────────

    #[Test]
    public function the_month_grid_starts_on_monday_and_ends_on_sunday(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar?month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-03');

        $celle = $risposta->json('data.days');

        // Marzo 2026 comincia di domenica: senza il riallineamento al lunedì,
        // ogni data finirebbe sotto l'intestazione sbagliata — un errore che
        // non dà nessun segnale.
        $this->assertSame('2026-02-23', $celle[0]['date']);
        $this->assertSame('2026-04-05', $celle[count($celle) - 1]['date']);
        $this->assertSame(0, count($celle) % 7, 'La griglia non è fatta di settimane intere.');
    }

    #[Test]
    public function the_cells_outside_the_month_are_marked(): void
    {
        $celle = collect($this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar?month=2026-03')
            ->assertOk()
            ->json('data.days'));

        $this->assertFalse($celle->firstWhere('date', '2026-02-23')['in_month']);
        $this->assertTrue($celle->firstWhere('date', '2026-03-01')['in_month']);
    }

    /**
     * 🚨 `null` = niente registrato · `0` = registrato e vale zero.
     *
     * Appiattirli renderebbe una giornata a digiuno indistinguibile da una
     * dimenticata, e l'app direbbe a chi si è scordato di scrivere che ha
     * mangiato zero.
     */
    #[Test]
    public function a_day_with_nothing_logged_is_null_not_zero(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 0);

        $celle = collect($this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar?month='.$this->oggi()->format('Y-m'))
            ->assertOk()
            ->json('data.days'));

        $oggi = $celle->firstWhere('date', $this->iscritto->giornoDiOggi()->etichetta);
        $domani = $celle->firstWhere('date', $this->iscritto->giornoDiOggi()->piuGiorni(1)->etichetta);

        $this->assertSame(0, $oggi['kcal'], 'Un pasto da zero calorie è comunque un pasto registrato.');

        if ($domani !== null) {
            $this->assertNull($domani['kcal']);
        }
    }

    #[Test]
    public function the_day_detail_lists_meals_and_sessions(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 700);
        $this->allena($this->oggi()->setTime(18, 0));

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/'.$this->iscritto->giornoDiOggi()->etichetta)
            ->assertOk()
            ->assertJsonPath('data.kcal', 700)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.duration_min', 60);
    }

    /**
     * 🚨 `Carbon::createFromFormat()` **non solleva niente** su una data
     * impossibile: trabocca. `2026-13-45` diventa il 14 febbraio 2027, in
     * silenzio. Un `try/catch` intorno sembra un controllo e non controlla
     * niente — l'app riceverebbe 200 con i dati di un giorno mai chiesto.
     */
    #[Test]
    public function an_impossible_date_is_rejected_instead_of_overflowing(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/2026-13-45')
            ->assertStatus(422);

        // 31 novembre non esiste: traboccherebbe al 1° dicembre.
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/2026-11-31')
            ->assertStatus(422);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/2026-02-28')
            ->assertOk();
    }

    #[Test]
    public function the_week_view_has_seven_days_in_the_same_shape_as_the_month(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/week')
            ->assertOk();

        $celle = $risposta->json('data.days');

        $this->assertCount(7, $celle);
        $this->assertArrayHasKey('kcal', $celle[0]);
        $this->assertArrayHasKey('burned', $celle[0]);
        $this->assertArrayHasKey('workouts', $celle[0]);
    }

    #[Test]
    public function the_calendar_of_a_gym_mate_is_never_visible(): void
    {
        $compagno = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
        $this->mangia($this->oggi()->setTime(13, 0), 4321, $compagno);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/'.$this->iscritto->giornoDiOggi()->etichetta)
            ->assertOk()
            ->assertJsonPath('data.kcal', 0)
            ->assertJsonCount(0, 'data.entries');
    }

    #[Test]
    public function series_and_calendar_need_authentication(): void
    {
        $this->getJson('/api/v1/series?metric=weight')->assertUnauthorized();
        $this->getJson('/api/v1/calendar')->assertUnauthorized();
    }

    /**
     * Le proteine per giorno — 3b-O.7.3, 21/08/2026.
     *
     * 📌 Servono alla scheda «Allenamento» dell'app, che riassume gli ultimi
     * sette giorni.
     *
     * 🚨 **Il test che conta non e' che il campo esista**: e' che sia
     * raggruppato **nel fuso di chi guarda**, come le calorie. ⚠️ Copiare la
     * forma di `kcalAssunte` senza copiarne la sostanza darebbe grammi giusti
     * sui giorni sbagliati — un difetto che nessuno vede, perche' il totale
     * della settimana torna lo stesso.
     */
    #[Test]
    public function the_calorie_series_also_carries_protein_per_day(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 2000, proteine: 120.0);
        $this->mangia($this->oggi()->setTime(20, 0), 600, proteine: 30.0);
        $this->mangia($this->oggi(1)->setTime(13, 0), 1800, proteine: 90.0);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk()
            ->assertJsonCount(7, 'data.protein');

        // I due pasti di oggi si sommano; ieri sta nel suo giorno.
        $this->assertSame(150, $risposta->json('data.protein.6'));
        $this->assertSame(90, $risposta->json('data.protein.5'));

        // ⛔ Un giorno senza pasti e' `0` qui, e chi disegna decide che
        // significa: l'app lo tratta come «non lo so» e nasconde la voce.
        $this->assertSame(0, $risposta->json('data.protein.0'));
    }

    /**
     * ⚠️ Un pasto senza proteine dichiarate non fa esplodere la somma.
     *
     * 🚨 `protein` e' facoltativo su `food_entries`: chi scrive solo le calorie
     * — che e' il caso piu' comune quando si segna al volo — lascia `null`, e
     * un `sum()` su `null` in PHP e' `0`, non un errore. Questo test lo fissa,
     * perche' il giorno che diventasse un errore fallirebbe **tutta** la serie,
     * non solo le proteine.
     */
    #[Test]
    public function meals_without_protein_do_not_break_the_series(): void
    {
        $this->mangia($this->oggi()->setTime(13, 0), 2000);
        $this->mangia($this->oggi()->setTime(20, 0), 600, proteine: 40.0);

        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        $this->assertSame(40, $risposta->json('data.protein.6'));
        $this->assertSame(2600, $risposta->json('data.consumed.6'));
    }
}
