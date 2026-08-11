<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\ChatKey;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\RecoveryKey;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\ScriveBusteCifrate;
use Tests\TestCase;

/**
 * S6 — il server instrada e non capisce.
 *
 * ── 🚨 Cosa dimostra questo file, e cosa no ────────────────────────────────
 *
 * Dimostra che il **backend** conserva buste, le restituisce identiche, non
 * accetta testo in chiaro e non regala chiavi a chi non c'entra.
 *
 * ⚠️ **Non** dimostra che la crittografia sia corretta: quella vive in Dart,
 * dove sta la libreria, ed è provata in `trainingfe/test/crypto/`. Chi cerca
 * «e chi mi dice che una busta si apra davvero solo dai due interessati?» deve
 * guardare lì — è scritto anche in `Tests\Concerns\ScriveBusteCifrate`.
 */
class ChatCryptoApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use ScriveBusteCifrate;

    private Tenant $alfa;

    private User $trainer;

    private User $iscritto;

    private User $estraneo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->estraneo = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');

        $this->trainer->assignedMembers()->attach($this->iscritto->id, [
            'tenant_id' => $this->alfa->id, 'assigned_at' => now(),
        ]);

        Event::fake([MessageSent::class]);
    }

    // ───────────────────── il corpo opaco ─────────────────────

    /**
     * 🚨 **Il test che presidia l'intera fase.**
     *
     * Quello che finisce nel database è ciò che finirebbe in mano a chiunque
     * ottenesse un accesso: un backup, un dipendente, una richiesta sbagliata.
     * Se un giorno qualcuno rimettesse il testo in chiaro — «tanto è più comodo
     * per il pannello» — è qui che si romperebbe.
     */
    #[Test]
    public function what_lands_in_the_database_is_not_readable(): void
    {
        $c = $this->conversazione();
        $busta = $this->busta('Ho di nuovo male alla spalla destra');

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", $busta)
            ->assertCreated();

        $riga = Message::query()->firstOrFail();

        $this->assertSame($busta['body'], $riga->body);
        $this->assertStringNotContainsString('spalla', $riga->body);
        $this->assertStringNotContainsString('spalla', (string) base64_decode($riga->body, true));

        // La busta è completa: senza nonce e versione non si riaprirebbe mai.
        $this->assertSame($busta['nonce'], $riga->nonce);
        $this->assertSame(1, $riga->envelope_version);
    }

    /**
     * 🚨 **Il testo in chiaro viene rifiutato, non accettato in silenzio.**
     *
     * ⚠️ Senza questo vincolo la chat tornerebbe leggibile **un messaggio alla
     * volta** — una versione vecchia dell'app, uno script di prova, un test
     * dimenticato — e niente si romperebbe abbastanza da farsene accorgere.
     */
    #[Test]
    public function plain_text_is_refused(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", ['body' => 'Ciao'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['envelope_version', 'nonce']);

        $this->assertSame(0, Message::query()->count());
    }

    /** Un nonce della lunghezza sbagliata non è un nonce di `crypto_box`. */
    #[Test]
    public function a_malformed_envelope_is_refused(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->postJson("/api/v1/conversations/{$c->id}/messages", [
                'envelope_version' => 1,
                'nonce' => 'corto',
                'body' => $this->corpoDi('x'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nonce']);
    }

    // ───────────────────── le chiavi pubbliche ─────────────────────

    #[Test]
    public function a_person_publishes_their_public_key(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('mario')])
            ->assertCreated();

        $this->assertSame(
            $this->chiaveFinta('mario'),
            ChatKey::query()->where('user_id', $this->iscritto->id)->value('public_key'),
        );
    }

    /** Ripubblicare sostituisce: una persona ha **una** chiave, non un elenco. */
    #[Test]
    public function republishing_replaces_instead_of_piling_up(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('prima')]);
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('seconda')]);

        $this->assertSame(1, ChatKey::query()->count());
        $this->assertSame(
            $this->chiaveFinta('seconda'),
            ChatKey::query()->value('public_key'),
        );
    }

    #[Test]
    public function the_counterpart_key_arrives_through_the_conversation(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->trainer)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('anna')]);

        $this->comeApp($this->iscritto)
            ->getJson("/api/v1/conversations/{$c->id}/key")
            ->assertOk()
            ->assertJsonPath('data.user_id', $this->trainer->id)
            ->assertJsonPath('data.public_key', $this->chiaveFinta('anna'));
    }

    /**
     * 🚨 Un terzo non prende la chiave passando da una conversazione altrui.
     *
     * Le chiavi sono pubbliche, ma **«chi ha una chiave in questo sistema» è già
     * un'informazione**: dice chi è iscritto e chi ha aperto l'app. Per questo
     * si passa sempre da una conversazione a cui si partecipa.
     */
    #[Test]
    public function a_third_party_gets_no_key_from_someone_elses_thread(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->trainer)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('anna')]);

        $this->comeApp($this->estraneo)
            ->getJson("/api/v1/conversations/{$c->id}/key")
            ->assertNotFound();
    }

    /**
     * ⚠️ Chi non ha ancora una chiave dà 404, e l'app deve dirlo.
     *
     * Significa «questa persona non ha ancora aperto l'app»: cifrare verso il
     * nulla produrrebbe un messaggio che nessuno potrà mai leggere.
     */
    #[Test]
    public function a_person_without_a_key_yet_is_a_clear_404(): void
    {
        $c = $this->conversazione();

        $this->comeApp($this->iscritto)
            ->getJson("/api/v1/conversations/{$c->id}/key")
            ->assertNotFound();
    }

    // ───────────────────── il pacchetto incartato ─────────────────────

    /**
     * ⚠️ **204 e non 404**: «non hai ancora creato una password di recupero» è
     * lo stato normale di chi si è appena registrato, non un errore.
     */
    #[Test]
    public function no_recovery_package_yet_is_not_an_error(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/account/recovery-key')
            ->assertNoContent();
    }

    #[Test]
    public function the_wrapped_package_goes_up_and_comes_back_identical(): void
    {
        $pacchetto = $this->pacchettoFinto();

        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', $pacchetto)
            ->assertCreated();

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/account/recovery-key')
            ->assertOk()
            ->assertJsonPath('data.salt', $pacchetto['salt'])
            ->assertJsonPath('data.nonce', $pacchetto['nonce'])
            ->assertJsonPath('data.wrapped_key', $pacchetto['wrapped_key'])
            ->assertJsonPath('data.ops_limit', $pacchetto['ops_limit'])
            ->assertJsonPath('data.mem_limit', $pacchetto['mem_limit']);
    }

    /**
     * 🚨 **Il server non conserva parametri che dichiarano un KDF finto.**
     *
     * Il pacchetto sta da noi: se il database uscisse, le password si
     * proverebbero *offline*, senza il limite di tentativi che protegge un
     * login. Il costo di Argon2id è l'unica difesa che resta, e un client
     * malfatto — o manomesso — che dichiarasse due passate su 8 kB la
     * annullerebbe.
     */
    #[Test]
    public function a_ridiculous_kdf_cost_is_refused(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', [
                ...$this->pacchettoFinto(),
                'ops_limit' => 1,
                'mem_limit' => 8192,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ops_limit', 'mem_limit']);

        $this->assertSame(0, RecoveryKey::query()->count());
    }

    /**
     * Il cambio password, visto dal server: un pacchetto che ne sostituisce un
     * altro. Non c'è nessun ramo di codice dedicato, e non deve essercene —
     * la verifica della vecchia password è **crittografica**, e avviene sul
     * telefono nel momento in cui il vecchio pacchetto si riapre.
     */
    #[Test]
    public function changing_the_password_is_just_a_replacement(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', $this->pacchettoFinto('vecchia'));

        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', $this->pacchettoFinto('nuova'))
            ->assertCreated();

        $this->assertSame(1, RecoveryKey::query()->count());
        $this->assertSame(
            $this->pacchettoFinto('nuova')['wrapped_key'],
            RecoveryKey::query()->value('wrapped_key'),
        );
    }

    /** Il pacchetto è personale: nessuno vede quello di un altro. */
    #[Test]
    public function nobody_reads_someone_elses_package(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', $this->pacchettoFinto());

        $this->comeApp($this->estraneo)
            ->getJson('/api/v1/account/recovery-key')
            ->assertNoContent();
    }

    // ───────────────────── cancellazione dell'account ─────────────────────

    /**
     * 🚨 **Cancellare l'account porta via anche le chiavi.**
     *
     * ⚠️ È esattamente il difetto di `health_readings`: una tabella nuova con
     * `user_id` che nessuno ricollega ad `AccountEraser`. Lasciare il pacchetto
     * incartato vorrebbe dire tenere da noi, per sempre, materiale contro cui
     * provare password — di una persona che ha chiesto di sparire.
     */
    #[Test]
    public function erasing_the_account_takes_the_keys_with_it(): void
    {
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/account/recovery-key', $this->pacchettoFinto());
        $this->comeApp($this->iscritto)
            ->putJson('/api/v1/chat-key', ['public_key' => $this->chiaveFinta('mario')]);

        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => TestCase::FAKE_PASSWORD])
            ->assertNoContent();

        $this->assertSame(0, RecoveryKey::query()->where('user_id', $this->iscritto->id)->count());
        $this->assertSame(0, ChatKey::query()->where('user_id', $this->iscritto->id)->count());
    }

    // ───────────────────────── interni ─────────────────────────

    private function conversazione(): Conversation
    {
        return $this->ctx()->runAs(
            $this->alfa,
            fn () => Conversation::between($this->trainer, $this->iscritto),
        );
    }

    /** 32 byte in base64: la forma di una chiave pubblica X25519. */
    private function chiaveFinta(string $etichetta): string
    {
        return base64_encode(hash('sha256', "chiave:{$etichetta}", true));
    }

    /** @return array<string, mixed> */
    private function pacchettoFinto(string $etichetta = 'x'): array
    {
        return [
            'version' => 1,
            'kdf' => 'argon2id13',
            'ops_limit' => 3,
            'mem_limit' => 64 * 1024 * 1024,
            'salt' => base64_encode(substr(hash('sha256', "salt:{$etichetta}", true), 0, 16)),
            'nonce' => base64_encode(substr(hash('sha256', "nonce:{$etichetta}", true), 0, 24)),
            'wrapped_key' => base64_encode(hash('sha512', "incarto:{$etichetta}", true)),
        ];
    }
}
