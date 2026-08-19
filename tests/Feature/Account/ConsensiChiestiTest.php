<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * «Gliel'ho chiesto» — FASE 2-bis, 19/08/2026.
 *
 * ── 🚨 Cosa difende questa data ────────────────────────────────────────────
 *
 * Le tre colonne `*_consent_at` dicono quando qualcuno ha detto **sì**. Nessuna
 * dice se gliel'abbiamo **chiesto**, e senza quell'informazione due situazioni
 * molto diverse sono lo stesso stato — tre `null`:
 *
 * | | Cosa fare |
 * |---|---|
 * | Non gliel'abbiamo mai chiesto | **chiedere** |
 * | Gliel'abbiamo chiesto e ha detto no a tutto | **non chiedere più** |
 *
 * ⚠️ Il difetto che chiude: chi rifiuta tutto si vede riproporre la stessa
 * domanda **a ogni reinstallazione** — e la seconda volta la risposta è «no» più
 * in fretta, perché è diventata un fastidio.
 */
class ConsensiChiestiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@olimpo.it');
    }

    #[Test]
    public function di_partenza_non_e_mai_stato_chiesto_niente(): void
    {
        $this->actingAs($this->iscritto)
            ->getJson('/api/v1/account/consents')
            ->assertOk()
            ->assertJsonPath('data.chiesti_il', null);
    }

    /**
     * 🚨 **Il caso che conta**: chiesto e rifiutato tutto.
     *
     * ⚠️ I tre consensi restano `null` — è un no — ma `chiesti_il` c'è, e
     * l'app da lì sa di non doverlo richiedere.
     */
    #[Test]
    public function chi_rifiuta_tutto_resta_segnato_come_gia_interpellato(): void
    {
        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/account/consents/chiesti')
            ->assertOk();

        $stato = $this->actingAs($this->iscritto)
            ->getJson('/api/v1/account/consents')
            ->assertOk()
            ->json('data');

        $this->assertNotNull($stato['chiesti_il']);

        // I consensi restano quelli che sono: chiedere non è concedere.
        $this->assertNull($stato['health']);
        $this->assertNull($stato['ai']);
        $this->assertNull($stato['sleep_ai']);
    }

    /**
     * ⚠️ **Idempotente**: la prima volta vince. Riscriverla a ogni apertura
     * sposterebbe in avanti una data che serve a sapere **da quando** quella
     * persona ha già visto la schermata.
     */
    #[Test]
    public function la_seconda_chiamata_non_sposta_la_data(): void
    {
        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/account/consents/chiesti')
            ->assertOk();

        $prima = $this->iscritto->fresh()->consensi_chiesti_il;

        Carbon::setTestNow(now()->addHours(3));

        try {
            $this->actingAs($this->iscritto)
                ->postJson('/api/v1/account/consents/chiesti')
                ->assertOk();

            $this->assertEquals($prima, $this->iscritto->fresh()->consensi_chiesti_il);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 💡 Segnarla non tocca nessun consenso: è un fatto sulla nostra interfaccia. */
    #[Test]
    public function segnare_non_concede_niente(): void
    {
        $this->actingAs($this->iscritto)
            ->patchJson('/api/v1/account/consents', ['ai' => true])
            ->assertOk();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/account/consents/chiesti')
            ->assertOk();

        $fresco = $this->iscritto->fresh();

        $this->assertNotNull($fresco->ai_consent_at, 'Il consenso dato resta dato.');
        $this->assertNull($fresco->health_consent_at, 'E quello non dato resta non dato.');
    }

    /** 🚨 Senza sessione non si tocca niente. */
    #[Test]
    public function un_estraneo_non_puo_segnare_niente(): void
    {
        $this->postJson('/api/v1/account/consents/chiesti')->assertStatus(401);

        $this->assertNull($this->iscritto->fresh()->consensi_chiesti_il);
    }
}
