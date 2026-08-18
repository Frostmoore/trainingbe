<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Services\Billing\Stripe\ChiaviDiStripe;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * La guardia sulle chiavi di Stripe — 18/08/2026.
 *
 * ── 🚨 Cosa dimostra questo file ───────────────────────────────────────────
 *
 * Che **non si possono muovere soldi veri per sbaglio**. La regola, testuale dal
 * committente: *«finche' il sito e' in debug mode deve usare le chiavi staging e
 * il webhook staging»*.
 *
 * ⚠️ **Nessun test qui tocca la rete.** Le stringhe sono finte e cominciano per
 * `sk_live_` solo perche' e' il prefisso che la guardia deve riconoscere: non
 * sono chiavi, sono esche.
 */
class ChiaviDiStripeTest extends TestCase
{
    /**
     * 💡 Le esche. ⚠️ Volutamente **non** lunghe come una chiave vera e con la
     * parola `finta` dentro: se un analizzatore di credenziali le guarda, deve
     * capire da solo che non sono segreti. Stessa ragione di
     * `TestCase::FAKE_PASSWORD`.
     */
    private const ESCA_VERA_SEGRETA = 'sk_live_chiave_finta_per_il_test';

    private const ESCA_VERA_PUBBLICA = 'pk_live_chiave_finta_per_il_test';

    private const ESCA_PROVA_SEGRETA = 'sk_test_chiave_finta_per_il_test';

    // ───────────────── la regola, interrogata direttamente ─────────────────

    #[Test]
    public function la_regola_vuole_TUTTE_E_DUE_le_condizioni(): void
    {
        /*
         * 🚨 La tabella della verita' per intero, in un test solo.
         *
         * ⚠️ Le tre righe `false` contano piu' di quella `true`: dicono che per
         * arrivare alle chiavi vere bisogna sbagliare **due** variabili nello
         * stesso momento, non una.
         */
        $this->assertTrue(ChiaviDiStripe::usaChiaviVere('production', false));

        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production', true));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('staging', false));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('local', true));
    }

    #[Test]
    public function la_regola_senza_debug_dichiarato_sta_dal_lato_prudente(): void
    {
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production'));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere(null));
    }

    #[Test]
    public function un_APP_DEBUG_scritto_male_NON_apre_le_chiavi_vere(): void
    {
        /*
         * 🚨 `APP_DEBUG=0` e `APP_DEBUG="false"` sono modi plausibili di scrivere
         * «spento», e Laravel **non** li converte in `false` booleano: restano
         * stringhe.
         *
         * 💡 Il confronto stretto (`=== false`) li tratta quindi come «non ho
         * capito», e «non ho capito» porta alla sandbox. ⚠️ Con un confronto
         * lasco (`! $debug`) la stringa `"0"` sarebbe risultata falsa e avrebbe
         * aperto le chiavi vere — un `.env` scritto in buona fede che muove
         * soldi.
         */
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production', '0'));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production', 'false'));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production', 0));
        $this->assertFalse(ChiaviDiStripe::usaChiaviVere('production', null));
    }

    #[Test]
    public function la_configurazione_vera_usa_questa_regola(): void
    {
        /*
         * ⚠️ Il collante fra la regola e il file di configurazione: senza questo
         * test, `config/services.php` potrebbe smettere di chiamarla e tutti gli
         * altri test resterebbero verdi.
         *
         * 💡 In fase di test `APP_ENV=testing`, quindi ci si aspetta la sandbox
         * e le chiavi `STRIPE_STAGING_*`.
         */
        $config = require config_path('services.php');

        $this->assertTrue($config['stripe']['sandbox']);
    }

    // ───────────────── quando si usa la sandbox ─────────────────

    #[Test]
    public function in_locale_con_debug_acceso_e_sandbox(): void
    {
        $this->configura(env: 'local', debug: true);

        $this->assertTrue(app(ChiaviDiStripe::class)->eLaSandbox());
    }

    #[Test]
    public function su_staging_e_sandbox(): void
    {
        $this->configura(env: 'staging', debug: true);

        $this->assertTrue(app(ChiaviDiStripe::class)->eLaSandbox());
    }

    #[Test]
    public function in_produzione_MA_con_il_debug_acceso_e_ancora_sandbox(): void
    {
        /*
         * 🚨 **E' la meta' della regola che il committente ha chiesto**, ed e' la
         * meta' che `APP_ENV` da solo non copriva.
         *
         * ⚠️ Un sito in produzione con il debug acceso e' un sito che qualcuno
         * sta ancora sistemando. Fargli muovere soldi veri perche' una variabile
         * dice `production` sarebbe esattamente il momento sbagliato.
         */
        $this->configura(env: 'production', debug: true);

        $this->assertTrue(app(ChiaviDiStripe::class)->eLaSandbox());
    }

    #[Test]
    public function senza_APP_DEBUG_si_assume_acceso_quindi_sandbox(): void
    {
        /*
         * 🚨 Il valore predefinito di un interruttore di sicurezza deve stare dal
         * lato prudente: «non lo so» e' un ottimo motivo per non toccare i soldi
         * di nessuno.
         */
        $this->configura(env: 'production', debug: null);

        $this->assertTrue(app(ChiaviDiStripe::class)->eLaSandbox());
    }

    // ───────────────── quando si usano quelle vere ─────────────────

    #[Test]
    public function solo_produzione_E_debug_spento_insieme_danno_le_chiavi_vere(): void
    {
        $this->configura(env: 'production', debug: false, segreta: self::ESCA_VERA_SEGRETA);

        $chiavi = app(ChiaviDiStripe::class);

        $this->assertFalse($chiavi->eLaSandbox());
        $this->assertSame(self::ESCA_VERA_SEGRETA, $chiavi->segreto());
    }

    // ───────────────── la guardia che ferma l'incidente ─────────────────

    #[Test]
    public function una_chiave_segreta_VERA_fuori_produzione_fa_fallire_la_chiamata(): void
    {
        /*
         * 🚨 **Il test piu' importante del file.**
         *
         * E' lo scenario vero: nel `.env` locale convivono le chiavi live e
         * quelle di prova, e basta che qualcuno scambi un nome di variabile
         * perche' un flusso di pagamento cominci a muovere soldi. ⚠️ Qui la
         * chiamata **fallisce subito e a voce alta**, invece di riuscire in
         * silenzio.
         */
        $this->configura(env: 'local', debug: true, segreta: self::ESCA_VERA_SEGRETA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('~VERA~');

        app(ChiaviDiStripe::class)->segreto();
    }

    #[Test]
    public function anche_la_chiave_pubblica_VERA_fuori_produzione_fa_fallire(): void
    {
        // 💡 La pubblicabile non muove soldi da sola, ma finisce nell'app e nel
        // browser: sbagliarla vuol dire mandare le persone sulla cassa vera.
        $this->configura(env: 'local', debug: true, pubblica: self::ESCA_VERA_PUBBLICA);

        $this->expectException(RuntimeException::class);

        app(ChiaviDiStripe::class)->pubblica();
    }

    #[Test]
    public function in_produzione_con_debug_acceso_la_chiave_vera_fa_comunque_fallire(): void
    {
        /*
         * ⚠️ Non e' un doppione del test sopra: qui `APP_ENV` e' **giusto** e
         * sbagliato e' solo il debug. Senza questa riga la guardia direbbe «siamo
         * in produzione, passa» e la meta' nuova della regola non varrebbe niente.
         */
        $this->configura(env: 'production', debug: true, segreta: self::ESCA_VERA_SEGRETA);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('~APP_DEBUG~');

        app(ChiaviDiStripe::class)->segreto();
    }

    #[Test]
    public function il_messaggio_dice_cosa_fare_e_non_solo_cosa_e_successo(): void
    {
        /*
         * 💡 Chi legge questo errore di solito **non sta pensando a Stripe**:
         * sta provando tutt'altro e si e' trovato davanti un'eccezione. Il
         * messaggio deve portarcelo per mano, o costa mezz'ora a chiunque.
         */
        $this->configura(env: 'local', debug: true, segreta: self::ESCA_VERA_SEGRETA);

        try {
            app(ChiaviDiStripe::class)->segreto();
            $this->fail('Doveva lanciare.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('STRIPE_STAGING_', $e->getMessage());
            $this->assertStringContainsString('APP_ENV=local', $e->getMessage());
        }
    }

    // ───────────────── la chiave che manca ─────────────────

    #[Test]
    public function una_chiave_mancante_lo_dice_invece_di_fallire_altrove(): void
    {
        /*
         * ⚠️ Senza questo controllo la stringa vuota arriverebbe fino a Stripe,
         * che risponderebbe con un errore di autenticazione — cioe' un guasto che
         * parla di credenziali sbagliate quando il problema e' che non ce ne sono.
         */
        $this->configura(env: 'local', debug: true, segreta: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('~manca~');

        app(ChiaviDiStripe::class)->segreto();
    }

    #[Test]
    public function il_segreto_dei_webhook_mancante_torna_stringa_vuota_e_non_lancia(): void
    {
        /*
         * 🚨 Qui **non** si lancia, ed e' voluto: la decisione di cosa rispondere
         * a una richiesta non verificabile appartiene al controller, che e'
         * l'unico posto che sa che si tratta di HTTP e che deve dire `503`.
         *
         * ⚠️ Un'eccezione qui diventerebbe un `500` — cioe' direbbe a Stripe
         * «riprova», e Stripe riproverebbe per ore su qualcosa che non
         * migliorera' finche' nessuno mette la variabile.
         */
        $this->configura(env: 'local', debug: true);
        config()->set('services.stripe.webhook_secret', null);

        $this->assertSame('', app(ChiaviDiStripe::class)->segretoDeiWebhook());
    }

    // ───────────────── aiutante ─────────────────

    /**
     * 🚨 Interroga **la regola vera**, non una sua copia.
     *
     * ⚠️ La tentazione era riscrivere qui `$env === 'production' && ! $debug`.
     * Sarebbe stato un test che prova la mia copia della regola: il giorno che
     * le due divergono resterebbe verde mentre il codice sbaglia. Chiamando
     * `usaChiaviVere()` — la stessa funzione che usa `config/services.php` — il
     * test non puo' avere ragione mentre il codice ha torto.
     *
     * 💡 `$debug = null` riproduce **la variabile assente**: si passa il valore
     * predefinito di `usaChiaviVere()` invece di forzarne uno, cosi' si verifica
     * anche quello.
     */
    private function configura(
        string $env,
        ?bool $debug,
        ?string $segreta = self::ESCA_PROVA_SEGRETA,
        ?string $pubblica = null,
    ): void {
        $vere = $debug === null
            ? ChiaviDiStripe::usaChiaviVere($env)
            : ChiaviDiStripe::usaChiaviVere($env, $debug);

        config()->set('app.env', $env);
        config()->set('app.debug', $debug ?? true);
        config()->set('services.stripe.sandbox', ! $vere);
        config()->set('services.stripe.secret', $segreta);
        config()->set('services.stripe.key', $pubblica ?? 'pk_test_chiave_finta_per_il_test');
        config()->set('services.stripe.webhook_secret', 'whsec_finto_per_il_test');
    }
}
