<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B8.2 — chi puo' ascoltare un canale privato.
 *
 * 🚨 **E' l'ultimo controllo che c'e'.** Una volta iscritto a un canale, il
 * client riceve tutto quello che ci passa: non esiste un secondo filtro a valle.
 * Per questo qui si verifica anche il caso che sembra improbabile — un utente di
 * un'altra palestra che indovina un id — perche' gli id sono numeri progressivi
 * globali e indovinarli e' banale.
 */
class BroadcastAuthTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $trainer;

    private User $iscritto;

    private User $estraneo;

    private Conversation $conversazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->estraneo = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->conversazione = $this->ctx()->runAs($this->alfa,
            fn () => Conversation::between($this->trainer, $this->iscritto));
    }

    private function autorizza(User $utente): \Illuminate\Testing\TestResponse
    {
        return $this->comeApp($utente)->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-conversation.'.$this->conversazione->id,
            'socket_id' => '1234.5678',
        ]);
    }

    #[Test]
    public function a_participant_is_authorised(): void
    {
        $this->autorizza($this->iscritto)->assertOk();
        $this->autorizza($this->trainer)->assertOk();
    }

    #[Test]
    public function a_third_party_of_the_same_gym_is_not(): void
    {
        $this->autorizza($this->estraneo)->assertForbidden();
    }

    #[Test]
    public function someone_from_another_gym_is_not(): void
    {
        $altrove = $this->creaUtente($this->beta, UserRole::Member, 'clara@beta.test');

        $this->autorizza($altrove)->assertForbidden();
    }

    /**
     * 🚨 La rotta per l'app deve accettare il **token**, non la sessione.
     *
     * Quella di serie sta nel gruppo `web`: un client bearer prenderebbe un 419
     * che sembra un problema di rete, e la chat via socket non si aprirebbe mai.
     */
    #[Test]
    public function the_app_route_accepts_a_bearer_token(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-conversation.'.$this->conversazione->id,
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();

        $this->autorizza($this->iscritto)->assertOk();
    }
}
