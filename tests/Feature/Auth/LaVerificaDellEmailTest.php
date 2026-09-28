<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Jobs\MandaLaMail;
use App\Mail\VerificaLEmailMail;
use App\Models\InvitoInPalestra;
use App\Services\Auth\LaVerificaDellEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ✅ **La verifica dell'email, e gli inviti che si possono finalmente vedere** —
 * voce 62.6, 28/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE QUESTA VOCE ═════════════════════════════════════════
 *
 * 📌 Il committente: *«Eh, facciamola, che ci vuole, mi pare che su laravel sia
 * tipo un paio di righe di codice»*. ⚠️ La riga è una davvero; il lavoro è tutto
 * quello che le sta intorno — e la parte più delicata è **quello che NON deve
 * succedere**.
 *
 * ══ 🚨 LA COSA CHE QUESTI TEST DEVONO PROTEGGERE PRIMA DI TUTTO ═══════════
 *
 * La **voce 63**: *«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE»*.
 *
 * ⛔ Una verifica dell'email è il cancello più facile da mettere per sbaglio
 * sull'accesso: basta un `verified` di troppo in un gruppo di rotte, e chi non ha
 * ricevuto la mail resta fuori. 🚨 Con il DMARC in `p=none` da ieri, «non ha
 * ricevuto la mail» non è un caso raro.
 *
 * 💡 Per questo il primo test di questo file non prova che la verifica funzioni:
 * prova che **non abbia rotto niente**.
 */
class LaVerificaDellEmailTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **Chi NON ha verificato entra lo stesso, e usa l'app.**
     *
     * ⛔ È il test che vale più di tutti gli altri di questo file. Se cade, la
     * voce 62.6 va annullata: 🔑 una funzione che fa perdere l'accesso a qualcuno
     * non si tiene, per quanto sia comoda.
     */
    #[Test]
    public function chi_non_ha_verificato_entra_e_usa_lapp(): void
    {
        $palestra = $this->creaPalestra('Non verificata', 'non-verificata');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'nonverificata@esempio.test');

        $persona->forceFill(['email_verified_at' => null])->save();

        self::assertFalse($persona->fresh()->hasVerifiedEmail());

        /* 🔑 L'accesso: deve funzionare identico. */
        $entrata = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonverificata@esempio.test',
            'password' => self::FAKE_PASSWORD,
        ]);

        $entrata->assertOk();

        $token = $entrata->json('data.token') ?? $entrata->json('token');

        self::assertNotNull($token, '🚨 Il login non ha restituito un token.');

        /* 🔑 E l'app: `me` è la rotta con cui si avvia. */
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    /**
     * ⛔ **E l'unica cosa che NON può fare è vedere gli inviti ricevuti.**
     *
     * 🔑 Quella rotta consegna **codici monouso**: senza verifica, chi si
     * registra con l'indirizzo di un altro si prenderebbe i suoi inviti.
     *
     * ⚠️ E il 403 **dice cosa fare**: senza un codice che l'app sa leggere,
     * sarebbe un vicolo cieco — una funzione che non va e non spiega perché.
     */
    #[Test]
    public function ma_gli_inviti_ricevuti_no_e_il_403_dice_cosa_fare(): void
    {
        $palestra = $this->creaPalestra('Senza verifica', 'senza-verifica');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'senza@esempio.test');

        $persona->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($persona->fresh(), 'sanctum')
            ->getJson('/api/v1/inviti-ricevuti')
            ->assertForbidden();
    }

    /**
     * ✅ **Chi ha verificato vede l'invito, col suo codice.**
     *
     * 💡 È il pezzo che sblocca tutta la voce: da qui l'app può portare alla
     * schermata dell'invito, che vuole il codice — e quel codice **non passa da
     * una notifica**, perché di là passerebbe da Google.
     */
    #[Test]
    public function chi_ha_verificato_vede_linvito_col_codice(): void
    {
        $palestra = $this->creaPalestra('Che invita', 'che-invita');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@che-invita.test');

        $invitato = $this->creaPalestra('Casa sua', 'casa-sua');
        $persona = $this->creaUtente($invitato, UserRole::Member, 'invitato@esempio.test');

        /*
         * ⚠️ **`creaUtente()` NON verifica l'email**, ed è giusto: la verifica
         * vale per chi si iscrive da adesso, e un aiutante dei test che la desse
         * per fatta nasconderebbe proprio il caso che qui interessa.
         */
        $persona->forceFill(['email_verified_at' => now()])->save();

        $invito = $this->ctx()->runAs($palestra, fn (): InvitoInPalestra => InvitoInPalestra::create([
            'tenant_id' => $palestra->getKey(),
            'invitato_da' => $capo->getKey(),
            'token' => str_repeat('b', 32),
            'email' => 'invitato@esempio.test',
            'expires_at' => now()->addDays(7),
        ]));

        $risposta = $this->actingAs($persona->fresh(), 'sanctum')
            ->getJson('/api/v1/inviti-ricevuti');

        $risposta->assertOk();

        $risposta->assertJsonPath('data.0.codice', $invito->token);
        $risposta->assertJsonPath('data.0.tipo', 'palestra');
        $risposta->assertJsonPath('data.0.da', 'Che invita');
    }

    /**
     * 🚨 **Un invito già usato, revocato o rifiutato non si vede.**
     *
     * ⚖️ Le condizioni stanno in `EUnInvito::scopeValidi()`, in un posto solo. ⛔ Il
     * controller non le riscrive: ripeterle vorrebbe dire che una quinta
     * condizione, un giorno, verrebbe dimenticata proprio qui — e un invito che
     * continua a funzionare quando non dovrebbe non dà nessun errore.
     */
    #[Test]
    public function un_invito_gia_chiuso_non_compare(): void
    {
        $palestra = $this->creaPalestra('Chiusa', 'chiusa');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@chiusa.test');

        $mio = $this->creaPalestra('Mia', 'mia');
        $persona = $this->creaUtente($mio, UserRole::Member, 'chiuso@esempio.test');

        /*
         * ⚠️ **`creaUtente()` NON verifica l'email**, ed è giusto: la verifica
         * vale per chi si iscrive da adesso, e un aiutante dei test che la desse
         * per fatta nasconderebbe proprio il caso che qui interessa.
         */
        $persona->forceFill(['email_verified_at' => now()])->save();

        $this->ctx()->runAs($palestra, function () use ($palestra, $capo): void {
            foreach ([
                ['token' => str_repeat('c', 32), 'used_at' => now()],
                ['token' => str_repeat('d', 32), 'revoked_at' => now()],
                ['token' => str_repeat('e', 32), 'rifiutato_il' => now()],
                ['token' => str_repeat('f', 32), 'expires_at' => now()->subDay()],
            ] as $storto) {
                $invito = InvitoInPalestra::create([
                    'tenant_id' => $palestra->getKey(),
                    'invitato_da' => $capo->getKey(),
                    'token' => $storto['token'],
                    'email' => 'chiuso@esempio.test',
                    'expires_at' => $storto['expires_at'] ?? now()->addDays(7),
                ]);

                unset($storto['token'], $storto['expires_at']);

                if ($storto !== []) {
                    $invito->forceFill($storto)->save();
                }
            }
        });

        $this->actingAs($persona->fresh(), 'sanctum')
            ->getJson('/api/v1/inviti-ricevuti')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * 📧 **La mail di conferma si chiede, e va in coda come tutte le altre.**
     */
    #[Test]
    public function la_mail_di_conferma_si_chiede_e_va_in_coda(): void
    {
        Queue::fake();

        $palestra = $this->creaPalestra('Chiede', 'chiede');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'chiede@esempio.test');

        $persona->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($persona->fresh(), 'sanctum')
            ->postJson('/api/v1/auth/email/conferma')
            ->assertOk()
            ->assertJsonPath('data.mandata', true);

        Queue::assertPushed(
            MandaLaMail::class,
            fn (MandaLaMail $job): bool => $job->a === 'chiede@esempio.test'
                && $job->mail instanceof VerificaLEmailMail,
        );
    }

    /**
     * ⛔ **E a chi ha già verificato non si manda niente.**
     *
     * ⚠️ Senza questo, il test qui sopra passerebbe anche se la mail partisse
     * **sempre**: e una persona che tocca il bottone per curiosità si prenderebbe
     * una mail che non serve a niente.
     */
    #[Test]
    public function a_chi_ha_gia_verificato_non_si_manda_niente(): void
    {
        Queue::fake();

        $palestra = $this->creaPalestra('Gia verificato', 'gia-verificato');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'gia@esempio.test');

        /*
         * ⚠️ **`creaUtente()` NON verifica l'email**, ed è giusto: la verifica
         * vale per chi si iscrive da adesso, e un aiutante dei test che la desse
         * per fatta nasconderebbe proprio il caso che qui interessa.
         */
        $persona->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($persona->fresh(), 'sanctum')
            ->postJson('/api/v1/auth/email/conferma')
            ->assertOk()
            ->assertJsonPath('data.mandata', false)
            ->assertJsonPath('data.gia_verificata', true);

        Queue::assertNotPushed(MandaLaMail::class);
    }

    /**
     * ✅ **Il link firmato conferma davvero.**
     */
    #[Test]
    public function il_link_firmato_conferma_lindirizzo(): void
    {
        $palestra = $this->creaPalestra('Conferma', 'conferma');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'conferma@esempio.test');

        $persona->forceFill(['email_verified_at' => null])->save();
        $persona = $persona->fresh();

        $this->get(app(LaVerificaDellEmail::class)->link($persona))
            ->assertOk()
            ->assertSee('Fatto', false);

        self::assertTrue($persona->fresh()->hasVerifiedEmail());
    }

    /**
     * 🚨 **Un link fatto per un indirizzo vecchio non vale più.**
     *
     * ⛔ Con la sola firma, un link mandato a `vecchia@…` continuerebbe a valere
     * dopo un cambio di email — e toccandolo verificherebbe **il nuovo**
     * indirizzo, che nessuno ha mai confermato. 🔑 L'impronta lo impedisce.
     */
    #[Test]
    public function un_link_per_lindirizzo_vecchio_non_vale_piu(): void
    {
        $palestra = $this->creaPalestra('Cambiata', 'cambiata');
        $persona = $this->creaUtente($palestra, UserRole::Member, 'vecchia@esempio.test');

        $persona->forceFill(['email_verified_at' => null])->save();

        $link = app(LaVerificaDellEmail::class)->link($persona->fresh());

        /* ✉️ Cambia indirizzo prima di toccare il link. */
        $persona->forceFill(['email' => 'nuova@esempio.test'])->save();

        $this->get($link)
            ->assertOk()
            ->assertSee('non vale più', false);

        self::assertFalse(
            $persona->fresh()->hasVerifiedEmail(),
            '🚨 Un link fatto per il vecchio indirizzo ha verificato il nuovo.',
        );
    }

    /**
     * 🔒 **`verified` sta su UNA rotta sola, e deve restare così.**
     *
     * ⛔ È il vincolo che tiene insieme la 62.6 e la voce 63. 🚨 Un `verified` in
     * più su un gruppo di rotte non darebbe nessun errore qui: darebbe un 403 a
     * qualcuno, in produzione, il giorno che non ha ricevuto una mail.
     *
     * 💡 Si guardano **le righe di codice** dei file delle rotte, non i commenti:
     * il commento che spiega la regola la nomina, ed è la cosa che serve di più.
     */
    #[Test]
    public function il_middleware_verified_sta_su_una_rotta_sola(): void
    {
        $trovati = [];

        foreach (['routes/api.php', 'routes/web.php'] as $file) {
            foreach (file(base_path($file)) as $n => $riga) {
                $pulita = ltrim($riga);

                if (str_starts_with($pulita, '*') || str_starts_with($pulita, '//')
                    || str_starts_with($pulita, '/*')) {
                    continue;
                }

                if (str_contains($pulita, "'verified'") || str_contains($pulita, '"verified"')) {
                    $trovati[] = $file.':'.($n + 1);
                }
            }
        }

        self::assertCount(
            1,
            $trovati,
            '🚨 `verified` compare in '.count($trovati)." punti:\n  ".implode("\n  ", $trovati)."\n"
            .'⛔ Deve stare **solo** su `inviti-ricevuti`. La voce 63: «niente al mondo deve '
            .'impedire a un utente di accedere» — e una verifica dell\'email su una rotta di '
            ."servizio chiude fuori chi non ha ricevuto la mail.\n"
            .'💡 Se serve davvero un secondo punto, va deciso dal committente e scritto qui.',
        );

        self::assertStringContainsString('api.php', $trovati[0]);
    }
}
