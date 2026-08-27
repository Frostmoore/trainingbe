<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ⚖️ Niente AI senza la presa d'atto — 3b-J.3, 27/08/2026.
 *
 * ══ 🚨 COSA DIFENDE QUESTA CLASSE ═════════════════════════════════════════
 *
 * 📌 Il committente: *«tra i consensi mettiamo anche un consenso obbligatorio se
 * vuoi attivare l'ai … l'importante e' che chi attiva l'ai legga questa cosa e
 * vi acconsenta»*.
 *
 * ⛔ **Che l'app mostri una finestra non basta.** Se la protezione vivesse solo
 * nell'interfaccia, basterebbe una chiamata all'API per saltarla — ed e'
 * esattamente il percorso di chi poi si fa male seguendo una frase generata da
 * un modello. 🚨 Questi test provano che il **server** rifiuta.
 */
class PresaDAttoSullAiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $utente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->utente = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');
    }

    #[Test]
    public function accendere_lai_senza_presa_datto_viene_rifiutato(): void
    {
        $this->comeApp($this->utente)
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_disclaimer_required');

        // ⛔ E non e' rimasto niente a meta': il consenso non si e' scritto.
        $this->assertNull($this->utente->fresh()->ai_consent_at);
    }

    #[Test]
    public function con_la_presa_datto_nella_stessa_chiamata_passa(): void
    {
        $this->comeApp($this->utente)
            ->patchJson('/api/v1/account/consents', [
                'ai' => true,
                'ai_disclaimer' => true,
            ])
            ->assertOk();

        $fresco = $this->utente->fresh();

        $this->assertNotNull($fresco->ai_consent_at);

        // 🚨 **E' una data, non un booleano**: l'art. 7(1) chiede di poter
        // dimostrare che il consenso e' stato dato, e quando.
        $this->assertNotNull($fresco->ai_disclaimer_at);
    }

    /**
     * 💡 Chi l'ha gia' accettata non deve rimandarla per forza a ogni chiamata.
     *
     * ⚠️ L'app la ripropone comunque a ogni accensione — leggerla e' il punto —
     * ma il server guarda anche cosa c'e' scritto a database: un client che
     * manda solo `ai` dopo averla accettata non e' un client che sta barando.
     */
    #[Test]
    public function chi_lha_gia_accettata_puo_riaccendere_lai(): void
    {
        $this->utente->registraConsenso('ai_disclaimer_at', true);

        $this->comeApp($this->utente->fresh())
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertOk();

        $this->assertNotNull($this->utente->fresh()->ai_consent_at);
    }

    /**
     * 🚨 **Spegnere l'AI cancella anche la presa d'atto.**
     *
     * ⛔ Non perche' quello che si e' capito si dimentichi, ma perche' chi
     * riaccende **deve rileggere**: e' la richiesta, ed e' l'unica cosa che la
     * rende vera nel tempo. Tenendola, chi spegne e riaccende dopo un anno si
     * ritroverebbe l'AI attiva senza aver mai piu' visto quel testo.
     */
    #[Test]
    public function spegnere_lai_cancella_la_presa_datto(): void
    {
        $this->comeApp($this->utente)
            ->patchJson('/api/v1/account/consents', [
                'ai' => true,
                'ai_disclaimer' => true,
            ])
            ->assertOk();

        $this->comeApp($this->utente->fresh())
            ->patchJson('/api/v1/account/consents', ['ai' => false])
            ->assertOk();

        $fresco = $this->utente->fresh();

        $this->assertNull($fresco->ai_consent_at);
        $this->assertNull($fresco->ai_disclaimer_at);

        // ⛔ E riaccendere senza rileggerla non si puo' piu'.
        $this->comeApp($fresco)
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertStatus(422);
    }

    /** 💡 Si legge insieme agli altri, con la stessa forma. */
    #[Test]
    public function la_presa_datto_si_legge_fra_i_consensi(): void
    {
        $this->comeApp($this->utente)
            ->patchJson('/api/v1/account/consents', [
                'ai' => true,
                'ai_disclaimer' => true,
            ])
            ->assertOk();

        $this->comeApp($this->utente->fresh())
            ->getJson('/api/v1/account/consents')
            ->assertOk()
            ->assertJsonStructure(['data' => ['ai', 'ai_disclaimer']]);
    }

    /**
     * ⚠️ Spegnere non richiede di aver letto niente.
     *
     * 🚨 Chiedere una conferma a chi sta **revocando** un consenso sarebbe un
     * ostacolo alla revoca, che l'art. 7(3) vieta: revocare deve costare quanto
     * concedere, o meno.
     */
    #[Test]
    public function spegnere_lai_non_chiede_niente(): void
    {
        $this->comeApp($this->utente)
            ->patchJson('/api/v1/account/consents', ['ai' => false])
            ->assertOk();
    }
}
