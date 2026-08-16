<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Services\Billing\Listino;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\ImmaginiDelSito;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il sito pubblico — F9 della Parte B.
 *
 * ⚠️ **È l'unica cosa di tutto il piano che non è né app né pannello.** Fino al
 * 13/08/2026 l'indirizzo principale del prodotto mostrava `view('welcome')` —
 * la pagina di benvenuto di Laravel, mai toccata, con il logo del framework.
 */
class SitoPubblicoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    #[Test]
    public function the_home_page_speaks_to_the_three_audiences(): void
    {
        $r = $this->get('/')->assertOk();

        // F9.1 — tre percorsi: persone, trainer indipendenti, palestre.
        $r->assertSee('Per chi si allena da solo')
            ->assertSee('Per i trainer indipendenti')
            ->assertSee('Per le palestre');

        $r->assertDontSee('Laravel', escape: false);
    }

    /**
     * 🚨 **Nessun prezzo è scritto dentro una vista.**
     *
     * ── ⚠️ Questo test ha sostituito `the_prices_come_from_the_database` ────
     *
     * Il vecchio provava che la home leggesse i piani da `plans`. Dal 16/08 la
     * home non elenca più i piani: il listino sta su `/prezzi` e viene da
     * `Listino` + `config/listino.php`, perché il modello è cambiato — si paga
     * **a posto**, non a scaglione di trainer.
     *
     * 💡 **L'intenzione del vecchio test resta, ed è questa**: un prezzo scritto
     * in un template è un prezzo che un giorno dirà una cosa diversa da quella
     * che il sistema fattura, e lo si scopre da un cliente arrabbiato invece
     * che da un test. Qui si cambia il listino e si guarda se la pagina segue.
     */
    #[Test]
    public function no_price_is_written_inside_a_view(): void
    {
        config()->set('listino.scaglioni', [['fino' => null, 'prezzo_cent' => 1234]]);
        config()->set('listino.singolo_cent', 4321);

        $this->get('/prezzi')
            ->assertOk()
            ->assertSee('12,34 €', escape: false)
            ->assertSee('43,21 €', escape: false);
    }

    /**
     * 🚨 **Il sito non annuncia funzioni che non esistono più.**
     *
     * ── Cosa provava prima, e perché è cambiato ────────────────────────────
     *
     * `the_price_list_says_how_many_ai_calls_are_included` e
     * `a_gym_sees_both_of_its_caps` provavano il **sotto-limite delle foto**
     * («400 richieste di cui 40 con foto») e i due tetti delle palestre.
     *
     * ⚠️ Il 15/08 il modello è passato a **una moneta sola**: non esiste più un
     * sotto-limite, esistono i gettoni, e una foto ne costa 10. Quei due test
     * difendevano una promessa che il prodotto non fa più.
     */
    #[Test]
    public function the_site_talks_about_tokens_not_about_a_photo_sub_limit(): void
    {
        $prezzi = $this->get('/prezzi')->assertOk();

        $prezzi->assertSee('gettoni', escape: false);
        $prezzi->assertSee('10', escape: false);

        // 💡 La formula vecchia non deve ricomparire: se un giorno qualcuno la
        // rimette, sta rimettendo anche un modello di prezzo che non esiste.
        $prezzi->assertDontSee('di cui con foto', escape: false);
    }

    /**
     * 🚨 **Il sito non promette una funzione che non esiste più** — 14/08/2026.
     *
     * ⚠️ Diceva «li assegni dal telefono», e dal 14/08 dal pannello **non si
     * assegna più niente**: un programma si consegna via chat cifrata (D4). Una
     * promessa che il prodotto non mantiene è peggio di una funzione mancante,
     * perché si scopre dopo aver pagato.
     */
    #[Test]
    public function the_site_does_not_promise_assignment(): void
    {
        $r = $this->get('/')->assertOk();

        $r->assertDontSee('li assegni');

        // 💡 E dice la cosa che al trainer interessa davvero sapere prima di
        // pagare: R8 — un programma consegnato non gli si può togliere. Dal
        // 16/08 sta fra le domande frequenti, che è dove uno se lo chiede.
        $r->assertSee('restano tuoi', escape: false);
    }

    // ═══════════════════ il sito ridisegnato — 16/08/2026 ═══════════════════

    /**
     * 🚨 **I due link legali del piè di pagina rispondevano 404.**
     *
     * Erano lì da quando il sito esiste: il footer prometteva l'informativa
     * privacy e le condizioni d'uso, e chi le apriva trovava una pagina di
     * errore. ⚠️ È il tipo di difetto che nessuno segnala — chi cerca
     * l'informativa e non la trova non scrive per lamentarsi, se ne va.
     */
    #[Test]
    public function the_legal_documents_are_actually_there(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Informativa privacy', escape: false);
        $this->get('/condizioni')->assertOk()->assertSee('Condizioni', escape: false);
    }

    #[Test]
    public function the_footer_only_promises_pages_that_exist(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        preg_match_all('~href="(/[a-z-]*)"~', $home, $trovati);

        foreach (array_unique($trovati[1]) as $percorso) {
            // 💡 Si saltano le rotte del pannello, che rimandano al login.
            if (str_starts_with($percorso, '/admin')) {
                continue;
            }

            $this->get($percorso)->assertOk();
        }
    }

    /**
     * ⚠️ **La lista dei documenti è chiusa, e questo test è il motivo.**
     *
     * 🚨 Senza il vincolo sulla rotta, il nome del documento finisce dentro un
     * percorso di file: `/../../.env` diventa una lettura arbitraria del disco.
     */
    #[Test]
    public function you_cannot_ask_for_a_document_that_is_not_on_the_list(): void
    {
        $this->get('/inventato')->assertNotFound();
        $this->get('/..%2f..%2f.env')->assertNotFound();
    }

    #[Test]
    public function the_terms_say_it_is_not_a_medical_device(): void
    {
        /*
         * 🚨 È la clausola per cui queste condizioni sono nate. L'app mostra
         * testi generati da un modello su dati di salute: se le condizioni non
         * dicono, in modo esplicito, che non è un dispositivo medico e che non
         * va seguito senza un professionista, il resto del documento non serve
         * a niente.
         */
        $this->get('/condizioni')
            ->assertOk()
            ->assertSee('Non è un dispositivo medico', escape: false)
            ->assertSee('medico dello sport', escape: false);
    }

    // ═══════════════════ i prezzi ═══════════════════

    #[Test]
    public function the_pricing_page_shows_the_real_numbers(): void
    {
        $listino = app(Listino::class);

        $this->get('/prezzi')
            ->assertOk()
            // Il primo scaglione, formattato come lo vede una persona.
            ->assertSee(number_format($listino->primoScaglione() / 100, 2, ',', '.').' €', escape: false)
            ->assertSee('500', escape: false);
    }

    /**
     * 🚨 **Gli scaglioni sono progressivi, come le aliquote.**
     *
     * I primi 25 posti costano il prezzo pieno **anche a chi ne ha 300**.
     * ⚠️ L'alternativa — «oltre 100 paghi tutto a 2,99» — creerebbe un salto in
     * cui **aggiungere un posto fa scendere la fattura**, e chi se ne accorge
     * lo racconta.
     */
    #[Test]
    public function the_tiers_are_progressive_not_a_cliff(): void
    {
        $listino = app(Listino::class);

        $this->assertSame(0, $listino->costoMensile(0));
        $this->assertSame(25 * 499, $listino->costoMensile(25));
        $this->assertSame(25 * 499 + 1 * 399, $listino->costoMensile(26));
        $this->assertSame(25 * 499 + 75 * 399 + 200 * 299, $listino->costoMensile(300));

        // 💡 La proprietà che conta più di ogni singolo numero: la fattura non
        // scende mai aggiungendo un posto.
        for ($n = 1; $n <= 150; $n++) {
            $this->assertGreaterThan(
                $listino->costoMensile($n - 1),
                $listino->costoMensile($n),
                "aggiungere il posto {$n} fa scendere la fattura",
            );
        }
    }

    #[Test]
    public function the_example_shows_what_the_gym_keeps(): void
    {
        $e = app(Listino::class)->esempio(60);

        // 💡 È il numero che vende: non «4,99 a posto» ma «incasso 600 e ne
        // pago 264».
        $this->assertSame(60, $e['posti']);
        $this->assertSame($e['ricavo'] - $e['costo'], $e['margine']);
        $this->assertGreaterThan(0, $e['margine']);
    }

    /**
     * 🚨 **Sulla home non si parla di soldi.**
     *
     * ── Perche' questo test esiste ────────────────────────────────────────
     *
     * La home del 16/08 mattina spiegava, nel terzo passo di «come funziona»,
     * che una palestra puo' rivendere un posto a 10 € e tenersene la meta'.
     *
     * ⚠️ **E' vero, ed e' la cosa sbagliata da dire li'.** Chi legge quella
     * pagina sta valutando se scaricare un'app: gli si stava spiegando che e'
     * un prodotto costruito per essere rivenduto addosso a lui. Quei numeri
     * stanno su `/prezzi`, che e' la pagina dove una palestra li cerca apposta.
     *
     * 💡 Il test guarda il **meccanismo**, non le parole esatte: qualunque
     * cifra in euro sulla home lo fa fallire, comunque sia stata scritta.
     */
    #[Test]
    public function the_home_does_not_pitch_the_reseller_margin(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        // Nessuna cifra in euro, in nessuna forma.
        $this->assertSame(
            0,
            preg_match_all('~\d+[.,]\d{2}\s*(€|&euro;)~u', $home),
            'sulla home è ricomparso un prezzo',
        );

        foreach (['rivend', 'margine', 'a posto', 'posti accesi', 'al mese'] as $parola) {
            $this->assertStringNotContainsStringIgnoringCase(
                $parola,
                $home,
                "sulla home è ricomparso il discorso commerciale: «{$parola}»",
            );
        }
    }

    /**
     * 💡 E dice **cosa fa l'app**, in ordine, prima di dire tutto il resto.
     *
     * I tre passi erano «entrano tutti / chi vuole l'AI la accende / la
     * palestra la rivende»: tre passi del **modello di business**. Chi li
     * leggeva non sapeva ancora cosa fa l'app, e imparava come si guadagna
     * sopra di lui.
     */
    #[Test]
    public function the_home_explains_the_product_in_three_steps(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Scarichi ed entri', escape: false)
            ->assertSee('Segni cosa mangi', escape: false)
            ->assertSee('Ricevi le schede dal tuo trainer', escape: false);
    }

    /**
     * 🚨 **Nessun pulsante «Scarica» finche' non c'e' niente da scaricare.**
     *
     * ⚠️ Uno che non scarica niente e' peggio di nessun pulsante: chi lo preme
     * una volta e non succede niente non lo preme piu', e non torna a
     * controllare se nel frattempo funziona.
     */
    #[Test]
    public function the_download_button_appears_only_when_there_is_a_store(): void
    {
        config()->set('app_mobile.android', '');
        config()->set('app_mobile.ios', '');

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Scarica per', escape: false)
            ->assertSee('Presto su Google Play', escape: false);

        config()->set('app_mobile.android', 'https://play.google.com/store/apps/details?id=finto');

        $this->get('/')
            ->assertOk()
            ->assertSee('Scarica per Android', escape: false)
            ->assertSee('https://play.google.com/store/apps/details?id=finto', escape: false);
    }

    // ═══════════════════ le immagini ═══════════════════

    /**
     * 🚨 **Il sito non deve sembrare guasto mentre è solo incompleto.**
     *
     * Le immagini vanno generate e caricate a mano. Un `<img src>` scritto
     * secco mostrerebbe l'icona dell'immagine rotta su tutte le pagine fino a
     * quel momento — e chi arriva non distingue «manca una foto» da «questo
     * sito non funziona».
     */
    #[Test]
    public function a_missing_image_leaves_a_deliberate_gap_not_a_broken_icon(): void
    {
        $this->svuotaLeImmagini();

        $home = $this->get('/')->assertOk();

        $home->assertSee('figura-vuota', escape: false);
        $home->assertDontSee('<img', escape: false);
    }

    /**
     * 💡 E quando il file arriva, compare da solo: nessun template da toccare.
     *
     * ── 🚨 Perche' comincia svuotando ─────────────────────────────────────
     *
     * Fino al 16/08 questo test **passava per il motivo sbagliato**: la cartella
     * era vuota, quindi il file finto era l'unico. Caricate le immagini vere, il
     * `.webp` viene prima del `.jpg` nell'ordine di preferenza e la ricerca
     * avrebbe trovato quello — provando che funziona una cosa che il test non
     * stava verificando.
     *
     * ⚠️ **Un test che non sistema la propria premessa non dimostra niente**, e
     * lo si scopre solo quando la premessa cambia.
     */
    #[Test]
    public function dropping_the_file_in_is_all_it_takes(): void
    {
        $this->svuotaLeImmagini();

        $this->creaImmagine('eroe');

        $this->get('/')
            ->assertOk()
            ->assertSee('/img/sito/eroe.jpg?v=', escape: false)
            // ⚠️ Le misure sono negli attributi, non nel CSS: servono a
            // riservare lo spazio **prima** del caricamento, o la pagina salta
            // in faccia a chi sta leggendo.
            ->assertSee('width="1600"', escape: false)
            ->assertSee('height="1200"', escape: false);
    }

    /**
     * 🚨 **L'indirizzo porta la data di modifica del file.**
     *
     * ⚠️ Senza, chi ha già visto il sito continuerebbe a vedere la vecchia
     * immagine dalla cache del browser anche dopo averla sostituita — e non
     * c'è nessuna catena di build che aggiunga un'impronta al nome.
     */
    #[Test]
    public function replacing_an_image_changes_its_address(): void
    {
        // ⚠️ Come sopra: senza svuotare si misurerebbe il `.webp` vero.
        $this->svuotaLeImmagini();

        $percorso = $this->creaImmagine('eroe');

        $prima = app(ImmaginiDelSito::class)->url('eroe');

        touch($percorso, time() + 60);
        clearstatcache(true, $percorso);

        $this->assertNotSame($prima, (new ImmaginiDelSito)->url('eroe'));
    }

    /**
     * 🚨 **Un `og:image` che punta a un file inesistente è peggio di nessuno**:
     * i servizi di messaggistica mostrano il riquadro rotto invece di
     * ripiegare sul titolo.
     */
    #[Test]
    public function the_social_preview_is_only_promised_when_it_exists(): void
    {
        $this->svuotaLeImmagini();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('og:image', escape: false)
            ->assertSee('name="twitter:card" content="summary"', escape: false);

        $this->creaImmagine('og');

        $this->get('/')
            ->assertOk()
            // ⚠️ Assoluto: chi legge questo tag non ha un indirizzo di base da
            // cui risolvere un percorso relativo.
            ->assertSee('property="og:image" content="http', escape: false)
            ->assertSee('summary_large_image', escape: false);
    }

    /**
     * ⚠️ Il nome dell'immagine arriva dai template, ma non si accetta niente
     * che non sia un nome: un `..` diventerebbe un percorso fuori da `public/`.
     */
    #[Test]
    public function an_image_name_cannot_climb_out_of_the_folder(): void
    {
        $this->assertNull(app(ImmaginiDelSito::class)->url('../../.env'));
        $this->assertNull(app(ImmaginiDelSito::class)->url('..'));
    }

    /**
     * 💡 **La lista della spesa non si ricava a occhio dalla cartella.**
     *
     * `mancanti()` è quello che dice cosa resta da produrre, ed è la stessa
     * lista che `LEGGIMI.md` ripete a chi apre la cartella invece del codice.
     */
    #[Test]
    public function the_shopping_list_says_what_is_still_missing(): void
    {
        $this->svuotaLeImmagini();

        $immagini = new ImmaginiDelSito;

        $this->assertSame(array_keys(ImmaginiDelSito::ATTESE), $immagini->mancanti());

        $this->creaImmagine('og');

        $this->assertNotContains('og', (new ImmaginiDelSito)->mancanti());
    }

    /**
     * 🚨 **Le sette immagini sono state caricate davvero.**
     *
     * ⚠️ Finche' mancavano, il sito mostrava un riempimento voluto e nessuno se
     * ne accorgeva — che era lo scopo. Ora che ci sono, il rischio si e'
     * capovolto: **se una sparisce, il riempimento la nasconde**. Questo test e'
     * l'unica cosa che se ne accorgerebbe.
     */
    #[Test]
    public function all_seven_images_are_actually_on_disk(): void
    {
        $this->assertSame([], (new ImmaginiDelSito)->mancanti());
    }

    /**
     * ⚠️ **E pesano poco.** Il server e' condiviso con altri due siti, e
     * un'immagine sostituita con l'originale da due megabyte non farebbe
     * fallire niente: farebbe solo aprire la pagina in cinque secondi, che e'
     * il genere di cosa che nessuno segnala.
     */
    #[Test]
    public function the_images_stay_light(): void
    {
        $immagini = new ImmaginiDelSito;

        foreach (array_keys(ImmaginiDelSito::ATTESE) as $nome) {
            $percorso = $immagini->url($nome);

            if ($percorso === null) {
                continue;
            }

            $file = public_path(parse_url($percorso, PHP_URL_PATH) ?? '');

            // 🚨 `og` piu' stretta delle altre: alcuni servizi di messaggistica
            // scartano le anteprime pesanti e tornano al riquadro grigio.
            $tetto = $nome === 'og' ? 200 : 300;

            $this->assertLessThan(
                $tetto * 1024,
                filesize($file),
                "{$nome} pesa piu' di {$tetto} KB",
            );
        }
    }

    // ─────────────────── i due aiutanti delle immagini ───────────────────

    /**
     * 🚨 Sposta via le immagini vere invece di cancellarle.
     *
     * ⚠️ Un test che cancellasse `public/img/sito/` distruggerebbe file veri
     * caricati a mano, che non stanno da nessun'altra parte.
     */
    private function svuotaLeImmagini(): void
    {
        foreach (glob(public_path(ImmaginiDelSito::CARTELLA).'/*') ?: [] as $file) {
            if (is_file($file) && ! str_ends_with($file, '.md')) {
                rename($file, $file.'.daparte');
            }
        }
    }

    private function creaImmagine(string $nome): string
    {
        $cartella = public_path(ImmaginiDelSito::CARTELLA);

        if (! is_dir($cartella)) {
            mkdir($cartella, 0755, true);
        }

        $percorso = "{$cartella}/{$nome}.jpg";
        file_put_contents($percorso, 'finta');

        $this->daRipulire[] = $percorso;

        return $percorso;
    }

    /** @var list<string> */
    private array $daRipulire = [];

    protected function tearDown(): void
    {
        foreach ($this->daRipulire as $percorso) {
            @unlink($percorso);
        }

        // ⚠️ E si rimettono a posto quelle vere, altrimenti il primo test che
        // le sposta le fa sparire dal sito di chi sta sviluppando.
        foreach (glob(public_path(ImmaginiDelSito::CARTELLA).'/*.daparte') ?: [] as $file) {
            rename($file, substr($file, 0, -strlen('.daparte')));
        }

        parent::tearDown();
    }

    // ─────────────────── L'atterraggio di un invito — F6.2 ───────────────────

    #[Test]
    public function a_valid_invite_says_who_invited_you(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna Trainer', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $invito = app(InvitiDelTrainer::class)->invita($trainer);

        $this->get('/invito/'.$invito->token)
            ->assertOk()
            ->assertSee('Anna Trainer');
    }

    /**
     * 🚨 **Un `GET` non consuma l'invito.**
     *
     * ⚠️ I servizi di messaggistica aprono i link da soli per mostrare
     * l'anteprima: un `GET` che riscattasse brucerebbe l'invito **prima** che il
     * destinatario lo abbia nemmeno visto.
     */
    #[Test]
    public function opening_the_link_does_not_burn_the_invite(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $invito = app(InvitiDelTrainer::class)->invita($trainer);

        $this->get('/invito/'.$invito->token)->assertOk();
        $this->get('/invito/'.$invito->token)->assertOk();

        $this->assertTrue($invito->fresh()->eValido());
    }

    /** ⚠️ Un solo messaggio per scaduto, usato, revocato e inesistente. */
    #[Test]
    public function every_dead_invite_says_the_same_thing(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Anna', 'anna@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $scaduto = app(InvitiDelTrainer::class)->invita($trainer);
        $scaduto->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get('/invito/'.$scaduto->token)
            ->assertOk()
            ->assertSee('non è più valido', escape: false)
            // 🚨 E non dice chi aveva invitato: un invito morto non deve
            // continuare a rivelare il nome di chi l'ha mandato.
            ->assertDontSee('Anna');

        $this->get('/invito/'.str_repeat('z', 32))
            ->assertOk()
            ->assertSee('non è più valido', escape: false);
    }

    /** Un token malformato non arriva nemmeno al controller. */
    #[Test]
    public function a_malformed_token_is_not_a_route(): void
    {
        $this->get('/invito/troppo-corto')->assertNotFound();
    }
}
