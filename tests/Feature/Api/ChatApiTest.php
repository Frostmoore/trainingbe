<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * B8 — la chat.
 */
class ChatApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $trainer;

    private User $altroTrainer;

    private User $iscritto;

    private User $estraneo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->altroTrainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'bruno@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->estraneo = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->trainer->assignedMembers()->attach($this->iscritto->id, [
            'tenant_id' => $this->alfa->id, 'assigned_at' => now(),
        ]);

        // 🚨 Il broadcaster della suite e' `reverb` (serve a BroadcastAuthTest,
        // dove l'autorizzazione dei canali va provata davvero). Qui pero' non
        // c'e' nessun processo Reverb in ascolto: senza questo, ogni messaggio
        // aspetterebbe due secondi di timeout di rete. Il caso «il broker non
        // risponde» e' coperto da `MessageSent::announce()`, non da qui.
        Event::fake([MessageSent::class]);
    }

    // ───────────────────────── apertura ─────────────────────────

    #[Test]
    public function a_member_opens_the_chat_with_their_trainer(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->trainer->id])
            ->assertCreated();

        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());
    }

    /**
     * 🚨 Fuori dal legame trainer-iscritto non si apre niente.
     *
     * Una chat aperta verso chiunque della palestra sarebbe, per un iscritto, il
     * modo per scrivere a tutti gli altri iscritti.
     */
    #[Test]
    public function there_is_no_chat_without_an_assignment(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->altroTrainer->id])
            ->assertForbidden();

        $this->assertSame(0, Conversation::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_person_of_another_gym_is_not_reachable(): void
    {
        $altrove = $this->creaUtente($this->beta, UserRole::Trainer, 'clara@beta.test');

        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $altrove->id])
            ->assertNotFound();
    }

    /**
     * 🚨 Due tocchi ravvicinati non creano due thread paralleli.
     *
     * Senza il vincolo unico, i due si scriverebbero in stanze diverse senza
     * capire perche' l'altro non risponde — un guasto che non da' nessun errore.
     */
    #[Test]
    public function opening_twice_returns_the_same_conversation(): void
    {
        $a = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->trainer->id])
            ->assertCreated()->json('data.id');

        $b = $this->comeApp($this->iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $this->trainer->id])
            ->assertCreated()->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());
    }

    // ───────────────────────── messaggi ─────────────────────────

    #[Test]
    public function it_sends_and_reads_messages(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ciao, ho un dubbio sulla scheda'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Ciao, ho un dubbio sulla scheda');

        $this->comeApp($this->trainer)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * `after` e' la strada del polling: solo cio' che e' arrivato dopo.
     *
     * L'app ricade su polling a 15 secondi quando il socket non si apre, e
     * riscaricare il thread intero ogni volta lo renderebbe inutilizzabile su
     * rete mobile.
     */
    #[Test]
    public function the_polling_contract_returns_only_what_is_new(): void
    {
        $c = $this->conversazione();

        $primo = $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Primo'])
            ->json('data.id');

        $this->comeApp($this->trainer)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Secondo']);

        $nuovi = $this->comeApp($this->iscritto)
            ->getJson("/api/v1/conversations/{$c->id}/messages?after={$primo}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $nuovi);
        $this->assertSame('Secondo', $nuovi[0]['body']);
    }

    #[Test]
    public function marking_as_read_clears_the_counter(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ciao']);

        $elenco = $this->comeApp($this->trainer)->getJson('/api/v1/conversations')->assertOk();
        $this->assertSame(1, $elenco->json('data.0.unread'));

        $this->comeApp($this->trainer)->postJson("/api/v1/conversations/{$c->id}/read")->assertOk();

        $dopo = $this->comeApp($this->trainer)->getJson('/api/v1/conversations')->assertOk();
        $this->assertSame(0, $dopo->json('data.0.unread'));
    }

    /**
     * 🚨 Un terzo non entra, nemmeno se e' della stessa palestra.
     *
     * Il global scope da solo lo lascerebbe passare: una conversazione e' fra
     * **due** persone, e il filtro su `trainer_id`/`member_id` e' l'unico che lo
     * dice.
     */
    #[Test]
    public function a_third_party_cannot_read_the_thread(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Riservato']);

        $this->comeApp($this->altroTrainer)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertNotFound();

        $this->comeApp($this->estraneo)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Mi intrometto'])
            ->assertNotFound();
    }

    /**
     * 🚨 **Il titolare della palestra NON legge le chat dei suoi trainer.**
     *
     * È materiale coperto da riservatezza: infortuni, disturbi alimentari,
     * gravidanze, umore. Non è materiale che il titolare abbia titolo di
     * leggere, e **sapere che potrebbe** basta a far smettere le persone di
     * scrivere le cose che contano — cioè a rendere la chat inutile per quello
     * per cui esiste.
     *
     * Il `gym_admin` è il caso più insidioso perché ha il permesso su tutto il
     * resto della palestra: qui deve fermarsi.
     */
    #[Test]
    public function the_gym_owner_cannot_read_the_chats_of_their_trainers(): void
    {
        $titolare = $this->creaUtente($this->alfa, UserRole::GymAdmin, 'titolare@alfa.test');

        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ho un problema al ginocchio']);

        // Non compare nel suo elenco…
        $elenco = $this->comeApp($titolare)->getJson('/api/v1/conversations')->assertOk();
        $this->assertSame([], $elenco->json('data'));

        // …e nemmeno andando dritti sull'id.
        $this->comeApp($titolare)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertNotFound();

        // E non ci può nemmeno scrivere dentro.
        $this->comeApp($titolare)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ci sono anch\'io'])
            ->assertNotFound();

        $this->comeApp($titolare)
            ->postJson("/api/v1/conversations/{$c->id}/read")
            ->assertNotFound();
    }

    /**
     * 🚨 Nemmeno il super admin, ed è l'unica policy senza la sua scorciatoia.
     *
     * Ha già una via legittima e **tracciata** per entrare nei dati di un
     * cliente — l'impersonazione, che scrive chi–chi–quando in `audit_logs`. Una
     * scorciatoia qui sarebbe un accesso **non tracciato** allo stesso materiale,
     * e renderebbe quella traccia una formalità aggirabile.
     */
    #[Test]
    public function not_even_the_super_admin_reads_a_conversation(): void
    {
        $god = $this->creaSuperAdmin();

        $c = $this->conversazione();

        $this->assertFalse(
            Gate::forUser($god)->allows('view', $c),
            'Il super admin può leggere una chat senza lasciare traccia: '
            .'l\'impersonazione tracciata diventerebbe una formalità aggirabile.',
        );
    }

    /** La regola vale anche per l'altro trainer, che è il caso già coperto. */
    #[Test]
    public function the_policy_only_lets_the_two_participants_through(): void
    {
        $c = $this->conversazione();

        $this->assertTrue(Gate::forUser($this->trainer)->allows('view', $c));
        $this->assertTrue(Gate::forUser($this->iscritto)->allows('view', $c));

        $this->assertFalse(Gate::forUser($this->altroTrainer)->allows('view', $c));
        $this->assertFalse(Gate::forUser($this->estraneo)->allows('view', $c));

        // E nessuno la cancella: appartiene a due persone, e cancellarla
        // sarebbe anche il modo per far sparire una richiesta scomoda.
        $this->assertFalse(Gate::forUser($this->trainer)->allows('delete', $c));
    }

    #[Test]
    public function sending_a_message_broadcasts_it(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ciao'])
            ->assertCreated();

        Event::assertDispatched(MessageSent::class);
    }

    /** L'elenco si ordina per ultimo messaggio, non per data di creazione. */
    #[Test]
    public function the_list_is_ordered_by_the_last_message(): void
    {
        $this->trainer->assignedMembers()->attach($this->estraneo->id, [
            'tenant_id' => $this->alfa->id, 'assigned_at' => now(),
        ]);

        $primaChat = $this->conversazione();
        $secondaChat = $this->ctx()->runAs($this->alfa,
            fn () => Conversation::between($this->trainer, $this->estraneo));

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$primaChat->id}/messages", ['body' => 'A']);

        $this->comeApp($this->estraneo)
            ->postJson("/api/v1/conversations/{$secondaChat->id}/messages", ['body' => 'B']);

        $elenco = $this->comeApp($this->trainer)->getJson('/api/v1/conversations')->assertOk()->json('data');

        $this->assertSame($secondaChat->id, $elenco[0]['id']);
    }

    // ───────────────────────── token dei dispositivi ─────────────────────────

    #[Test]
    public function the_same_device_token_is_stored_once(): void
    {
        foreach ([1, 2] as $volta) {
            $this->comeApp($this->iscritto)
                ->postJson('/api/v1/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
                ->assertCreated();

            unset($volta);
        }

        $this->assertSame(1, \App\Models\DeviceToken::withoutGlobalScopes()->count());
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function conversazione(): Conversation
    {
        return $this->ctx()->runAs($this->alfa,
            fn () => Conversation::between($this->trainer, $this->iscritto));
    }
}
