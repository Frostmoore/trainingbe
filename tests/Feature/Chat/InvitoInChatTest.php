<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Comune;
use App\Models\Conversation;
use App\Models\PlanSubscription;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * L'invito che arriva in chat — Parte M6, 18/08/2026.
 *
 * 🚨 **La decisione del committente che questo file difende**: *«il link
 * d'invito assegna chi lo usa — anche se non abbonato — a chi l'ha creato»*.
 * Diventare allievo di un trainer apre la chat illimitata **con lui**: è un
 * rapporto vero, non un contatto a freddo.
 */
class InvitoInChatTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private User $trainer;

    private User $tizio;

    private ProfiloPubblico $scheda;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class]);

        $comune = Comune::create([
            'codice' => '099014', 'nome' => 'Rimini',
            'chiave' => app(ChiaveComune::class)->da('Rimini'),
            'provincia' => 'RN', 'provincia_nome' => 'Rimini', 'regione' => 'Emilia-Romagna',
            'popolazione' => 150_951, 'lat' => 44.060249, 'lng' => 12.565599, 'attivo' => true,
        ]);

        $suo = $this->creaPalestra('Studio Libero', 'libero', 'LIBE2345');
        $this->trainer = $this->creaUtente($suo, UserRole::FreeTrainer, 'coach@esempio.it');

        $this->scheda = ProfiloPubblico::create([
            'user_id' => $this->trainer->id,
            'comune_id' => $comune->id,
            'titolo' => 'Coach, personal trainer',
            'visibile' => true,
        ]);

        $altro = $this->creaPalestra('Ares', 'ares', 'ARES2345');
        $this->tizio = $this->creaUtente($altro, UserRole::Member, 'tizio@esempio.it');

        // 🚨 Senza abbonamento: è tutto il punto della decisione del committente.
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $altro->id)->delete();
    }

    #[Test]
    public function il_link_dell_invito_lo_costruisce_il_server(): void
    {
        /*
         * 💡 Il messaggio con dentro il link lo **cifra l'app**: la chat è punto
         * a punto e il server non può comporre un messaggio. 🚨 Se potesse
         * comporne uno, potrebbe comporne anche altri — e non è la promessa che
         * abbiamo fatto.
         *
         * ⚠️ Ma l'**indirizzo** lo costruisce il server: lasciarlo comporre
         * all'app vorrebbe dire che il giorno in cui cambia il dominio bisogna
         * pubblicare una versione nuova sugli store, e nel frattempo ogni invito
         * mandato punterebbe da nessuna parte.
         */
        $risposta = $this->actingAs($this->trainer)
            ->postJson('/api/v1/trainer/invites')
            ->assertStatus(201);

        $this->assertStringContainsString('/invito/', (string) $risposta->json('data.url'));
        $this->assertNotEmpty($risposta->json('data.token'));
    }

    #[Test]
    public function chi_riscatta_un_invito_NON_deve_essere_abbonato(): void
    {
        /*
         * 🚨 **La decisione del committente, testuale**: «anche se non
         * abbonato».
         */
        $token = $this->invito();

        $this->actingAs($this->tizio)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertOk()
            ->assertJsonPath('data.trainer.id', $this->trainer->id)
            ->assertJsonPath('data.restanti', null);

        $this->assertTrue(
            $this->tizio->assignedTrainers()->where('users.id', $this->trainer->id)->exists(),
        );
    }

    #[Test]
    public function riscattare_sblocca_il_filo_dove_si_erano_conosciuti(): void
    {
        /*
         * 🚨 **Il punto di M6.2.**
         *
         * ⚠️ Senza, la persona verrebbe assegnata al trainer e resterebbe
         * comunque bloccata nel filo in cui si erano parlati: assegnata, con la
         * storia sotto gli occhi, e la penna ferma.
         */
        $c = Conversation::withoutGlobalScopes()->findOrFail(
            $this->actingAs($this->tizio)
                ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
                ->assertStatus(201)
                ->json('data.id'),
        );

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->tizio, $c)->assertStatus(201);
        }

        $this->scrivi($this->tizio, $c)->assertStatus(403);

        /*
         * ⚠️ Il token si prende **prima**, in una variabile.
         *
         * Scritto in linea — `postJson('.../'.$this->invito().'/...')` — PHP
         * valuta l'argomento **dopo** `actingAs($this->tizio)`, e `invito()`
         * fa `actingAs($this->trainer)`: al momento della POST l'utente attivo
         * era il trainer, che riscattava il proprio invito. Tre test rossi per
         * un ordine di valutazione, non per il codice.
         */
        $token = $this->invito();

        $this->actingAs($this->tizio)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertOk();

        $this->assertSame(TipoConversazione::Iscritto, $c->fresh()->tipo);
        $this->scrivi($this->tizio, $c->fresh())->assertStatus(201);
    }

    #[Test]
    public function se_non_si_erano_mai_scritti_la_conversazione_nasce_gia_senza_limite(): void
    {
        // 💡 L'invito può arrivare anche fuori dalla chat — un messaggio, un QR.
        $token = $this->invito();

        $this->actingAs($this->tizio)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertOk();

        $c = Conversation::withoutGlobalScopes()
            ->where('trainer_id', $this->trainer->id)
            ->where('member_id', $this->tizio->id)
            ->firstOrFail();

        $this->assertSame(TipoConversazione::Iscritto, $c->tipo);
    }

    #[Test]
    public function un_invito_si_usa_una_volta_sola(): void
    {
        $token = $this->invito();

        $this->actingAs($this->tizio)->postJson("/api/v1/inviti/{$token}/riscatta")->assertOk();

        $altro = $this->creaUtente($this->tizio->tenant, UserRole::Member, 'altro@esempio.it');

        $this->actingAs($altro)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertStatus(422);
    }

    #[Test]
    public function ogni_invito_morto_dice_la_stessa_cosa(): void
    {
        /*
         * 🚨 Un messaggio diverso per «non esiste», «scaduto» e «revocato»
         * permetterebbe di provare token a tappeto e capire quali sono esistiti
         * — e quando.
         */
        $scaduto = TrainerInvite::withoutGlobalScopes()->create([
            'tenant_id' => $this->trainer->tenant_id,
            'trainer_id' => $this->trainer->id,
            'token' => str_repeat('a', 40),
            'expires_at' => now()->subDay(),
        ]);

        $messaggi = [];

        foreach ([$scaduto->token, 'token-che-non-e-mai-esistito'] as $token) {
            $messaggi[] = $this->actingAs($this->tizio)
                ->postJson("/api/v1/inviti/{$token}/riscatta")
                ->assertStatus(422)
                ->json('errors.token.0');
        }

        $this->assertCount(1, array_unique($messaggi), 'I due rifiuti devono essere indistinguibili.');
    }

    #[Test]
    public function un_trainer_non_puo_diventare_allievo_di_se_stesso(): void
    {
        /*
         * ⚠️ Capita: si apre il proprio link per vedere come si vede. Senza
         * questo, il trainer finirebbe fra i propri allievi e da lì in ogni
         * conteggio di posti occupati.
         */
        $token = $this->invito();

        $this->actingAs($this->trainer)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertStatus(422);
    }

    #[Test]
    public function riscattare_NON_sposta_la_persona_nel_tenant_del_trainer(): void
    {
        /*
         * 🚨 Chi si allena da solo resta nel proprio spazio: il legame
         * `trainer_member` attraversa i tenant di proposito (F6.1). ⚠️ Spostare
         * i dati sarebbe un trasloco che nessuno ha chiesto, per un rapporto che
         * si può interrompere domani.
         */
        $prima = $this->tizio->tenant_id;
        $token = $this->invito();

        $this->actingAs($this->tizio)
            ->postJson("/api/v1/inviti/{$token}/riscatta")
            ->assertOk();

        $this->assertSame($prima, $this->tizio->fresh()->tenant_id);
    }

    // ────────────────── aiutanti ──────────────────

    private function invito(): string
    {
        return (string) $this->actingAs($this->trainer)
            ->postJson('/api/v1/trainer/invites')
            ->assertStatus(201)
            ->json('data.token');
    }

    private function scrivi(User $chi, Conversation $c): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($chi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'envelope_version' => 1,
            'nonce' => str_repeat('a', 32),
            'body' => base64_encode('busta finta per il test'),
        ]);
    }
}
