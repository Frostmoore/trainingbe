<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\UserRole;
use App\Mail\BenvenutoMail;
use App\Mail\InvitoDelTrainerMail;
use App\Mail\PasswordCambiataMail;
use App\Mail\RichiestaDalSitoMail;
use App\Mail\RichiestaRicevutaMail;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📧 **Le mail partono dai punti giusti** — 22/09/2026.
 *
 * ⛔ Prima di oggi tre di queste **non esistevano**, e non lo diceva nessuno:
 * l'invito di un trainer nasceva senza mail (l'indirizzo si poteva scrivere e
 * non serviva a niente), la password cambiava in silenzio, e una richiesta dal
 * sito finiva solo in tabella — visibile aprendo il pannello, se qualcuno lo
 * apriva.
 *
 * 💡 Si prova con `Mail::fake()`: qui interessa **che parta e a chi**, non come
 * è fatta. Com'è fatta lo prova `LeMailSiVestonoUgualeTest`.
 */
final class LeMailPartonoDavveroTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Mail::fake();
    }

    #[Test]
    public function chi_si_registra_dall_app_riceve_il_benvenuto(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rita Bianchi',
            'username' => 'rita',
            'email' => 'rita@esempio.test',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
            'age_confirmed' => true,
            'terms_accepted' => true,
        ])->assertCreated();

        Mail::assertSent(BenvenutoMail::class, fn (BenvenutoMail $m): bool => $m->hasTo('rita@esempio.test'));
    }

    #[Test]
    public function l_invito_del_trainer_con_email_parte(): void
    {
        $trainer = $this->conPianoDaTrainer(app(CreaTenantPersonale::class)(
            'Coach', 'coach@esempio.test', ['password' => self::FAKE_PASSWORD], UserRole::FreeTrainer,
        ));

        app(TenantContext::class)->runAs(
            $trainer->tenant,
            fn () => app(InvitiDelTrainer::class)->invita($trainer->fresh(), 'allieva@esempio.test'),
        );

        Mail::assertSent(
            InvitoDelTrainerMail::class,
            fn (InvitoDelTrainerMail $m): bool => $m->hasTo('allieva@esempio.test'),
        );
    }

    /** ⚖️ E senza indirizzo non parte niente: il link si copia dall'app. */
    #[Test]
    public function l_invito_senza_email_non_manda_niente(): void
    {
        $trainer = $this->conPianoDaTrainer(app(CreaTenantPersonale::class)(
            'Coach', 'coach@esempio.test', ['password' => self::FAKE_PASSWORD], UserRole::FreeTrainer,
        ));

        app(TenantContext::class)->runAs(
            $trainer->tenant,
            fn () => app(InvitiDelTrainer::class)->invita($trainer->fresh()),
        );

        Mail::assertNotSent(InvitoDelTrainerMail::class);
    }

    /** 🚨 L'avviso di sicurezza: una password che cambia senza dirlo nasconde un furto. */
    #[Test]
    public function cambiando_la_password_arriva_l_avviso(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Rita', 'rita@esempio.test', ['password' => self::FAKE_PASSWORD], UserRole::FreeUser,
        );

        $reset = app(LaReimpostazioneDellaPassword::class);

        // ⚠️ `apriPer()` torna il **link**, non il token: il token e' l'ultimo pezzo.
        $link = $reset->apriPer($persona->fresh());
        $token = (string) mb_substr($link, mb_strrpos($link, '/') + 1);

        $this->assertTrue($reset->completa($token, 'Password-Nuova-9'));

        Mail::assertSent(
            PasswordCambiataMail::class,
            fn (PasswordCambiataMail $m): bool => $m->hasTo('rita@esempio.test'),
        );
    }

    /** 💡 Due mail: la ricevuta a chi scrive, l'avviso a noi. */
    #[Test]
    public function una_richiesta_dal_sito_manda_due_mail(): void
    {
        config(['services.contatti.email' => 'noi@esempio.test']);

        $this->post('/scrivici', [
            'tipo' => 'palestra',
            'nome' => 'Rita Bianchi',
            'email' => 'rita@esempio.test',
            'messaggio' => 'Vorrei capire quanto costa per 240 iscritti.',
            'accetto' => '1',
        ])->assertRedirect();

        Mail::assertSent(RichiestaRicevutaMail::class, fn ($m): bool => $m->hasTo('rita@esempio.test'));
        Mail::assertSent(RichiestaDalSitoMail::class, fn ($m): bool => $m->hasTo('noi@esempio.test'));
    }

    /** ⛔ Il robot che compila il campo trappola non fa partire niente. */
    #[Test]
    public function il_campo_trappola_non_manda_mail(): void
    {
        $this->post('/scrivici', [
            'tipo' => 'altro',
            'nome' => 'Robot',
            'email' => 'robot@esempio.test',
            'messaggio' => 'Compro backlink, rispondi subito.',
            'accetto' => '1',
            'sito_web' => 'https://spam.example',
        ])->assertRedirect();

        Mail::assertNothingSent();
    }
}
