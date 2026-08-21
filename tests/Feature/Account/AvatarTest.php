<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * L'immagine del profilo — M7.2, 18/08/2026.
 *
 * 🚨 **Il caricamento di file è l'endpoint più pericoloso di un'API**: chi
 * carica decide cosa arriva sul disco del server. Metà dei test qui riguardano
 * quello che **non** deve poter entrare.
 */
class AvatarTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private User $io;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->io = $this->creaUtente($palestra, UserRole::Member, 'io@esempio.it');
    }

    #[Test]
    public function si_carica_una_foto_di_profilo(): void
    {
        $risposta = $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('io.jpg', 400, 400),
            ])
            ->assertStatus(201);

        $this->assertNotNull($risposta->json('data.avatar_url'));
        $this->assertNotNull($this->io->fresh()->avatar_path);
        $this->assertCount(1, Storage::disk('public')->files('avatar'));
    }

    #[Test]
    public function il_nome_del_file_lo_decide_il_server(): void
    {
        /*
         * 🚨 ⚠️ Usare il nome originale vorrebbe dire lasciare a chi carica il
         * controllo del percorso — `../../qualcosa.php` è il primo tentativo di
         * chiunque. E due persone con `foto.jpg` si sovrascriverebbero.
         */
        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('../../cattivo.jpg', 100, 100),
            ])
            ->assertStatus(201);

        $file = Storage::disk('public')->files('avatar');

        $this->assertCount(1, $file);
        $this->assertStringNotContainsString('cattivo', $file[0]);
        $this->assertStringNotContainsString('..', $file[0]);
        $this->assertStringStartsWith('avatar/'.$this->io->id.'-', $file[0]);
    }

    #[Test]
    public function un_sv_g_non_si_carica(): void
    {
        /*
         * 🚨 Un SVG è un documento XML che può contenere script: è un'immagine
         * per il validatore e un vettore di attacco per il browser che la mostra.
         */
        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->create('cattivo.svg', 10, 'image/svg+xml'),
            ])
            ->assertStatus(422);

        $this->assertCount(0, Storage::disk('public')->files('avatar'));
    }

    #[Test]
    public function un_file_che_non_e_un_immagine_non_si_carica(): void
    {
        // ⚠️ Non basta l'estensione: Laravel guarda il contenuto.
        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->create('finta.jpg', 10, 'application/x-php'),
            ])
            ->assertStatus(422);

        $this->assertCount(0, Storage::disk('public')->files('avatar'));
    }

    #[Test]
    public function un_file_troppo_grande_non_si_carica(): void
    {
        // 💡 Una foto di profilo che pesa più di 4 MB è una foto non ridimensionata.
        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('enorme.jpg')->size(5000),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function caricare_una_seconda_foto_cancella_la_prima(): void
    {
        /*
         * 💡 Il vecchio si cancella **dopo** che il nuovo è salvato: al
         * contrario, un caricamento fallito lascerebbe la persona senza immagine
         * e senza averla cambiata.
         */
        foreach (['prima.jpg', 'seconda.jpg'] as $nome) {
            $this->actingAs($this->io)
                ->postJson('/api/v1/account/avatar', ['avatar' => UploadedFile::fake()->image($nome)])
                ->assertStatus(201);
        }

        $this->assertCount(1, Storage::disk('public')->files('avatar'), 'Non deve accumulare file.');
    }

    #[Test]
    public function si_puo_togliere_la_propria_foto(): void
    {
        /*
         * 🚨 Poter **togliere** un'immagine di sé non è una rifinitura: è la
         * differenza fra un dato che si è scelto di dare e uno che non si può
         * più ritirare.
         */
        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', ['avatar' => UploadedFile::fake()->image('io.jpg')]);

        $this->actingAs($this->io)
            ->deleteJson('/api/v1/account/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($this->io->fresh()->avatar_path);
        $this->assertCount(0, Storage::disk('public')->files('avatar'));
    }

    #[Test]
    public function serve_l_autenticazione(): void
    {
        $this->postJson('/api/v1/account/avatar', [
            'avatar' => UploadedFile::fake()->image('io.jpg'),
        ])->assertStatus(401);
    }

    #[Test]
    public function non_si_puo_cambiare_la_foto_di_un_altro(): void
    {
        /*
         * 💡 Non c'è nessun id nella rotta, ed è la difesa: si scrive sempre e
         * solo il proprio avatar, preso da `$request->user()`. La cosa più
         * semplice da autorizzare è quella che non si può chiedere.
         */
        $altro = $this->creaUtente($this->io->tenant, UserRole::Member, 'altro@esempio.it');

        $this->actingAs($this->io)
            ->postJson('/api/v1/account/avatar', ['avatar' => UploadedFile::fake()->image('io.jpg')])
            ->assertStatus(201);

        $this->assertNull($altro->fresh()->avatar_path);
    }
}
