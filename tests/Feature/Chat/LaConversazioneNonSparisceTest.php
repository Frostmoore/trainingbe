<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 💬 **La conversazione non sparisce mai** — D9, 19/09/2026.
 *
 * 📌 Il committente: *«La conversazione non deve né sparire né nascondersi mai.
 * Se io mi disiscrivo da un trainer, al massimo non gli posso più scrivere ma i
 * messaggi vecchi ce li devo avere»*.
 *
 * ⛔ Prima, uscendo dalla palestra, il filo col suo trainer spariva
 * dall'elenco e aprirlo dava 404 (`Conversation::scopeForUser` filtrava sui
 * tenant). Trovato nel giro del 18/09.
 */
class LaConversazioneNonSparisceTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    #[Test]
    public function chi_esce_dalla_palestra_rilegge_il_filo_ma_non_scrive(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'mario@esempio.test');

        $c = Conversation::between($trainer, $iscritto);
        $this->scrivi($iscritto, $c)->assertStatus(201);

        app(EsciDaUnaPalestra::class)($iscritto->fresh());

        $fuori = User::withoutGlobalScopes()->findOrFail($iscritto->getKey());

        // 💬 Il filo c'e' ancora, per tutti e due.
        foreach ([$fuori, $trainer] as $chi) {
            $ids = array_column($this->actingAs($chi)->getJson('/api/v1/conversations')->assertOk()->json('data'), 'id');
            $this->assertContains($c->id, $ids, "Il filo e' sparito per {$chi->email}.");
        }

        // 📖 E si rilegge.
        $this->actingAs($fuori)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 🔒 Ma non si scrive piu', da nessuna delle due parti.
        $this->scrivi($fuori, $c)->assertStatus(403);
        $this->scrivi($trainer, $c)->assertStatus(403);
    }

    /** ⚖️ Chi e' ancora legato scrive come prima: la regola nuova non chiude niente di vivo. */
    #[Test]
    public function chi_e_ancora_nella_palestra_scrive_come_prima(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'mario@esempio.test');

        $c = Conversation::between($trainer, $iscritto);

        $this->scrivi($iscritto, $c)->assertStatus(201);
        $this->scrivi($trainer, $c)->assertStatus(201);
    }

    /** 💡 Una busta di forma valida: il server non ha le chiavi, ma pretende la forma. */
    private function scrivi(User $chi, Conversation $c): TestResponse
    {
        return $this->actingAs($chi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'envelope_version' => 1,
            'nonce' => str_repeat('a', 32),
            'body' => base64_encode('busta finta per il test'),
        ]);
    }
}
