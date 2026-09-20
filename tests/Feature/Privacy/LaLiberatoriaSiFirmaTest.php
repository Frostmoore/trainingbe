<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\UserRole;
use App\Models\LiberatoriaFirmata;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\LeLiberatorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ✍️ **Chi vede i dati di un altro prima firma** — art. 28 GDPR, 20/09/2026.
 *
 * 📌 Il committente, 20/09: *«Ruolo della palestra responsabile, dovrà firmare
 * la liberatoria specifica per le palestre (idem per i trainer)»*.
 */
class LaLiberatoriaSiFirmaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra();
    }

    #[Test]
    public function a_un_iscritto_non_serve_firmare_niente(): void
    {
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@esempio.test');

        $this->assertNull(app(LeLiberatorie::class)->serveA($iscritto));

        $this->comeApp($iscritto)
            ->getJson('/api/v1/account/liberatoria')
            ->assertOk()
            ->assertJsonPath('data.serve', false)
            ->assertJsonPath('data.testo', null);
    }

    #[Test]
    public function al_trainer_serve_e_il_testo_arriva_con_la_sua_versione(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@esempio.test', liberatoriaFirmata: false);

        $risposta = $this->comeApp($trainer)
            ->getJson('/api/v1/account/liberatoria')
            ->assertOk()
            ->assertJsonPath('data.serve', true)
            ->assertJsonPath('data.tipo', LeLiberatorie::TRAINER)
            ->assertJsonPath('data.versione', LeLiberatorie::VERSIONI[LeLiberatorie::TRAINER]);

        // 💡 Il testo è quello vero, non un segnaposto.
        $this->assertStringContainsString('responsabile del trattamento', (string) $risposta->json('data.testo'));
    }

    #[Test]
    public function alla_palestra_tocca_quella_della_palestra(): void
    {
        $titolare = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'titolare@esempio.test', liberatoriaFirmata: false);

        $this->assertSame(LeLiberatorie::PALESTRA, app(LeLiberatorie::class)->serveA($titolare));
    }

    /** 🚨 La firma è un atto: resta scritta, con versione, data e provenienza. */
    #[Test]
    public function firmare_lascia_una_traccia_e_toglie_il_muro(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach2@esempio.test', liberatoriaFirmata: false);

        $this->comeApp($trainer)
            ->postJson('/api/v1/account/liberatoria')
            ->assertStatus(201)
            ->assertJsonPath('data.serve', false);

        $firma = LiberatoriaFirmata::query()->where('user_id', $trainer->getKey())->sole();

        $this->assertSame(LeLiberatorie::TRAINER, $firma->tipo);
        $this->assertSame(LeLiberatorie::VERSIONI[LeLiberatorie::TRAINER], $firma->versione);
        $this->assertNotNull($firma->accettata_il);

        // ⚖️ E dopo non serve più.
        $this->assertNull(app(LeLiberatorie::class)->serveA($trainer->fresh()));
    }

    /** 💡 Ripremere il pulsante non crea un secondo atto identico. */
    #[Test]
    public function firmare_due_volte_non_raddoppia_la_firma(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach3@esempio.test', liberatoriaFirmata: false);

        app(LeLiberatorie::class)->firma($trainer, LeLiberatorie::TRAINER);
        app(LeLiberatorie::class)->firma($trainer, LeLiberatorie::TRAINER);

        $this->assertSame(1, LiberatoriaFirmata::query()->where('user_id', $trainer->getKey())->count());
    }

    /**
     * 🚨 **Una versione nuova si rifirma.**
     *
     * ⚠️ Il testo cambierà (il legale deve ancora rivederlo): un'accettazione
     * «per sempre» di un documento che cambia non dimostrerebbe niente.
     */
    #[Test]
    public function una_versione_vecchia_non_vale_per_quella_nuova(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach4@esempio.test', liberatoriaFirmata: false);

        LiberatoriaFirmata::create([
            'user_id' => $trainer->getKey(),
            'tenant_id' => $this->palestra->getKey(),
            'tipo' => LeLiberatorie::TRAINER,
            'versione' => 'trainer-v0',
            'accettata_il' => now()->subYear(),
        ]);

        $this->assertSame(LeLiberatorie::TRAINER, app(LeLiberatorie::class)->serveA($trainer));
    }

    /** ⛔ E il pannello non si apre finché non ha firmato. */
    #[Test]
    public function il_pannello_manda_alla_firma_finche_non_e_firmata(): void
    {
        $titolare = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'titolare2@esempio.test', liberatoriaFirmata: false);

        $this->actingAs($titolare)
            ->get('/admin')
            ->assertRedirectContains('liberatoria');

        app(LeLiberatorie::class)->firma($titolare, LeLiberatorie::PALESTRA);

        $this->actingAs($titolare->fresh())
            ->get('/admin')
            ->assertSuccessful();
    }

    /** 💡 E la cancellazione dell'account si porta via anche la firma. */
    #[Test]
    public function la_firma_segue_la_persona_quando_sparisce(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach5@esempio.test', liberatoriaFirmata: false);
        app(LeLiberatorie::class)->firma($trainer, LeLiberatorie::TRAINER);

        User::withoutGlobalScopes()->whereKey($trainer->getKey())->forceDelete();

        $this->assertSame(0, LiberatoriaFirmata::query()->where('user_id', $trainer->getKey())->count());
    }
}
