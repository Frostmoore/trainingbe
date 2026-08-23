<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
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
                /*
                 * ⛔ **Niente piu' `burned` ne' `training`** — FASE 11.6,
                 * 21/08/2026. Nascevano da `workout_sessions` e `daily_burns`,
                 * che stanno sul telefono di chi si allena.
                 *
                 * 🚨 I campi sono usciti dalla risposta invece di valere zero:
                 * uno zero avrebbe detto «non ti sei mosso» a chi si e'
                 * allenato due ore, e nessuno se ne sarebbe accorto.
                 */
                'nutrition' => ['totals', 'targets', 'entries_count'],
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
         * ⚠️ Prima si costruivano con `$this->oggi()->setTime(8, 0)`, cioe'
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

    /**
     * ⛔ **Il riepilogo non conta piu' gli allenamenti** — FASE 11.6.
     *
     * 📌 Il committente, 16/08: *«Niente, nemmeno se si allena»*. Le sedute
     * stanno sul telefono: non e' che non le mostriamo, il server non ce le ha.
     *
     * 🚨 Il test resta — rovesciato — perche' e' il posto dove qualcuno
     * cercherebbe di rimettercele. ⚠️ Chi lo facesse rimetterebbe anche il dato
     * sul server, che e' esattamente cio' che questa fase ha tolto.
     */
    #[Test]
    public function the_dashboard_no_longer_knows_about_workouts(): void
    {
        /*
         * Qui si creava una seduta per dimostrare che la dashboard la ignora.
         * Dalla FASE 11.6.3 la tabella non esiste piu': la premessa non si puo'
         * costruire, e l'asserzione vale ancora di piu' — la chiave `training`
         * non c'e' perche' non c'e' proprio da nessuna parte.
         */
        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/dashboard')->assertOk();

        // ⚠️ `null` perche' la chiave non c'e', non perche' vale zero.
        $this->assertNull($risposta->json('data.training'));
        $this->assertNull($risposta->json('data.nutrition.burned'));
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
