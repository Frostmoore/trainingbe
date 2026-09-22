<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📱 **Il profilo non sta più sul server** — 19/09/2026.
 *
 * 📌 Il committente: *«assicurati che non ci scriva niente di personale
 * (esattamente come peso altezza e data di nascita)»* · *«puoi rimuovere i dati
 * di tutti dal server, quella roba non serve più»* · *«Ok fallo»*.
 *
 * ⛔ Qui c'erano i test di C1 (il profilo scritto dall'app), degli orari salvati
 * e delle traduzioni verso le formule: il profilo e le formule stanno nell'app.
 * 💡 Restano le tre rotte, per il trasloco di un telefono che non l'ha fatto.
 */
class ProfileApiTest extends TestCase
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

    /**
     * 🚨 **La tabella non esiste**: non c'è niente da tenere, né da perdere.
     *
     * 🆕 19/09/2026: prima via i dati del corpo, poi anche le note dello staff
     * — 📌 *«non servono a molto sul server»*. Il profilo sta sul telefono.
     */
    #[Test]
    public function the_profiles_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('profiles'));
    }

    /** 💡 Un telefono che non ha traslocato trova un profilo vuoto, e chiude. */
    #[Test]
    public function the_move_finds_an_empty_profile_and_closes(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.sex', null)
            ->assertJsonPath('data.height_cm', null)
            ->assertJsonPath('data.meal_hours', null);

        $this->comeApp($this->iscritto)->deleteJson('/api/v1/profile')->assertOk();
    }

    /** ⛔ 410 e non un «ok» che ignora: un'app vecchia deve sapere che non ha salvato. */
    #[Test]
    public function the_server_no_longer_accepts_the_profile(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/profile', ['sex' => 'm', 'height_cm' => 178])
            ->assertStatus(410)
            ->assertJsonPath('code', 'profilo_sul_telefono');
    }

    #[Test]
    public function the_profile_needs_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['height_cm' => 180])->assertUnauthorized();
        $this->deleteJson('/api/v1/profile')->assertUnauthorized();
    }

    /*
     * ⛔ Qui c'era `in_the_small_hours_the_meal_is_the_last_of_the_day_not_breakfast`: provava il diario sul server, che dal 22/09/2026 non
     * c'è più (A1 di `plan_blocco_A.md`). Il diario sta sul telefono.
     */

}
