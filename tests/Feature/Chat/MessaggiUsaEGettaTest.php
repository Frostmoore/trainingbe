<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * I messaggi «una volta sola» — N16.
 *
 * ── 🚨 Cosa difendono questi test ──────────────────────────────────────────
 *
 * I **due orologi**, che sono la parte facile da sbagliare:
 *
 * | | Chi riceve | Chi manda |
 * |---|---|---|
 * | Fino a quando | Alla **prima apertura** | **24 ore dall'invio** |
 * | Poi | Traccia | Traccia |
 *
 * ⚠️ Un difetto qui non si vede: la busta continua a essere consegnata a chi
 * l'aveva già bruciata, e nessuno se ne accorge — men che meno chi l'ha mandata,
 * che è l'unico a cui interessava.
 */
class MessaggiUsaEGettaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    private User $iscritto;

    private Conversation $filo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'atleta@olimpo.it');

        $this->filo = Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->id,
            'trainer_id' => $this->trainer->id,
            'member_id' => $this->iscritto->id,
            'tipo' => TipoConversazione::Iscritto,
        ]);
    }

    private function scrivi(User $chi, bool $usaEGetta, bool $eraFoto = false): int
    {
        $risposta = $this->actingAs($chi)->postJson(
            '/api/v1/conversations/'.$this->filo->id.'/messages',
            [
                'envelope_version' => 2,
                'nonce' => str_repeat('a', 32),
                'body' => 'YnVzdGEtY2lmcmF0YQ==',
                'usa_e_getta' => $usaEGetta,
                'era_foto' => $eraFoto,
            ],
        );

        $risposta->assertStatus(201);

        return (int) $risposta->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function leggi(User $chi, int $id): array
    {
        $messaggi = $this->actingAs($chi)
            ->getJson('/api/v1/conversations/'.$this->filo->id.'/messages')
            ->assertOk()
            ->json('data');

        foreach ($messaggi as $m) {
            if ((int) $m['id'] === $id) {
                return $m;
            }
        }

        $this->fail("Il messaggio {$id} non c'è nell'elenco.");
    }

    #[Test]
    public function un_messaggio_normale_resta_normale(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: false);

        foreach ([$this->trainer, $this->iscritto] as $chi) {
            $riga = $this->leggi($chi, $id);

            $this->assertFalse($riga['usa_e_getta']);
            $this->assertFalse($riga['spenta']);
            $this->assertNotSame('', $riga['body']);
        }
    }

    /**
     * 🚨 **Il default è «normale».** Un client vecchio che non conosce il campo
     * non deve mandare messaggi che si autodistruggono.
     */
    #[Test]
    public function senza_il_campo_il_messaggio_non_e_effimero(): void
    {
        $risposta = $this->actingAs($this->trainer)->postJson(
            '/api/v1/conversations/'.$this->filo->id.'/messages',
            [
                'envelope_version' => 2,
                'nonce' => str_repeat('a', 32),
                'body' => 'YnVzdGEtY2lmcmF0YQ==',
            ],
        )->assertStatus(201);

        $this->assertFalse($risposta->json('data.usa_e_getta'));
    }

    /**
     * 🚨 **Il test centrale**: aperta una volta, a chi la riceve non torna più.
     */
    #[Test]
    public function a_chi_riceve_la_busta_non_torna_dopo_l_apertura(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        // Prima dell'apertura: la busta c'è.
        $prima = $this->leggi($this->iscritto, $id);
        $this->assertNotSame('', $prima['body']);
        $this->assertFalse($prima['spenta']);

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
            ->assertOk()
            ->assertJsonPath('data.visto', true);

        // Dopo: resta la traccia, non il contenuto.
        $dopo = $this->leggi($this->iscritto, $id);
        $this->assertSame('', $dopo['body']);
        $this->assertSame('', $dopo['nonce']);
        $this->assertTrue($dopo['spenta']);
    }

    /**
     * 💡 I due orologi sono diversi: l'apertura dell'altro non tocca il mittente.
     */
    #[Test]
    public function chi_manda_la_rivede_anche_dopo_che_l_altro_l_ha_aperta(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
            ->assertOk();

        $suo = $this->leggi($this->trainer, $id);

        $this->assertNotSame('', $suo['body'], 'Chi manda ha ancora le sue 24 ore.');
        $this->assertFalse($suo['spenta']);
    }

    #[Test]
    public function dopo_ventiquattro_ore_non_la_vede_piu_nemmeno_chi_l_ha_mandata(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        Carbon::setTestNow(now()->addHours(Message::ORE_PER_CHI_MANDA + 1));

        try {
            $suo = $this->leggi($this->trainer, $id);

            $this->assertSame('', $suo['body']);
            $this->assertTrue($suo['spenta']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 🚨 Chi manda non deve poter bruciare la **propria** busta.
     *
     * ⚠️ `crypto_box` gli permette di rileggere ciò che ha scritto: se il suo
     * tocco contasse come «visto», si toglierebbe da solo le ventiquattro ore.
     */
    #[Test]
    public function chi_manda_non_brucia_la_propria_busta(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        $this->actingAs($this->trainer)
            ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
            ->assertOk()
            ->assertJsonPath('data.visto', false);

        $this->assertNull(Message::query()->findOrFail($id)->visto_il);
        $this->assertNotSame('', $this->leggi($this->trainer, $id)['body']);
    }

    /**
     * 🚨 A busta spenta il contenuto non c'è più: la traccia giusta —
     * «Foto effimera» invece di «Messaggio effimero» — può venire solo da fuori.
     */
    #[Test]
    public function la_traccia_sa_se_era_una_foto(): void
    {
        $foto = $this->scrivi($this->trainer, usaEGetta: true, eraFoto: true);
        $testo = $this->scrivi($this->trainer, usaEGetta: true);

        foreach ([$foto, $testo] as $id) {
            $this->actingAs($this->iscritto)
                ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
                ->assertOk();
        }

        $this->assertTrue($this->leggi($this->iscritto, $foto)['era_foto']);
        $this->assertFalse($this->leggi($this->iscritto, $testo)['era_foto']);
    }

    /** ⚠️ La prima apertura vince: è la prova che il contenuto è stato scoperto. */
    #[Test]
    public function la_seconda_apertura_non_sposta_la_data(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
            ->assertOk();

        $primaVolta = Message::query()->findOrFail($id)->visto_il;

        Carbon::setTestNow(now()->addMinutes(30));

        try {
            $this->actingAs($this->iscritto)
                ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
                ->assertOk();

            $this->assertEquals($primaVolta, Message::query()->findOrFail($id)->visto_il);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 🚨 Un estraneo non raggiunge il messaggio nemmeno conoscendone l'id. */
    #[Test]
    public function un_estraneo_non_puo_segnare_niente(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        $estraneo = $this->creaUtente($this->palestra, UserRole::Member, 'altro@olimpo.it');

        $this->actingAs($estraneo)
            ->postJson('/api/v1/conversations/'.$this->filo->id.'/messages/'.$id.'/vista')
            ->assertStatus(404);

        $this->assertNull(Message::query()->findOrFail($id)->visto_il);
    }

    /**
     * 🚨 Il comando toglie i byte dal disco: senza, una busta effimera
     * resterebbe sul server per sempre e nessuno se ne accorgerebbe.
     */
    #[Test]
    public function il_comando_svuota_le_buste_scadute(): void
    {
        $effimero = $this->scrivi($this->trainer, usaEGetta: true);
        $normale = $this->scrivi($this->trainer, usaEGetta: false);

        Carbon::setTestNow(now()->addHours(Message::ORE_PER_CHI_MANDA + 1));

        try {
            $this->artisan('chat:pota-effimeri')->assertSuccessful();

            $svuotato = Message::query()->findOrFail($effimero);
            $intatto = Message::query()->findOrFail($normale);

            $this->assertSame('', $svuotato->body);
            $this->assertNotNull($svuotato->svuotato_il);

            // ⚠️ La riga resta: un buco nel mezzo di una conversazione sembra
            // un guasto, una traccia sembra quello che è.
            $this->assertNotNull(Message::query()->find($effimero));

            $this->assertNotSame('', $intatto->body, 'I messaggi normali non si toccano.');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function il_comando_non_tocca_le_buste_ancora_valide(): void
    {
        $id = $this->scrivi($this->trainer, usaEGetta: true);

        $this->artisan('chat:pota-effimeri')->assertSuccessful();

        $this->assertNotSame('', Message::query()->findOrFail($id)->body);
        $this->assertNull(Message::query()->findOrFail($id)->svuotato_il);
    }
}
