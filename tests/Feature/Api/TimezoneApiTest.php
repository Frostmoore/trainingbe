<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * `PUT /account/timezone` — il fuso che solo il telefono conosce.
 *
 * 🚨 **Chiude il debito lasciato aperto da A3**: la colonna `users.timezone`
 * esisteva e la catena la leggeva, ma non la scriveva nessuno — quindi tutti
 * ricadevano sul fuso della palestra, e chi vive altrove vedeva il giorno
 * sbagliato esattamente come prima della correzione.
 */
class TimezoneApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $mario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->mario = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_writes_the_timezone_and_answers_with_the_day_it_implies(): void
    {
        // Le 00:30 del 12 agosto a Roma: le 22:30 dell'11 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-08-11 22:30:00', 'UTC'));

        $this->comeApp($this->mario)
            ->putJson('/api/v1/account/timezone', ['timezone' => 'America/New_York'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'America/New_York')
            // 💡 A New York sono ancora le 18:30 dell'11: il giorno che torna
            // indietro è la prova che il fuso è stato **usato**, non solo scritto.
            ->assertJsonPath('data.today', '2026-08-11');

        $this->assertSame('America/New_York', $this->mario->fresh()->timezone);
    }

    /**
     * 🚨 Una stringa arbitraria qui dentro farebbe **lanciare** `setTimezone()`
     * dentro `GiornoLocale`, cioè in mezzo a ogni lettura datata: 500 su
     * dashboard, diario e calendario insieme, per un campo che nessuno
     * ricollegherebbe al guasto.
     */
    #[Test]
    public function it_refuses_anything_that_is_not_a_real_iana_identifier(): void
    {
        foreach (['Europa/Roma', 'CEST', '+02:00', 'Mars/Olympus', ''] as $spazzatura) {
            $this->comeApp($this->mario)
                ->putJson('/api/v1/account/timezone', ['timezone' => $spazzatura])
                ->assertStatus(422);
        }

        $this->assertNull($this->mario->fresh()->timezone);
    }

    /**
     * ⚠️ Senza questo, ogni avvio dell'app muoverebbe `updated_at`, e la colonna
     * smetterebbe di dire «quando è cambiato qualcosa di questa persona» per
     * dire «quando ha aperto l'app» — cioè non risponderebbe più alla domanda
     * per cui esiste.
     */
    #[Test]
    public function sending_the_same_timezone_twice_does_not_touch_the_row(): void
    {
        $this->comeApp($this->mario)
            ->putJson('/api/v1/account/timezone', ['timezone' => 'Europe/Rome'])
            ->assertOk();

        $primaVolta = $this->mario->fresh()->updated_at;

        Carbon::setTestNow(Carbon::now()->addHour());

        $this->comeApp($this->mario)
            ->putJson('/api/v1/account/timezone', ['timezone' => 'Europe/Rome'])
            ->assertOk();

        $this->assertEquals($primaVolta, $this->mario->fresh()->updated_at);
    }

    #[Test]
    public function it_needs_a_token(): void
    {
        $this->putJson('/api/v1/account/timezone', ['timezone' => 'Europe/Rome'])
            ->assertStatus(401);
    }

    /**
     * 💡 Il giro completo: scrivere il fuso cambia **cosa vede** la persona.
     *
     * È il test che dimostra perché questo endpoint esiste — gli altri
     * verificano che scriva, questo che **serva a qualcosa**.
     */
    #[Test]
    public function the_dashboard_follows_the_timezone_just_declared(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 22:30:00', 'UTC'));

        // Senza fuso proprio: quello della palestra, `Europe/Rome` di serie.
        $this->comeApp($this->mario)
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.date', '2026-08-12');

        $this->comeApp($this->mario)
            ->putJson('/api/v1/account/timezone', ['timezone' => 'America/New_York'])
            ->assertOk();

        $this->comeApp($this->mario)
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.date', '2026-08-11');
    }
}
