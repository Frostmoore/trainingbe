<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * G8 — cambiare la propria email e la propria password dall'app.
 *
 * 🚨 Il filo conduttore e' uno: **serve sempre la password attuale**. Un
 * telefono lasciato sbloccato sulla panca dello spogliatoio basterebbe
 * altrimenti a spostare l'account su un indirizzo che non e' il tuo — e da li',
 * con un recupero password, a prenderselo.
 */
class AccountCredentialsApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    // ───────────────────────── email ─────────────────────────

    #[Test]
    public function con_la_password_giusta_l_email_cambia(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/email', [
                'email' => 'nuova@alfa.test',
                'current_password' => self::FAKE_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'nuova@alfa.test');

        $this->assertSame('nuova@alfa.test', $this->iscritto->refresh()->email);
    }

    #[Test]
    public function senza_la_password_giusta_l_email_non_si_tocca(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/email', [
                'email' => 'nuova@alfa.test',
                'current_password' => 'non-e-quella',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('mario@alfa.test', $this->iscritto->refresh()->email);
    }

    #[Test]
    public function un_email_gia_usata_nella_stessa_palestra_si_rifiuta(): void
    {
        $this->creaUtente($this->alfa, UserRole::Member, 'occupata@alfa.test');

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/email', [
                'email' => 'occupata@alfa.test',
                'current_password' => self::FAKE_PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * 🚨 L'unicita' e' **per palestra**, non globale.
     *
     * La stessa persona puo' essere iscritta a due palestre con lo stesso
     * indirizzo, ed e' una situazione prevista: rifiutarla direbbe «occupata»
     * per una riga che sta da tutt'altra parte, e chi la subisce non avrebbe
     * modo di capire perche'.
     */
    #[Test]
    public function un_email_usata_in_un_altra_palestra_va_benissimo(): void
    {
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $this->creaUtente($beta, UserRole::Member, 'condivisa@esempio.test');

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/email', [
                'email' => 'condivisa@esempio.test',
                'current_password' => self::FAKE_PASSWORD,
            ])
            ->assertOk();
    }

    // ───────────────────────── password ─────────────────────────

    #[Test]
    public function la_password_cambia_e_quella_nuova_funziona(): void
    {
        $nuova = self::FAKE_PASSWORD.'-nuova9';

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/password', [
                'current_password' => self::FAKE_PASSWORD,
                'password' => $nuova,
                'password_confirmation' => $nuova,
            ])
            ->assertOk();

        $this->assertTrue(Hash::check($nuova, $this->iscritto->refresh()->password));
    }

    /**
     * ⚠️ La conferma esiste per intercettare un errore di battitura: senza
     * questo test, il giorno che qualcuno toglie `confirmed` non se ne
     * accorgerebbe nessuno.
     */
    #[Test]
    public function una_conferma_diversa_si_rifiuta(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/password', [
                'current_password' => self::FAKE_PASSWORD,
                'password' => 'cavallo divano 7',
                'password_confirmation' => 'cavallo divano 8',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function la_password_nuova_deve_reggere_la_regola_minima(): void
    {
        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/password', [
                'current_password' => self::FAKE_PASSWORD,
                'password' => 'soltantolettere',
                'password_confirmation' => 'soltantolettere',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /**
     * 🚨 Cambiare password **chiude le altre sessioni**.
     *
     * Chi lo fa spesso teme che qualcuno sia entrato: lasciare attivi i token
     * degli altri dispositivi vorrebbe dire non aver cambiato niente per chi e'
     * gia' dentro. Quello corrente resta, o ci si disconnetterebbe da soli
     * premendo «salva».
     */
    #[Test]
    public function cambiare_password_chiude_gli_altri_dispositivi(): void
    {
        $altroDispositivo = $this->iscritto->createToken('vecchio-telefono', ['member']);

        $nuova = self::FAKE_PASSWORD.'-nuova9';

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/password', [
                'current_password' => self::FAKE_PASSWORD,
                'password' => $nuova,
                'password_confirmation' => $nuova,
            ])
            ->assertOk();

        $this->assertNull(
            $this->iscritto->tokens()->whereKey($altroDispositivo->accessToken->getKey())->first(),
        );
    }

    /**
     * ⏸️ Chi entra con Google o Apple non ha una password che conosca: glielo
     * si dice, invece di lasciarlo indovinare contro un hash casuale.
     */
    #[Test]
    public function chi_entra_solo_con_google_non_puo_cambiare_la_password(): void
    {
        $this->iscritto->forceFill(['password_is_set' => false])->save();

        $this->comeApp($this->iscritto)
            ->patchJson('/api/v1/account/password', [
                'current_password' => self::FAKE_PASSWORD,
                'password' => 'cavallo divano 7',
                'password_confirmation' => 'cavallo divano 7',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    /** L'app deve sapere cosa mostrare: il contratto di `/auth/me`. */
    #[Test]
    public function auth_me_dice_se_c_e_una_password_e_quali_social(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.password_is_set', true)
            ->assertJsonPath('data.social', []);
    }
}
