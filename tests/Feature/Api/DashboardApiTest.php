<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * D4 — il riepilogo della schermata principale.
 *
 * 🚨 **I test su HRV, battito e sonno sono stati cancellati in S1.7**, insieme
 * alle tabelle e all'endpoint di ingest: i dati del sensore restano sul telefono
 * di chi li produce (decisione D9 di `todo-2026-08-11.md`).
 *
 * ⚠️ **Non sono stati persi: sono stati riscritti in Dart** — le stesse regole,
 * caso per caso, in `trainingfe/test/`. Quei test descrivevano **decisioni**
 * («la media esclude oggi», «un valore assoluto di HRV non si giudica»), non
 * implementazioni, e perderne uno significava perdere la decisione. La mappa sta
 * in `plan_security_and_retention.md` §S4.4.
 *
 * Qui resta invece un test nuovo, `the_dashboard_no_longer_carries_any_body_signal()`,
 * che verifica che quelle sezioni **non tornino**.
 */
class DashboardApiTest extends TestCase
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

    #[Test]
    public function the_dashboard_answers_with_every_section(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'date',
                'now',
                'hour',
                'day_progress_pct',
                'nutrition' => ['totals', 'targets', 'burned', 'entries_count'],
                'training' => ['last_30_days', 'days_since_last', 'recent'],
                // ⚠️ Solo il peso OBIETTIVO: peso e scostamento sono dati del
                // corpo e da S5 non stanno piu' sul server (D9-bis).
                'body' => ['target_weight_kg'],
            ]]);
    }

    /**
     * 🚨 **Il riepilogo non porta più nessun segnale del corpo** — S1.
     *
     * Non è un test di cortesia: è il presidio della decisione. Se un giorno
     * qualcuno rimettesse `sleep` o `vitals` nella risposta — «tanto è solo un
     * campo» — questo test si accorgerebbe che i dati sanitari sono tornati sul
     * server, che è la cosa che l'intero piano esiste per impedire.
     *
     * ⚠️ Vale anche al contrario: se domani servisse davvero rimetterli, questo
     * test è il punto in cui si è costretti a leggere §C11 e §C12 di
     * `todo-2026-08-11.md` prima di cambiare idea.
     */
    #[Test]
    public function the_dashboard_no_longer_carries_any_body_signal(): void
    {
        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('sleep', $dati);
        $this->assertArrayNotHasKey('vitals', $dati);
    }

    /**
     * 🚨 **L'ora è parte del riepilogo**, non un dettaglio.
     *
     * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono due
     * situazioni opposte. Senza `day_progress_pct` né l'app né l'AI possono
     * distinguerle, e daranno lo stesso consiglio in entrambi i casi.
     */
    #[Test]
    public function the_day_progress_is_measured_on_waking_hours_not_on_24(): void
    {
        $servizio = app(DashboardService::class);

        /*
         * 🚨 **Le ore sono quelle dell'orologio di chi guarda** — A3.
         *
         * ⚠️ Prima si costruivano con `Carbon::today()->setTime(8, 0)`, cioe'
         * **le 8 UTC**, e il test passava solo perche' il servizio leggeva l'ora
         * nello stesso fuso sbagliato. A Roma quelle sono le 10, e la giornata
         * sveglia e' passata al 24% invece che al 12%.
         */
        $alle = fn (int $ora): Carbon => Carbon::parse(
            $this->iscritto->dataDiOggi().' '.sprintf('%02d:00', $ora),
            $this->iscritto->fusoOrario(),
        );

        $alle8 = $servizio->forToday($this->iscritto, $alle(8));
        $alle20 = $servizio->forToday($this->iscritto, $alle(20));
        $alle3 = $servizio->forToday($this->iscritto, $alle(3));

        // Alle 8 del mattino è passato circa il 12% della giornata sveglia
        // (6→23), non il 33% delle ore: usare le 24 ore farebbe sembrare
        // «indietro» chiunque a metà mattina.
        $this->assertSame(12, $alle8['day_progress_pct']);
        $this->assertSame(82, $alle20['day_progress_pct']);
        $this->assertSame(0, $alle3['day_progress_pct']);
    }

    #[Test]
    public function the_dashboard_counts_the_days_since_the_last_workout(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => WorkoutSession::create([
            'user_id' => $this->iscritto->getKey(),
            'started_at' => Carbon::today()->subDays(5)->setTime(18, 0),
            'ended_at' => Carbon::today()->subDays(5)->setTime(19, 0),
        ]));

        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        // «Non ti alleni da 5 giorni» è l'informazione che fa tornare in
        // palestra; un elenco di date costringe a fare il conto a mente.
        $this->assertSame(5, $risposta->json('data.training.days_since_last'));
        $this->assertSame(1, $risposta->json('data.training.last_30_days'));
    }

    #[Test]
    public function the_dashboard_sums_what_was_eaten_today(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => Carbon::now(),
            'meal' => MealType::Lunch,
            'description' => 'Pasto',
            'kcal' => 700,
        ]));

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.nutrition.totals.kcal', 700.0)
            ->assertJsonPath('data.nutrition.entries_count', 1);
    }

    #[Test]
    public function the_dashboard_of_a_gym_mate_never_leaks_in(): void
    {
        $compagno = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $compagno->getKey(),
            'eaten_at' => Carbon::now(),
            'meal' => MealType::Lunch,
            'description' => 'Pasto di Luigi',
            'kcal' => 4321,
        ]));

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.nutrition.totals.kcal', 0.0)
            ->assertJsonPath('data.nutrition.entries_count', 0);
    }

    #[Test]
    public function the_dashboard_needs_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
