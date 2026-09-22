<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📅 Il calendario del server — C4, e com'è dal 22/09/2026.
 *
 * ⛔ **Qui c'erano anche i test di `GET series`** (le serie delle calorie) e il
 * cibo nelle celle del calendario. Il 22/09/2026 il diario ha lasciato il server
 * (A1 di `plan_blocco_A.md`): la rotta delle serie non c'è più, e il calendario
 * dà solo **la griglia e i titoli**. Le calorie, gli allenamenti e i pasti li
 * mette l'app, che li ha sul telefono (`calendarProvider`, `giornataProvider`).
 *
 * 🚨 Quello che resta da difendere è la **forma**: le app già installate la
 * leggono, e una chiave sparita è un errore a schermo.
 */
class CalendarApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

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
     * 🚨 Le calorie delle celle sono **`null`**, sempre: le mette l'app. ⛔ E non
     * `0`, che per l'app vuol dire «hai mangiato zero».
     */
    #[Test]
    public function the_cells_carry_no_calories_the_app_fills_them(): void
    {
        $celle = collect($this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar?month=2026-03')
            ->assertOk()
            ->json('data.days'));

        $this->assertTrue($celle->every(fn (array $c): bool => array_key_exists('kcal', $c) && $c['kcal'] === null));
    }

    /**
     * Il dettaglio del giorno dà la data e il titolo in italiano. ⚠️ `kcal` ed
     * `entries` restano, vuoti, per le app già installate; `sessions` non c'è
     * dalla FASE 11.6.3.
     */
    #[Test]
    public function the_day_detail_carries_only_date_and_title(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/2026-09-02')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-02')
            ->assertJsonPath('data.title', 'Mercoledì 02 settembre 2026')
            ->assertJsonPath('data.kcal', null)
            ->assertJsonCount(0, 'data.entries');

        $this->assertNull($risposta->json('data.sessions'));
    }

    /**
     * 🚨 `Carbon::createFromFormat()` **non solleva niente** su una data
     * impossibile: trabocca. `2026-13-45` diventa il 14 febbraio 2027, in
     * silenzio.
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
        $celle = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/calendar/week')
            ->assertOk()
            ->json('data.days');

        $this->assertCount(7, $celle);
        $this->assertArrayHasKey('kcal', $celle[0]);
        $this->assertArrayNotHasKey('burned', $celle[0]);
        $this->assertArrayNotHasKey('workouts', $celle[0]);
    }

    #[Test]
    public function the_calendar_needs_authentication(): void
    {
        $this->getJson('/api/v1/calendar')->assertUnauthorized();
    }
}
