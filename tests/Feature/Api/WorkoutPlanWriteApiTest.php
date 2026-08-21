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
 * C2 — l'iscritto si scrive le proprie schede (decisione D1).
 *
 * Il cuore di questi test non è il CRUD: è la **distinzione fra la scheda
 * prescritta dal trainer e quella che l'iscritto si è scritto**, che convivono
 * nella stessa tabella e si distinguono per `created_by`.
 */
class WorkoutPlanWriteApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $iscritto;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'coach@alfa.test');
    }
}
