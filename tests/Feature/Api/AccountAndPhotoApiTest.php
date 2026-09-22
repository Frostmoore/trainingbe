<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Tenancy\CreaTenantPersonale;
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

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test', [
            'username' => 'mario.alfa',
        ]);
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'coach@alfa.test');
    }

    /*
     * ⛔ `sessione()` non esiste piu': `workout_sessions` e' caduta con la FASE
     * 11.6.3, e la cancellazione dell'account non ha piu' niente da cancellare
     * di la'. Quei dati stanno sul telefono.
     */

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
     * ⛔ **E a chi non ha una palestra non si parla di palestra** — 14/09/2026.
     *
     * 🚨 Le due righe di «Resteranno» erano fisse, e a un iscritto individuale
     * dicevano due cose false: i messaggi «nella conversazione del tuo trainer»
     * e le schede «restano alla palestra». ⚠️ Su una schermata irreversibile
     * una riga che non riguarda chi legge fa smettere di leggere anche il
     * resto — e il resto è l'elenco di quello che sta per perdere.
     */
    #[Test]
    public function the_preview_does_not_mention_a_gym_to_someone_without_one(): void
    {
        $solo = app(CreaTenantPersonale::class)(
            'dasolo@esempio.test',
            'dasolo@esempio.test',
            ['password' => self::FAKE_PASSWORD],
        );

        $risposta = $this->comeApp($solo)
            ->getJson('/api/v1/account/deletion-preview')
            ->assertOk();

        $resta = implode(' ', $risposta->json('data.kept'));

        $this->assertStringNotContainsString('palestra', $resta);
        $this->assertStringNotContainsString('trainer', $resta);

        // 💡 E non è vuoto: «non resta niente» sarebbe un'altra frase falsa.
        $this->assertNotEmpty($risposta->json('data.kept'));
    }

    /** ⚖️ Mentre a chi in palestra ci sta, le due righe servono e restano. */
    #[Test]
    public function but_a_gym_member_still_reads_them(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/account/deletion-preview')
            ->assertOk();

        $this->assertStringContainsString(
            'trainer',
            implode(' ', $risposta->json('data.kept')),
        );
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
        /*
         * ⛔ Qui si seminava una voce di diario e si controllava che sparisse: dal
         * 26/09/2026 il diario non è più sul server. Resta da provare che
         * l'account si cancelli.
         */
        $this->comeApp($this->iscritto)
            ->deleteJson('/api/v1/account', ['password' => TestCase::FAKE_PASSWORD])
            ->assertNoContent();

        $cancellato = User::withoutGlobalScopes()->withTrashed()->find($this->iscritto->getKey());

        $this->assertNotNull($cancellato);
        $this->assertNotSame('mario@alfa.test', $cancellato->email);
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

    /**
     * 🆕 🚨 **`/me` non manda più il profilo** — 19/09/2026.
     *
     * ⛔ Qui si pretendeva la chiave `profile` sempre presente (C7.2): dentro
     * c'erano sesso, data di nascita, età e altezza, a ogni accesso. 📌 *«assicurati
     * che non ci scriva niente di personale»*: il profilo sta sul telefono.
     */
    #[Test]
    public function me_does_not_send_the_profile_anymore(): void
    {
        $risposta = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertArrayNotHasKey('profile', $risposta->json('data'));
    }
}
