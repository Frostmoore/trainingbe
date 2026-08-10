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
 * C22 — «a chi posso scrivere».
 *
 * 🚨 **Senza questo endpoint la chat era una stanza in cui non si poteva
 * entrare.** `POST /conversations` vuole l'id della persona, e l'app non aveva
 * nessun modo di saperlo: chi non aveva gia' una conversazione vedeva una
 * schermata vuota e nessun modo di cominciarne una.
 *
 * ⚠️ Il rischio, aggiungendolo, e' l'opposto: che diventi la **rubrica di tutta
 * la palestra**. Per un iscritto sarebbe l'elenco di tutti gli altri iscritti —
 * ed e' esattamente cio' che i test qui sotto impediscono.
 */
class ChatContactsApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    private function collega(User $trainer, User $membro): void
    {
        $this->ctx()->runAs($this->alfa, function () use ($trainer, $membro): void {
            $trainer->assignedMembers()->attach($membro->getKey(), [
                'tenant_id' => $this->alfa->getKey(),
                'assigned_at' => now(),
            ]);
        });
    }

    #[Test]
    public function un_iscritto_collegato_vede_il_proprio_trainer(): void
    {
        $this->collega($this->trainer, $this->iscritto);

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/conversations/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->trainer->getKey())
            ->assertJsonPath('data.0.name', $this->trainer->name)
            ->assertJsonPath('data.0.is_trainer', true);
    }

    /**
     * 🚨 Il test che impedisce all'endpoint di diventare una rubrica.
     *
     * Senza il legame non compare nessuno — nemmeno un trainer della stessa
     * palestra, nemmeno un altro iscritto.
     */
    #[Test]
    public function senza_legame_non_compare_nessuno(): void
    {
        $altroIscritto = $this->creaUtente($this->alfa, UserRole::Member, 'luca@alfa.test');
        $this->creaUtente($this->alfa, UserRole::Trainer, 'bea@alfa.test');

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/conversations/contacts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // E nemmeno provando ad aprirla a mano.
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $altroIscritto->getKey()])
            ->assertStatus(403);
    }

    #[Test]
    public function le_persone_di_un_altra_palestra_non_compaiono_mai(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $trainerBeta = $this->creaUtente($beta, UserRole::Trainer, 'carla@beta.test');

        $this->collega($this->trainer, $this->iscritto);

        $r = $this->comeApp($this->iscritto)->getJson('/api/v1/conversations/contacts')->assertOk();

        $ids = array_column($r->json('data'), 'id');

        $this->assertContains($this->trainer->getKey(), $ids);
        $this->assertNotContains($trainerBeta->getKey(), $ids);
    }

    /** Un trainer vede i propri assegnati: la stessa relazione, letta al contrario. */
    #[Test]
    public function un_trainer_vede_i_propri_iscritti(): void
    {
        $this->collega($this->trainer, $this->iscritto);

        $this->comeApp($this->trainer)
            ->getJson('/api/v1/conversations/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->iscritto->getKey())
            ->assertJsonPath('data.0.is_trainer', false);
    }

    /**
     * ⚠️ Aprire due volte non crea due fili.
     *
     * `Conversation::between()` e' idempotente, ed e' cio' che evita che chi
     * tocca due volte il pulsante si ritrovi due conversazioni con la stessa
     * persona — e met&agrave; dei messaggi in una e met&agrave; nell'altra.
     */
    #[Test]
    public function aprire_due_volte_da_la_stessa_conversazione(): void
    {
        $this->collega($this->trainer, $this->iscritto);

        $prima = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->trainer->getKey()])
            ->assertCreated()
            ->json('data.id');

        $dopo = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->trainer->getKey()])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($prima, $dopo);
    }
}
