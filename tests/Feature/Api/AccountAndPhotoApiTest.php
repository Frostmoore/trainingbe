<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\FoodEntry;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Account\AccountEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\ScriveBusteCifrate;
use Tests\TestCase;

/**
 * C5, C6 e C7 — le foto legate all'allenamento, l'eliminazione dell'account e
 * le rifiniture del contratto.
 */
class AccountAndPhotoApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use ScriveBusteCifrate;

    private Tenant $alfa;

    private User $iscritto;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test', [
            'username' => 'mario.alfa',
        ]);
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'coach@alfa.test');
    }

    private function sessione(?User $di = null): WorkoutSession
    {
        $di ??= $this->iscritto;

        return $this->ctx()->runAs($di->tenant, fn () => WorkoutSession::create([
            'user_id' => $di->getKey(),
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]));
    }

    // ───────────────── C5: foto legate all'allenamento ─────────────────

    // ─────────────── C6: eliminazione dell'account ───────────────

    #[Test]
    public function the_deletion_preview_says_what_goes_and_what_stays(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/account/deletion-preview')
            ->assertOk()
            ->assertJsonPath('data.irreversible', true)
            ->assertJsonStructure(['data' => ['deleted', 'kept', 'irreversible']]);
    }

    /**
     * 🚨 È l'unica azione irreversibile dell'app: un telefono sbloccato
     * lasciato sul tavolo non deve bastare a compierla.
     */
    #[Test]
    public function deleting_the_account_requires_the_password(): void
    {
        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => 'quella-sbagliata'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNotNull(User::withoutGlobalScopes()->find($this->iscritto->getKey()));
    }

    #[Test]
    public function deleting_the_account_wipes_the_personal_data(): void
    {
        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->iscritto->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Lunch,
            'description' => 'Pasto',
            'kcal' => 500,
        ]));

        $this->sessione();

        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => TestCase::FAKE_PASSWORD])
            ->assertNoContent();

        $id = $this->iscritto->getKey();

        $this->assertSame(0, FoodEntry::withoutGlobalScopes()->where('user_id', $id)->count());
        $this->assertSame(0, WorkoutSession::withoutGlobalScopes()->where('user_id', $id)->count());
        $this->assertSame(0, Profile::withoutGlobalScopes()->where('user_id', $id)->count());
    }

    /**
     * 🚨 **Il messaggio resta, la persona no.**
     *
     * Cancellare i messaggi svuoterebbe la conversazione dal lato del trainer,
     * che si ritroverebbe le proprie risposte senza le domande — e quella è la
     * sua documentazione professionale.
     */
    #[Test]
    public function the_messages_survive_but_stop_being_attributable(): void
    {
        $conversazione = $this->ctx()->runAs($this->alfa, function (): Conversation {
            $c = Conversation::create([
                'member_id' => $this->iscritto->getKey(),
                'trainer_id' => $this->trainer->getKey(),
            ]);

            Message::create([
                'conversation_id' => $c->getKey(),
                'sender_id' => $this->iscritto->getKey(),
                // Da S6 il corpo e' una busta cifrata: il testo qui non c'e'
                // mai stato, e il test verifica che la **riga** sopravviva alla
                // cancellazione dell'account, non cosa c'era scritto.
                ...$this->busta('Domanda sulla scheda'),
            ]);

            return $c;
        });

        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => TestCase::FAKE_PASSWORD])
            ->assertNoContent();

        $messaggio = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversazione->getKey())
            ->first();

        $this->assertNotNull($messaggio, 'La conversazione del trainer è stata svuotata.');
        // ⚠️ Da S6 il confronto è sulla **busta**, non sul testo — che qui non
        // c'è mai stato. La regola però è la stessa e vale ancora: la busta non
        // si riscrive, perché riscriverla sarebbe falsificare una conversazione
        // davvero avvenuta. E dopo la cancellazione non è nemmeno più possibile:
        // le chiavi per rifarla non esistono più da nessuna parte.
        $this->assertSame(
            $this->corpoDi('Domanda sulla scheda'),
            $messaggio->body,
            'La busta è stata riscritta.',
        );

        // L'autore c'è ancora come riga, ma non è più nessuno.
        $autore = User::withoutGlobalScopes()->withTrashed()->find($messaggio->sender_id);

        $this->assertSame(AccountEraser::NOME_ANONIMO, $autore->name);
        $this->assertNotSame('mario@alfa.test', $autore->email);
        $this->assertNull($autore->username);
        $this->assertNotNull($autore->deleted_at);
    }

    #[Test]
    public function after_the_deletion_the_tokens_no_longer_work(): void
    {
        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => TestCase::FAKE_PASSWORD])
            ->assertNoContent();

        // Nessun dispositivo continua a scrivere su un account che non c'è più.
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/profile')
            ->assertUnauthorized();
    }

    // ─────────────── C7: rifiniture del contratto ───────────────

    #[Test]
    public function me_returns_the_username(): void
    {
        // Si sceglie in registrazione, si usa per accedere, e prima di C7 non si
        // rivedeva più da nessuna parte: chi lo dimenticava non aveva modo di
        // recuperarlo dall'app.
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'mario.alfa');
    }

    #[Test]
    public function me_always_has_the_profile_key_even_when_there_is_no_profile(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        // La chiave c'è sempre: `null` significa «non c'è un profilo», non
        // «non te l'ho mandato». Due casi che richiedono schermate diverse.
        $this->assertArrayHasKey('profile', $risposta->json('data'));
        $this->assertNull($risposta->json('data.profile'));
    }
}
