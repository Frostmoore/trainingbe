<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\PacchettoGettoni;
use App\Models\Plan;
use App\Services\Billing\Listino;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\ImmaginiDelSito;
use Database\Seeders\PacchettiGettoniSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
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

    /**
     * 🚨 **Le pagine del sito, in un posto solo.**
     *
     * ⚠️ Erano scritte a mano in quattro test diversi: il giorno in cui il
     * listino è diventato tre pagine (20/09), tre di quei quattro elenchi sono
     * rimasti indietro senza che niente diventasse rosso — continuavano a
     * provare le pagine vecchie, che esistevano ancora.
     *
     * 💡 Una pagina nuova si aggiunge **qui**, e tutti i controlli che valgono
     * per tutte (nessun JavaScript, nessun link rotto, le immagini) la
     * prendono in carico da soli.
     */
    private const LE_PAGINE = [
        '/',
        '/prezzi',
        '/prezzi/individuale',
        '/prezzi/trainer',
        '/prezzi/palestra',
        '/professionisti',
        // ✉️ 21/09/2026: il modulo di contatto è una pagina del sito come le
        // altre, e le regole del sito valgono anche per lei.
        '/scrivici',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🚨 **Una variabile statica di Livewire, non il sito** — 23/09/2026.
         *
         * Livewire ricorda in `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest`
         * di aver disegnato un componente, e da lì **inietta il suo script in
         * ogni pagina HTML dello stesso processo**. ⚠️ Nei test il processo è
         * uno solo: un `Livewire::test()` in un file precedente (le fatture, il
         * cambio carta) faceva comparire `<script>` sul sito, e il test «il sito
         * non ha JavaScript» falliva per un motivo che col sito non c'entra.
         * Il sito vero non ce l'ha (verificato servendo la home).
         */
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        $this->seed(PlanSeeder::class);

        /*
         * 🆕 🎟️ **E i tagli di gettoni** — 22/09/2026, fase F2.
         *
         * ⚠️ Dal 22/09 i prezzi dei gettoni **vivono nel database**, non più
         * in `config/listino.php`: un test che non li semina vede un listino
         * vuoto, e il sito non ha niente da mostrare.
         *
         * ⛔ Non si è messo un ripiego sul file dentro `Listino`: sarebbe
         * esattamente il difetto §86.1 — due listini che si contraddicono, uno
         * dei quali in vendita.
         */
        $this->seed(PacchettiGettoniSeeder::class);
    }

    /**
     * 🚨 **La home parla a chi si allena, e dice i tre modi di usarla.**
     *
     * ── ⚠️ Ha sostituito `the_home_page_speaks_to_the_three_audiences` ─────
     *
     * Fino al 19/09 la home aveva tre schede — «Per chi si allena da solo»,
     * «Per i trainer indipendenti», «Per le palestre» — cioè **tre inviti a
     * comprare** messi davanti a chi era arrivato per scaricare un'app.
     *
     * 🆕 Dal 20/09 il discorso a chi compra sta tutto su `/professionisti`,
     * che è una pagina sola e sua. La home resta di chi si allena e dice, in
     * fondo, i tre modi in cui la stessa app lo riguarda: da solo, con un
     * trainer, in palestra. 💡 L'ultima riga manda i professionisti dove
     * devono andare, invece di occupare la pagina con la loro metà.
     */
    #[Test]
    public function the_home_page_speaks_to_the_person_who_trains(): void
    {
        $r = $this->get('/')->assertOk();

        /*
         * 🆕 **La home rifatta del 20/09 sera.** 📌 *«deve mettere bene in
         * mostra tutte le funzionalità dell'app»*: le funzioni hanno una riga
         * ciascuna, con la schermata accanto.
         */
        $r->assertSee('Diario alimentare', escape: false)
            ->assertSee('Allenamenti', escape: false)
            ->assertSee('Schede e chat', escape: false);

        // 🚨 E chi compra ha una pagina sua, raggiungibile da qui.
        $r->assertSee('href="/professionisti"', escape: false);

        $r->assertDontSee('Laravel', escape: false);
    }

    /**
     * 🚨 **L'apertura è un'immagine a tutta pagina, con la sua fotografia.**
     *
     * 📌 Il committente, 20/09: *«non mi piace per niente l'hero, deve essere
     * un'immagine a piena pagina»*.
     *
     * ⚠️ E il **velo** fa parte dell'apertura, non è decorazione: il titolo è
     * bianco, e senza qualcosa che scurisca la fotografia sotto diventa
     * illeggibile con la prima immagine chiara che ci finisce. 💡 Non si
     * scopre finché non succede, e allora è già in produzione.
     */
    #[Test]
    public function the_hero_is_a_full_page_image(): void
    {
        $pagina = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="eroe', $pagina, "l'apertura non è a tutta pagina");
        $this->assertStringContainsString('eroe-sfondo', $pagina, "l'apertura non ha nessuna immagine");
        $this->assertStringContainsString('eroe-velo', $pagina, 'la fotografia non ha il velo sotto al titolo');

        // 📌 E il titolo vecchio, quello bocciato, non deve tornare.
        $this->assertStringNotContainsString('Quello che mangi e come ti alleni', $pagina);
    }

    /**
     * 📌 **La schermata «Oggi» dell'app si vede** — chiesta esplicitamente:
     * *«ci deve essere uno screen della schermata oggi dell'app da qualche
     * parte»*.
     *
     * 💡 Il test guarda il **telaio del telefono** e il nome dell'immagine, non
     * il file: le catture si fanno dall'emulatore e non ci sono ancora, e
     * quello che deve restare vero è che la pagina le **chieda**.
     */
    #[Test]
    public function the_home_shows_the_app_screens(): void
    {
        $pagina = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="telefono"', $pagina, 'nessuna schermata incorniciata');

        foreach (['app-oggi', 'app-diario', 'app-allenamento', 'app-chat'] as $schermata) {
            $this->assertStringContainsString(
                $schermata,
                $pagina,
                "la home non mostra la schermata «{$schermata}»",
            );
        }
    }

    /**
     * 🚨 **Il punto in cui si spiega cosa è gratis e cosa si paga.**
     *
     * 📌 *«spiegando il piano gratuito e quello su abbonamento in un punto
     * specifico della pagina … spingiamo comunque gli utenti ad abbonarsi, è
     * così che guadagno»*.
     *
     * 💡 Il confronto è una **tabella a due colonne**, non due paragrafi: la
     * domanda «cosa mi manca se non pago» si risponde guardando, non leggendo.
     */
    #[Test]
    public function the_home_explains_free_versus_plus_in_one_place(): void
    {
        $pagina = $this->get('/')->assertOk();

        $pagina->assertSee('id="gratis-o-plus"', escape: false);

        // Il prezzo vero del Plus, preso dal listino.
        $pagina->assertSee(
            number_format(app(Listino::class)->singolo() / 100, 2, ',', '.').' €',
            escape: false,
        );

        // E la spinta: da qui si va alla pagina che lo spiega per intero.
        $pagina->assertSee('href="/prezzi/individuale"', escape: false);
    }

    /**
     * 🚨 **La pagina di chi compra esiste, e dice per prima cosa cosa NON
     * potrà vedere.**
     *
     * ⚠️ È l'informazione che una palestra scoprirebbe altrimenti **dopo**
     * aver pagato — «come sarebbe che non vedo il peso dei miei iscritti?» —
     * e scoperta in quel momento è una richiesta di rimborso. Scritta prima,
     * è il motivo per sceglierci: dati che non hai non puoi perderli.
     */
    #[Test]
    public function the_professionals_page_says_what_they_cannot_see(): void
    {
        $r = $this->get('/professionisti')->assertOk();

        $r->assertSee('Cosa non potrai vedere', escape: false)
            ->assertSee('Peso, misure, foto dei progressi', escape: false)
            ->assertSee('Il diario alimentare', escape: false);

        // 📌 E dice che si firma una liberatoria, con i testi pubblici.
        $r->assertSee('art. 28', escape: false)
            ->assertSee('href="/liberatoria-palestra"', escape: false)
            ->assertSee('href="/liberatoria-trainer"', escape: false);
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
        config()->set('listino.singolo_cent', 4321);

        // 🆕 F9.1 — il prezzo del Plus si legge dal DATABASE (il pannello lo può
        // cambiare): il numero finto va messo anche lì, dove il sito lo cerca.
        Plan::query()->where('code', Plan::PLUS)->update(['price_cents' => 4321]);

        /*
         * 🆕 **La forma del listino è cambiata il 13/09/2026**: non più
         * «pacchetti di posti» con un numero solo, ma **piani a due tetti**.
         *
         * 🚨 Quello che questo test difende non è cambiato: **nessun prezzo
         * scritto dentro una vista**. Se un giorno qualcuno ne incolla uno nel
         * template, questo test resta verde solo perché il numero finto non
         * compare — ed è per quello che ce ne sono due, uno per platea.
         */
        config()->set('listino.palestre.piani', [
            ['codice' => 'finto', 'nome' => 'Finto', 'iscritti' => 10, 'trainer' => 2, 'prezzo_cent' => 1234, 'nota' => 'finto'],
        ]);

        // 🆕 Dal 20/09 sera il listino è **tre pagine**: il prezzo del
        // singolo si vede su quella individuale, i piani su quella palestre.
        $this->get('/prezzi/individuale')->assertOk()->assertSee('43,21 €', escape: false);
        $this->get('/prezzi/palestra')->assertOk()->assertSee('12,34 €', escape: false);
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
        $prezzi = $this->get('/prezzi/individuale')->assertOk();

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
        // ⚠️ Tutte e due le pagine, dal 20/09: il discorso a chi compra si è
        // spostato, e una promessa sbagliata si sposta con lui.
        foreach (['/', '/professionisti'] as $pagina) {
            $this->get($pagina)->assertOk()->assertDontSee('li assegni');
        }

        /*
         * 💡 E la home dice la cosa che interessa davvero sapere prima di
         * cominciare: R8 — un programma consegnato non si può più togliere.
         *
         * 🆕 **«restano tue»** dal 20/09, non più «restano tuoi»: il soggetto
         * della frase sono le **schede**. ⚠️ Cambia una vocale e non cambia
         * niente della regola, ma il test cerca una stringa: se resta quella
         * vecchia, diventa verde solo perché la frase non c'è più.
         */
        $this->get('/')->assertOk()->assertSee('restano tue', escape: false);
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

    /**
     * 🚨 **Ogni link del sito porta a una pagina che esiste** — tutte e tre
     * le pagine, non solo il piè di pagina della home.
     *
     * ── ⚠️ Perché è stato allargato il 20/09/2026 ──────────────────────────
     *
     * Perché guardava **solo la home**, e intanto la pagina dei prezzi aveva
     * un pulsante «Iscriviti ora» che puntava a `/registrati`: una rotta che
     * non è mai esistita. 🚨 Il pulsante principale della pagina che deve
     * vendere rispondeva **404**, e nessun test se ne accorgeva perché
     * nessuno guardava quella pagina.
     *
     * 💡 Chi preme un pulsante e finisce su una pagina d'errore non ne prova
     * un altro: se ne va, e non scrive per dirlo.
     */
    #[Test]
    public function every_link_on_the_site_leads_somewhere_that_exists(): void
    {
        foreach (self::LE_PAGINE as $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();

            // ⚠️ Anche i percorsi con una barra dentro (`/prezzi/trainer`) e
            // quelli con un codice di piano (`/iscriviti/gym_10`): il modello
            // vecchio si fermava al primo livello e non vedeva nessuna delle
            // pagine nuove.
            preg_match_all('~href="(/[a-z0-9/_-]*)"~', $html, $trovati);

            foreach (array_unique($trovati[1]) as $percorso) {
                // 💡 Si saltano le rotte del pannello, che rimandano al login.
                if (str_starts_with($percorso, '/admin')) {
                    continue;
                }

                $this->get($percorso)->assertOk("{$pagina} manda su {$percorso}, che non risponde");
            }
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

        $this->get('/prezzi/individuale')
            ->assertOk()
            // Il canone e il primo taglio di gettoni, come li vede una persona.
            ->assertSee(number_format($listino->singolo() / 100, 2, ',', '.').' €', escape: false)
            ->assertSee(number_format($listino->pacchettiGettoni()[0]['prezzo'] / 100, 2, ',', '.').' €', escape: false);

        // E i due listini a posti, ognuno sulla sua pagina.
        $this->get('/prezzi/trainer')
            ->assertOk()
            ->assertSee(number_format($listino->piani('trainer')[0]['prezzo'] / 100, 2, ',', '.').' €', escape: false);

        $this->get('/prezzi/palestra')
            ->assertOk()
            ->assertSee(number_format($listino->piani('palestre')[0]['prezzo'] / 100, 2, ',', '.').' €', escape: false);
    }

    /**
     * 🚨 **Il numero delle chiamate incluse NON si pubblica** — 20/09/2026.
     *
     * ── 🔄 Terza versione di questo test, e le tre ragioni sono diverse ────
     *
     * | Quando | Regola | Perché |
     * |---|---|---|
     * | fino al 17/08 | non si dice | accanto ai gettoni, la divisione fa scoprire che un pacchetto costa meno |
     * | 18/08 | **si dice** | 📌 *«a sto punto diciamolo, tanto sta scritto nelle condizioni»* |
     * | **20/09** | **non si dice** | 📌 *«150 incluse non è vero, sono quelle automatiche (poi ok sono 150 ma non lo diciamo)»* |
     *
     * 🚨 **E stavolta non è pudore: era una frase falsa.** Quella quota copre
     * **solo le chiamate automatiche**; tutto ciò che una persona chiede
     * toccando qualcosa si paga a gettoni, abbonata o no
     * (`CancelloDeiGettoni`, 31/08). Scrivere «150 elaborazioni incluse»
     * accanto al prezzo faceva credere di aver comprato 150 foto di piatti.
     *
     * 💡 Il test mette un numero riconoscibile e controlla che **non compaia
     * da nessuna parte**: è l'unico modo di accorgersi se qualcuno lo rimette.
     */
    #[Test]
    public function the_included_monthly_allowance_is_not_published(): void
    {
        config()->set('listino.chiamate_mensili', 7777);

        foreach (self::LE_PAGINE as $pagina) {
            $this->get($pagina)
                ->assertOk()
                ->assertDontSee('7.777', escape: false)
                ->assertDontSee('7777', escape: false);
        }
    }

    /**
     * 🚨 **Il sito non vende un catalogo che non esiste** — 20/09/2026.
     *
     * 📌 Il committente: *«La cosa di comparire più in alto nel catalogo per i
     * trainer non ha senso, i trainer proprio non compaiono nel catalogo …
     * anzi, in realtà è una stronzata anche per le palestre al momento, proprio
     * non esiste ancora un catalogo»*.
     *
     * ⚠️ **Il codice ce l'ha**: `/api/v1/catalogo`, le campagne a
     * visualizzazione, la scheda pubblica nel pannello. Ma come **prodotto**
     * non c'è: nell'app quella schermata è «trova qualcuno a cui scrivere»
     * dentro i messaggi, e nessuno vende quelle posizioni.
     *
     * 💡 Il test guarda le **parole**, non le intenzioni: qualunque promessa di
     * visibilità a pagamento lo fa fallire, comunque sia scritta. Il giorno in
     * cui il catalogo esiste davvero, si cancella questo test — con un commento
     * che dice perché.
     */
    #[Test]
    public function the_site_does_not_sell_a_catalogue_that_does_not_exist(): void
    {
        foreach (self::LE_PAGINE as $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();

            foreach (['comparire più in alto', 'visualizzazione', 'vetrina', 'a visualizzazione'] as $promessa) {
                $this->assertStringNotContainsString(
                    $promessa,
                    $html,
                    "{$pagina} promette una visibilità a pagamento che non si può comprare",
                );
            }
        }
    }

    /**
     * 🚨 **Il piano gratuito è raccontato com'è, coi suoi limiti.**
     *
     * ── ⛔ Cosa diceva la prima stesura, e perché era peggio di niente ─────
     *
     * Diceva *«Gratis: allenamenti, schede, storico e cronometro — tutto»*, e
     * *«Plus: scrivi cosa hai mangiato e le calorie le conta lei»* come se
     * fosse compreso. Nessuna delle due è vera:
     *
     * | Nel codice | Dove |
     * |---|---|
     * | Senza abbonamento si usano **le 3 schede più recenti**, e **solo di un giorno** | `limiti_delle_schede.dart` |
     * | Quello che chiedi tu si paga **a gettoni sempre**, abbonati o no | `CancelloDeiGettoni` |
     * | Senza abbonamento l'assistente **non parte da solo**, nemmeno coi gettoni | `CancelloDeiGettoni`, 20/09 |
     *
     * 🚨 Un limite scoperto **dopo** aver scaricato è una disinstallazione; un
     * limite scritto prima è una ragione per abbonarsi.
     */
    #[Test]
    public function the_free_plan_is_described_with_its_real_limits(): void
    {
        foreach (['/', '/prezzi/individuale'] as $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();

            $this->assertStringContainsString('3', $html);

            foreach (['schede', 'gettoni'] as $parola) {
                $this->assertStringContainsString($parola, $html, "{$pagina} non parla di {$parola}");
            }
        }

        // 🚨 I tre limiti veri, detti a parole, sulla pagina che li deve dire.
        $prezzi = $this->get('/prezzi/individuale')->assertOk();

        $prezzi->assertSee('3 schede più recenti', escape: false)
            ->assertSee('solo di un giorno', escape: false)
            // 🚨 Il limite scoperto leggendo l'app il 20/09 notte: senza
            // abbonamento si fa partire **un allenamento al giorno**.
            ->assertSee('Un allenamento al giorno', escape: false)
            // 💡 E le card che restano sfumate: sono la metà dell'offerta.
            ->assertSee('card sfumate', escape: false);

        /*
         * ⛔ **E non si promette come incluso quello che si paga a gettoni.**
         * La frase deve esserci in positivo: «si paga a gettoni in tutti e due
         * i casi».
         */
        $prezzi->assertSee('si paga a gettoni in tutti e', escape: false);
    }

    /**
     * 🚨 **La pagina dei prezzi non manda via chi la sta leggendo.**
     *
     * ── ⚠️ Ha sostituito `the_pricing_page_asks_you_to_sign_up` ────────────
     *
     * Quello pretendeva un pulsante «Iscriviti ora», ed era la cosa giusta da
     * volere: «Entra nel pannello» è quello che fa chi è **già cliente**.
     * ⛔ Il guaio è che quel pulsante puntava a `/registrati`, che non
     * esiste: il test verificava che ci fosse la **parola**, non che
     * portasse da qualche parte.
     *
     * 🆕 Dal 20/09 non si finge un'iscrizione che sul sito non si può fare:
     * chi si allena scarica l'app, chi compra passa da `/professionisti`.
     * 💡 La parte che restava vera — non mandare al login chi sta ancora
     * decidendo — è rimasta, e `every_link_on_the_site_leads_somewhere_that_exists`
     * copre il resto.
     */
    #[Test]
    public function the_pricing_page_does_not_send_the_reader_to_the_login(): void
    {
        foreach (['/prezzi', '/prezzi/individuale', '/prezzi/trainer', '/prezzi/palestra'] as $pagina) {
            $this->get($pagina)->assertOk()->assertDontSee('Entra nel pannello', escape: false);
        }

        // 💡 Il bivio manda dove si decide: le tre pagine, e chi allena.
        $this->get('/prezzi')->assertOk()->assertSee('href="/professionisti"', escape: false);
    }

    /**
     * 🚨 **Da una pagina dei prezzi ci si abbona**, e il pulsante porta al
     * modulo di quel piano.
     *
     * 📌 *«ci deve essere un modo per permettere alle palestre e ai trainers di
     * abbonarsi direttamente dal sito»*. ⛔ Fino al 20/09 non c'era: i prezzi
     * si leggevano e poi bisognava scrivere una mail.
     *
     * ⚠️ Il test prende i codici dal **listino**, non li scrive: il giorno che
     * un piano si chiama in un altro modo, il pulsante deve seguirlo.
     */
    #[Test]
    public function a_professional_can_subscribe_from_the_price_page(): void
    {
        $listino = app(Listino::class);

        foreach (['trainer' => '/prezzi/trainer', 'palestre' => '/prezzi/palestra'] as $platea => $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();

            foreach ($listino->piani($platea) as $piano) {
                $this->assertStringContainsString(
                    'href="/iscriviti/'.$piano['codice'].'"',
                    $html,
                    "{$pagina}: da {$piano['codice']} non ci si può abbonare",
                );
            }

            /*
             * ⛔ **Il su misura no**: ha un minimo e una fattura a consumo, si
             * concorda. Un pulsante «abbonati» lì vorrebbe dire far comprare
             * al buio un piano che nessuno ha spiegato.
             */
            $this->assertStringNotContainsString(
                'href="/iscriviti/'.$listino->suMisura($platea)['codice'].'"',
                $html,
                "{$pagina}: il su misura non si compra con un tocco",
            );
        }
    }

    /**
     * 🚨 **Tre platee, tre sezioni, e tre unità di misura diverse.**
     *
     * ⚠️ Palestre e trainer comprano **persone**, chi si allena da solo compra
     * **gettoni**. Metterle nella stessa tabella farebbe confrontare due
     * grandezze che non si confrontano.
     *
     * 🆕 20/09: le sezioni sono `#da-solo`, `#trainer`, `#palestre` — una
     * sotto l'altra, non più tre ante di un carosello.
     */
    #[Test]
    public function the_pricing_page_is_split_into_three(): void
    {
        /*
         * 🆕 **Tre pagine, non tre sezioni** — 20/09 sera. 📌 *«Prezzi deve
         * essere un menù a tendina con Individuale, trainer e palestra con tre
         * pagine diverse»*.
         *
         * ⚠️ Palestre e trainer comprano **persone**, chi si allena da solo
         * compra un abbonamento: su una pagina sola ognuno deve scorrere oltre
         * i prezzi degli altri due.
         */
        $bivio = $this->get('/prezzi')->assertOk();

        $bivio->assertSee('href="/prezzi/individuale"', escape: false)
            ->assertSee('href="/prezzi/trainer"', escape: false)
            ->assertSee('href="/prezzi/palestra"', escape: false);

        // E la tendina in cima porta alle stesse tre, da qualunque pagina.
        $this->get('/')->assertOk()
            ->assertSee('href="/prezzi/individuale"', escape: false)
            ->assertSee('href="/prezzi/trainer"', escape: false)
            ->assertSee('href="/prezzi/palestra"', escape: false);

        /*
         * 💡 E i due minimi del piano su misura sono diversi — 30 allievi per
         * un trainer, 60 iscritti per una palestra. ⚠️ Vanno detti dove si
         * mostra il prezzo: chi fa il conto con dieci allievi e legge il
         * prezzo di trenta, senza la spiegazione, pensa a un errore.
         */
        $this->get('/prezzi/trainer')->assertOk()
            ->assertSee('Da '.config('listino.trainer.a_consumo.minimo_iscritti').' allievi', escape: false);

        $this->get('/prezzi/palestra')->assertOk()
            ->assertSee('Da '.config('listino.palestre.a_consumo.minimo_iscritti').' iscritti', escape: false);
    }

    /**
     * 🚨 **Si parte da chi si allena da solo, non dalla palestra.**
     *
     * ⚠️ È la platea più larga e la sola che arriva qui **senza sapere già cosa
     * vuole comprare**: la palestra ci arriva sapendolo, e una linguetta le
     * basta. L'ordine nel documento è l'ordine in cui si scorre, quindi è
     * l'ordine che conta.
     */
    #[Test]
    public function the_pricing_page_starts_from_the_person_not_from_the_gym(): void
    {
        $pagina = $this->get('/prezzi')->assertOk()->getContent();

        // 💡 L'ordine delle tre porte sul bivio: è la platea più larga, ed è
        // la sola che arriva qui **senza sapere già cosa vuole comprare**.
        $solo = strpos($pagina, 'href="/prezzi/individuale"');
        $trainer = strpos($pagina, 'href="/prezzi/trainer"');
        $palestre = strpos($pagina, 'href="/prezzi/palestra"');

        /*
         * ⚠️ `assertIsInt` prima di confrontare: `strpos` torna `false` se
         * l'ancora non c'è, e `false < 100` è **vero**. 🚨 La versione
         * precedente di questo test confrontava tre `false` e passava
         * tranquilla su una pagina che non aveva più nessuna di quelle
         * ancore.
         */
        foreach (['individuale' => $solo, 'trainer' => $trainer, 'palestra' => $palestre] as $nome => $dove) {
            $this->assertIsInt($dove, "la porta «{$nome}» non c'e'");
        }

        $this->assertLessThan($trainer, $solo, 'la prima porta non è chi si allena da solo');
        $this->assertLessThan($palestre, $trainer, 'il trainer non viene prima della palestra');
    }

    /**
     * 💡 **Il sito non ha una riga di JavaScript, e nemmeno una richiesta a
     * un dominio che non è il nostro.**
     *
     * 🚨 Il backend gira su un server condiviso con altri due siti: ogni
     * dipendenza esterna è una cosa che può cadere portandosi dietro la
     * pagina che dovrebbe vendere il prodotto. ⚠️ E un carattere preso da un
     * CDN è l'indirizzo IP di chi legge, regalato a qualcun altro, su una
     * pagina che parla di non raccogliere dati.
     */
    #[Test]
    public function the_site_needs_no_javascript_and_no_cdn(): void
    {
        foreach ([...self::LE_PAGINE, '/privacy'] as $percorso) {
            $pagina = $this->get($percorso)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script', $pagina, "{$percorso} ha del JavaScript");
            $this->assertStringNotContainsString('https://cdn', $pagina, "{$percorso} chiama un CDN");
            $this->assertStringNotContainsString('fonts.googleapis', $pagina, "{$percorso} chiama un font esterno");
        }
    }

    /** 💡 I gettoni sono la scala di prezzo di chi si allena da solo. */
    #[Test]
    public function the_solo_section_is_priced_in_tokens(): void
    {
        /*
         * 🆕 **`nome` dal 20/09/2026**: i tagli si chiamano «Prova», «Serie»,
         * «Blocco», «Stagione». ⚠️ Non è facoltativo e non ha un ripiego: un
         * `?? $gettoni.' gettoni'` nella vista farebbe passare in silenzio un
         * listino configurato a metà, e il nome comparirebbe sbagliato sulla
         * pagina invece che rosso in un test.
         */
        /*
         * 🔄 **Il taglio si scrive in tabella, non in `config`** — 22/09/2026,
         * fase F2. 📌 *«ovviamente devono essere nel database, non in un
         * file»*.
         *
         * ⛔ Qui prima c'era `config()->set('listino.pacchetti', ...)`, e
         * passava: era il file a essere il listino. ⚠️ Adesso il file è solo il
         * **seme**, e un test che scrivesse ancora nella configurazione
         * proverebbe una strada che in produzione non esiste più.
         */
        PacchettoGettoni::query()->update(['attivo' => false]);

        PacchettoGettoni::query()->create([
            'codice' => 'gettoni_finti',
            'nome' => 'Finto',
            'gettoni' => 42,
            'prezzo_cent' => 1234,
            'nota' => 'finto',
        ]);

        $this->get('/prezzi/individuale')
            ->assertOk()
            ->assertSee('Finto', escape: false)
            ->assertSee('42 gettoni', escape: false)
            ->assertSee('12,34 €', escape: false);
    }

    /**
     * 🚨 **Un taglio piu' grande non puo' costare di piu' a gettone.**
     *
     * ── Perche' e' un test e non un buon proposito ────────────────────
     *
     * ⚠️ Se il pacchetto da 2000 costasse, per gettone, piu' di quello da 100,
     * chi fa la divisione — e qualcuno la fa sempre — comprerebbe venti
     * pacchetti da cento. Il listino sembrerebbe una **trappola** invece di uno
     * sconto, ed e' esattamente il genere di cosa che si racconta in giro.
     *
     * 💡 E' un'invariante facile da rompere senza accorgersene: basta
     * ritoccare un prezzo solo, che e' proprio quello che si fa.
     */
    #[Test]
    public function a_bigger_pack_never_costs_more_per_token(): void
    {
        $pacchetti = app(Listino::class)->pacchettiGettoni();

        $this->assertGreaterThan(1, count($pacchetti), 'servono almeno due tagli per confrontarli');

        $precedente = null;

        foreach ($pacchetti as $p) {
            $perGettone = $p['prezzo'] / $p['gettoni'];

            if ($precedente !== null) {
                $this->assertLessThan(
                    $precedente['prezzo_per_gettone'],
                    $perGettone,
                    sprintf(
                        'il taglio da %d costa %.2f centesimi a gettone, quello da %d ne costava %.2f',
                        $p['gettoni'],
                        $perGettone,
                        $precedente['gettoni'],
                        $precedente['prezzo_per_gettone'],
                    ),
                );
            }

            $precedente = ['gettoni' => $p['gettoni'], 'prezzo_per_gettone' => $perGettone];
        }
    }

    /** 💡 E i tagli sono in ordine crescente: un elenco disordinato non si legge. */
    #[Test]
    public function the_packs_are_listed_from_smallest_to_biggest(): void
    {
        $gettoni = array_column(app(Listino::class)->pacchettiGettoni(), 'gettoni');

        $ordinati = $gettoni;
        sort($ordinati);

        $this->assertSame($ordinati, $gettoni);
    }

    /**
     * 🚨 **Un pacchetto grande non può costare di piu' a posto di uno piccolo.**
     *
     * ── ⚠️ Ha sostituito `the_tiers_are_progressive_not_a_cliff` ───────
     *
     * Quello provava che la **scala progressiva** sui posti non avesse salti: i
     * primi 25 al prezzo pieno anche a chi ne aveva 300, come le aliquote. Era
     * corretta e **illeggibile** — per sapere quanto si spendeva bisognava fare
     * una somma a pezzi — e il 18/08 e' stata sostituita da pacchetti a prezzo
     * fisso.
     *
     * 💡 L'invariante che conta e' rimasta, in forma piu' semplice: **chi
     * compra di piu' paga di meno a posto**. Altrimenti chi fa la divisione
     * comprerebbe tanti pacchetti piccoli, e il listino sembrerebbe una
     * trappola invece di uno sconto.
     */
    #[Test]
    public function a_bigger_bundle_never_costs_more_per_seat(): void
    {
        $listino = app(Listino::class);

        foreach (['palestre', 'trainer'] as $platea) {
            $piani = $listino->piani($platea);

            $this->assertGreaterThan(1, count($piani), "{$platea}: servono almeno due piani");

            $precedente = null;

            foreach ($piani as $p) {
                if ($precedente !== null) {
                    $this->assertLessThan(
                        $precedente['perSlot'],
                        $p['perSlot'],
                        sprintf(
                            '%s: il piano da %d costa %d centesimi a slot, quello da %d ne costava %d',
                            $platea, $p['slot'], $p['perSlot'], $precedente['slot'], $precedente['perSlot'],
                        ),
                    );
                }

                $precedente = $p;
            }
        }
    }

    /**
     * 🚨 **Un posto costa sempre meno dell'abbonamento singolo.**
     *
     * ⚠️ Se costasse di piu', alla palestra converrebbe dire ai propri
     * iscritti «abbonatevi da soli», e il pacchetto non lo comprerebbe nessuno.
     * E' la regola da cui discende tutto il listino, ed e' la piu' facile da
     * rompere ritoccando **un** prezzo.
     */
    #[Test]
    public function a_seat_always_costs_less_than_an_individual_subscription(): void
    {
        $listino = app(Listino::class);
        $singolo = $listino->singolo();

        foreach (['palestre', 'trainer'] as $platea) {
            foreach ($listino->piani($platea) as $p) {
                $this->assertLessThan(
                    $singolo,
                    $p['perSlot'],
                    sprintf(
                        '%s: il piano da %d costa %d a slot, l\'abbonamento singolo %d',
                        $platea, $p['slot'], $p['perSlot'], $singolo,
                    ),
                );
            }
        }
    }

    /**
     * 🚨 **Il su misura è il piano dei numeri grandi, e il minimo è quello
     * che tiene fuori i numeri piccoli.**
     *
     * ── ⚠️ Ha ROVESCIATO `paying_as_you_go_costs_more_than_the_smallest_bundle`
     *
     * Fino al 19/09 il piano a consumo costava **di più** a persona: era il
     * prezzo della flessibilità, e il test difendeva quella differenza perché
     * senza di essa i pacchetti non sarebbero convenuti a nessuno.
     *
     * 🎯 Col listino vero (20/09) il su misura è un'altra cosa: **2,80 a
     * persona**, meno di ogni piano a canone, più un fisso. Non è il piano di
     * chi vuole flessibilità, è il piano di chi ha tanta gente — e a tanta
     * gente si fa uno sconto, non un sovrapprezzo.
     *
     * 💡 Quello che tiene in piedi i piani a canone non è più il prezzo
     * unitario ma il **minimo**: sotto 30 allievi (o 60 iscritti) il su
     * misura costa molto di più del piano che quelle persone le copre. Chi è
     * piccolo non può sbagliare scelta a proprio danno, ed è questo che il
     * test difende.
     *
     * ── 📌 Una sovrapposizione nota, e voluta ──────────────────────────────
     *
     * ⚠️ **Al minimo esatto il su misura costa meno del piano più grande**:
     * un trainer con 30 allievi paga 94 € su misura e 100 € col Pro; una
     * palestra con 60 iscritti paga 183 € contro 200 €. 💡 Non è una svista:
     * è il punto in cui si vuole che chi ha toccato il tetto **salga** invece
     * di andarsene, e sale trovando anche più posti trainer (20, contro i 6 del
     * Pro). Il listino l'ha deciso il committente il 20/09/2026, con i prezzi
     * finali; i posti trainer li ha stretti il 21/09 — ⛔ erano *illimitati*, ed
     * era il modo di far passare gli iscritti per istruttori e non pagarli.
     */
    #[Test]
    public function the_custom_plan_is_for_the_big_numbers_and_the_minimum_keeps_the_small_ones_out(): void
    {
        $listino = app(Listino::class);

        foreach (['palestre', 'trainer'] as $platea) {
            $misura = $listino->suMisura($platea);
            $piani = $listino->piani($platea);

            // 1. A persona il su misura costa meno di **ogni** piano a canone.
            foreach ($piani as $piano) {
                $this->assertLessThan(
                    $piano['perSlot'],
                    $misura['perSlot'],
                    "{$platea}: il su misura non fa sconto rispetto a {$piano['codice']}",
                );
            }

            // 2. Ma sotto il minimo si paga il minimo, canone compreso...
            $this->assertSame(
                $misura['canone'] + $misura['minimo'] * $misura['perSlot'],
                $listino->costoSuMisura($platea, 1),
                "{$platea}: sotto il minimo non si sta pagando il minimo",
            );

            // 3. ...e quel minimo costa più di ogni piano che ci sta dentro:
            //    chi è piccolo non risparmia passando al su misura.
            //    ⚠️ Solo quelli **sotto** il minimo: al minimo esatto il su
            //    misura costa meno, ed è la sovrapposizione voluta di sopra.
            foreach ($piani as $piano) {
                if ($piano['slot'] >= $misura['minimo']) {
                    continue;
                }

                $this->assertGreaterThan(
                    $piano['prezzo'],
                    $listino->costoSuMisura($platea, $piano['slot']),
                    "{$platea}: con {$piano['slot']} persone il su misura costa meno di {$piano['codice']}",
                );
            }
        }
    }

    #[Test]
    public function the_example_shows_what_the_gym_keeps(): void
    {
        $e = app(Listino::class)->esempio('palestre', 25);

        // 💡 È il numero che vende: non «4,99 a posto» ma «incasso 600 e ne
        // pago 264».
        $this->assertSame(25, $e['slot']);
        $this->assertSame($e['ricavo'] - $e['costo'], $e['margine']);
        $this->assertGreaterThan(0, $e['margine']);
    }

    /**
     * 🚨 **Sulla home non si fa il discorso della rivendita.**
     *
     * ── 🔄 Il test è stato RISTRETTO il 20/09/2026 ────────────────────────
     *
     * Fino a quel giorno vietava **qualunque cifra in euro** sulla home. La
     * ragione era buona: la home del 16/08 spiegava, nel terzo passo di «come
     * funziona», che una palestra può rivendere un posto e tenersi la
     * differenza. ⚠️ È vero, ed è la cosa sbagliata da dire lì — chi sta
     * valutando se scaricare un'app leggeva di essere la merce.
     *
     * 🎯 **Ma il committente ha chiesto il contrario sui prezzi**: *«spiegando
     * il piano gratuito e quello su abbonamento in un punto specifico della
     * pagina … spingiamo comunque gli utenti ad abbonarsi»*. Il prezzo del
     * Plus **deve** stare sulla home, e nasconderlo si leggeva come una
     * fregatura più avanti.
     *
     * 💡 Quello che resta vietato è il **discorso a chi compra**: rivendere,
     * margine, posti. Il test guarda il meccanismo — le parole — non il
     * giudizio di chi scrive.
     */
    #[Test]
    public function the_home_does_not_pitch_the_reseller_margin(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        /*
         * ⚠️ **Con i confini di parola, e c'è un motivo.**
         *
         * La prima versione cercava le parole come sottostringhe, e un
         * commento nel foglio di stile che diceva «occup**a posto**» faceva
         * fallire il test. 💡 Un controllo troppo largo su un testo in
         * italiano fallisce su prosa innocente, e il modo in cui lo si
         * «aggiusta» di solito è indebolirlo fino a non provare più niente.
         *
         * 🚨 E i commenti del foglio di stile **contano**: stanno dentro un
         * `<style>` incorporato, quindi sono testo della pagina. Ci è già
         * cascato due volte chi scriveva quei commenti.
         */
        foreach (['rivend\w*', 'margine', 'a posto', 'per posto', 'posti accesi'] as $parola) {
            $this->assertSame(
                0,
                preg_match('~\b'.$parola.'\b~iu', $home),
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
            // 🆕 20/09: «di chi ti segue», non «dal tuo trainer» — può essere
            // la palestra, e chi ce l'ha non si riconoscerebbe nell'altra.
            ->assertSee('Ricevi le schede di chi ti segue', escape: false);
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
     * 🚨 **Le immagini che ci sono ci sono, e quelle che mancano sono
     * dichiarate.**
     *
     * ── ⚠️ Ha sostituito `all_seven_images_are_actually_on_disk` ───────────
     *
     * Quello pretendeva che `mancanti()` fosse **vuoto**, ed era giusto finché
     * l'elenco conteneva solo le sette fotografie caricate il 16/08. ⛔ Col
     * sito del 20/09 le pagine chiedono anche le **schermate dell'app**, che si
     * catturano dall'emulatore e non ci sono ancora: con la regola vecchia la
     * suite sarebbe rimasta rossa fino a quel giorno, e un test rosso che si
     * impara a ignorare smette di servire.
     *
     * 💡 Adesso il buco si **dichiara** in `ImmaginiDelSito::ANCORA_DA_PRODURRE`
     * e il test confronta le due liste. Se sparisce un'immagine che c'era →
     * rosso. Se ne arriva una di quelle dichiarate e nessuno aggiorna la lista
     * → rosso lo stesso. L'unico modo di stare verdi è dire la verità.
     */
    #[Test]
    public function the_images_on_disk_match_what_is_declared_missing(): void
    {
        $mancanti = (new ImmaginiDelSito)->mancanti();
        $dichiarate = ImmaginiDelSito::ANCORA_DA_PRODURRE;

        sort($mancanti);
        sort($dichiarate);

        $this->assertSame(
            $dichiarate,
            $mancanti,
            'le immagini mancanti non sono quelle dichiarate in ANCORA_DA_PRODURRE',
        );
    }

    /**
     * 🚨 **Ogni immagine attesa è usata davvero da una pagina.**
     *
     * ── ⚠️ Il buco che questo test chiude — 20/09/2026 ─────────────────────
     *
     * `all_seven_images_are_actually_on_disk` guarda **il disco**. La
     * riscrittura del sito del 20/09 ha rifatto home, prezzi e la pagina dei
     * professionisti senza rimettere nemmeno un `<x-sito.figura>`: sette
     * fotografie vere, generate e caricate a mano, restate su disco senza che
     * nessuna pagina le mostrasse. ⛔ **La suite era tutta verde.**
     *
     * 💡 Qui si svuota la cartella apposta: senza i file, ogni figura lascia
     * il suo riempimento con dentro `data-attesa="<nome>"`, e quello dice
     * quali immagini le pagine **chiedono** — che è la cosa da controllare.
     * `og` è a parte: sta nel `<head>`, non nel corpo.
     */
    #[Test]
    public function every_expected_image_is_actually_used_on_a_page(): void
    {
        $this->svuotaLeImmagini();

        $chieste = [];

        foreach (self::LE_PAGINE as $percorso) {
            preg_match_all(
                '~data-attesa="([a-z-]+)"~',
                $this->get($percorso)->assertOk()->getContent(),
                $trovate,
            );

            $chieste = array_merge($chieste, $trovate[1]);
        }

        $chieste = array_unique($chieste);
        sort($chieste);

        $attese = array_values(array_diff(array_keys(ImmaginiDelSito::ATTESE), ['og']));
        sort($attese);

        $this->assertSame(
            $attese,
            $chieste,
            'ci sono immagini attese che nessuna pagina mostra, o viceversa',
        );
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
        $this->conPianoDaTrainer($trainer);

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
        $this->conPianoDaTrainer($trainer);

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
        $this->conPianoDaTrainer($trainer);

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
