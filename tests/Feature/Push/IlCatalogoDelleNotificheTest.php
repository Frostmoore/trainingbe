<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\CanaleDellaNotifica;
use App\Enums\Notifica;
use App\Enums\UserRole;
use App\Jobs\MandaLaNotifica;
use App\Models\User;
use App\Services\Notifiche\LeNotifiche;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔔 **Il catalogo delle notifiche regge da solo** — voce 62, 27/09/2026.
 *
 * ══ 📌 COSA SI STA PROTEGGENDO ════════════════════════════════════════════
 *
 * 📌 Il committente: *«il sistema delle notifiche fallo autoconsistente. Voglio
 * un solo punto dove posso aggiungere notifiche»*.
 *
 * 🔑 «Un solo punto» non è una comodità di scrittura: è una **garanzia**, e
 * regge solo se non esistono strade laterali. ⛔ Questo file è quello che
 * verifica che non ce ne siano.
 *
 * ══ ⚠️ E LA COSA CHE PROTEGGE DAVVERO ═════════════════════════════════════
 *
 * 🚨 Che nessuno mandi per **push** una notifica che è **locale**. Sarebbe far
 * passare dal server, e da Google, un dato che per scelta lì non ci passa — e
 * verrebbe fatto senza cattiveria, da qualcuno che copia una chiamata esistente
 * perché «era più comodo».
 */
class IlCatalogoDelleNotificheTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **La porta rifiuta una notifica locale.**
     *
     * ⚠️ Lancia, non restituisce `false`: ⛔ un rifiuto silenzioso sarebbe una
     * notifica che non parte e nessuno che se ne accorge. 💡 È un difetto di
     * programmazione, e va visto in sviluppo.
     */
    #[Test]
    public function una_notifica_locale_non_puo_partire_dal_server(): void
    {
        $palestra = $this->creaPalestra('Catalogo', 'catalogo');
        $utente = $this->creaUtente($palestra, UserRole::Member, 'tizio@catalogo.test');

        foreach (Notifica::locali() as $locale) {
            try {
                app(LeNotifiche::class)->manda($locale, $utente, 'titolo', 'corpo');

                self::fail(
                    "🚨 {$locale->value} è partita dal server: è una notifica LOCALE. "
                    .'⛔ Il server non deve sapere quando ti alleni.',
                );
            } catch (LogicException $e) {
                self::assertStringContainsString($locale->value, $e->getMessage());
            }
        }
    }

    /**
     * ✅ **E una push parte.** ⚠️ Senza questo, il test qui sopra passerebbe
     * anche con le notifiche **spente del tutto**: direbbe «le locali non
     * partono» e avrebbe ragione per il motivo peggiore.
     *
     * 💡 Non si prova che Firebase consegni — non è configurato in prova — ma
     * che la porta **non rifiuti**: è la differenza fra «vietato» e «rotto».
     *
     * 🔄 **Dal 27/09 `manda()` accoda**, quindi la prova è che il job ci sia.
     */
    #[Test]
    public function una_notifica_push_non_viene_rifiutata(): void
    {
        Queue::fake();

        $palestra = $this->creaPalestra('Catalogo Due', 'catalogo-due');
        $utente = $this->creaUtente($palestra, UserRole::Member, 'caio@catalogo-due.test');

        app(LeNotifiche::class)->manda(Notifica::Messaggio, $utente, 'titolo', 'corpo');

        Queue::assertPushed(MandaLaNotifica::class, 1);
    }

    /**
     * 🆕 📬 **Le push passano dalla CODA, non dalla richiesta** — 27/09/2026.
     *
     * ══ 📌 LA DOMANDA DEL COMMITTENTE ═════════════════════════════════════
     *
     * 📌 *«notifiche e email passano per una queue tipo redis? Perché potrebbe
     * essere una buona idea, e se non lo fanno poi succede sempre un casino con
     * le notifiche»*.
     *
     * ⛔ La risposta era **«solo in parte»**: le tre push che c'erano da prima
     * partivano da job accodati, le otto aggiunte il 27/09 partivano **dentro la
     * richiesta**. 🚨 `IncassoFallito` partiva dentro un webhook di Stripe, che
     * aspetta la risposta e a ~20 s dichiara il webhook fallito e **lo rimanda**.
     *
     * ══ 🔑 COSA DIMOSTRA QUESTO TEST ══════════════════════════════════════
     *
     * 💡 Che `manda()` **non tocca la rete**: accoda e torna. ⚠️ Vale per tutte e
     * undici le push, perché la regola sta nella porta e non nei chiamanti — che
     * sarebbero undici posti da non sbagliare.
     */
    #[Test]
    public function la_porta_accoda_e_non_manda_dentro_la_richiesta(): void
    {
        Queue::fake();

        $palestra = $this->creaPalestra('In coda', 'in-coda');
        $utente = $this->creaUtente($palestra, UserRole::Member, 'coda@in-coda.test');

        foreach (Notifica::push() as $push) {
            app(LeNotifiche::class)->manda($push, $utente, 'titolo', 'corpo');
        }

        Queue::assertPushed(MandaLaNotifica::class, count(Notifica::push()));

        /*
         * 🚨 **E il job porta quello che serve a consegnarla**: il tipo giusto e
         * l'**id** della persona, non il modello. ⛔ Con `SerializesModels` un
         * utente cancellato fra l'accodamento e la consegna farebbe fallire il
         * job, e la coda lo ritenterebbe per niente.
         */
        Queue::assertPushed(
            MandaLaNotifica::class,
            fn (MandaLaNotifica $job): bool => $job->quale === Notifica::IncassoFallito
                && $job->utenteId === (int) $utente->getKey(),
        );
    }

    /**
     * 🆕 🔒 **Nessuno salta la coda.**
     *
     * ⛔ `consegnaAdesso()` è `public` perché `MandaLaNotifica` vive in un'altra
     * classe e deve poterla chiamare. 🚨 Ma è anche la scorciatoia perfetta: chi
     * ha fretta la chiama da una richiesta e rimette l'HTTP verso Google dentro
     * la risposta — cioè il difetto che la coda è venuta a chiudere.
     *
     * 💡 Guarda la **forma**, non i nomi: cerca chi *usa* il metodo. 🔑 È la
     * stessa lezione del residuo `PlanImportResource::iscritti()`.
     */
    #[Test]
    public function nessuno_consegna_a_mano_saltando_la_coda(): void
    {
        $ammessi = [
            'app/Jobs/MandaLaNotifica.php',
            'app/Services/Notifiche/LeNotifiche.php',
        ];

        $colpevoli = [];
        $guardati = 0;

        $file = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));

            if (in_array($relativo, $ammessi, true)) {
                continue;
            }

            $guardati++;

            if (str_contains((string) file_get_contents($f->getPathname()), '->consegnaAdesso(')) {
                $colpevoli[] = $relativo;
            }
        }

        self::assertGreaterThan(50, $guardati, '⛔ Troppo pochi file guardati: il test non protegge niente.');

        self::assertSame(
            [],
            $colpevoli,
            "🚨 Qualcuno consegna push senza passare dalla coda:\n  ".implode("\n  ", $colpevoli)."\n"
            .'⛔ Dentro una richiesta vuol dire una chiamata HTTP a Google per ogni dispositivo, '
            .'in fila, mentre qualcuno aspetta — e in un webhook di Stripe vuol dire un timeout.',
        );
    }

    /**
     * 🔒 **NESSUNO chiama `PushSender` all'infuori della porta.**
     *
     * ══ 🚨 E' LA GUARDIA CHE RENDE VERA LA FRASE «UN SOLO PUNTO» ══════════
     *
     * ⛔ Senza, `LeNotifiche` sarebbe solo **una strada consigliata**: chi ha
     * fretta chiama `PushSender::toUser()` e si porta dietro il suo `tipo`
     * scritto a mano — che è esattamente com'era prima del 27/09.
     *
     * 💡 Guarda la **forma** e non i nomi: cerca chi *usa* la classe, non chi la
     * chiama in un modo particolare. 🔑 È la stessa lezione del residuo
     * `PlanImportResource::iscritti()`, trovato lo stesso giorno: una guardia
     * costruita su una lista di nomi noti lascia passare quello che si chiama
     * diversamente.
     */
    #[Test]
    public function nessuno_usa_il_mittente_delle_push_fuori_dalla_porta(): void
    {
        $ammessi = [
            'app/Services/Notifiche/LeNotifiche.php',
            'app/Services/Push/PushSender.php',
        ];

        $colpevoli = [];
        $guardati = 0;

        $file = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));

            if (in_array($relativo, $ammessi, true)) {
                continue;
            }

            $guardati++;

            $codice = (string) file_get_contents($f->getPathname());

            /*
             * ⚠️ Si cercano l'**uso come tipo** e la **chiamata**, non il nome
             * nudo: nei commenti la classe si nomina per spiegare com'è fatto il
             * giro, e vietare anche quello vorrebbe dire cancellare la
             * spiegazione — cioè la cosa che serve di più.
             */
            foreach (['PushSender $', 'PushSender::class', '->toUser('] as $spia) {
                if (str_contains($codice, $spia)) {
                    $colpevoli[] = "{$relativo} ({$spia})";
                }
            }
        }

        self::assertGreaterThan(50, $guardati, '⛔ Troppo pochi file guardati: il test non protegge niente.');

        self::assertSame(
            [],
            $colpevoli,
            "🚨 Qualcuno manda push fuori da LeNotifiche:\n  ".implode("\n  ", $colpevoli)."\n"
            .'⛔ Da lì si può mandare per push una notifica che è locale, e il catalogo '
            .'smette di essere «un solo punto».',
        );
    }

    /**
     * 📋 **Il catalogo è completo e senza doppioni.**
     *
     * ⚠️ Due casi con lo stesso valore sarebbero due notifiche che l'app non
     * riesce a distinguere: toccandole aprirebbe la schermata sbagliata.
     */
    #[Test]
    public function il_catalogo_e_coerente(): void
    {
        $valori = array_map(static fn (Notifica $n): string => $n->value, Notifica::cases());

        self::assertSame(
            count($valori),
            count(array_unique($valori)),
            '⛔ Due notifiche hanno lo stesso valore: l\'app non può distinguerle.',
        );

        /* 🔑 Ogni caso ha un canale: lo impone il `match` esaustivo, qui si verifica. */
        foreach (Notifica::cases() as $n) {
            self::assertInstanceOf(CanaleDellaNotifica::class, $n->canale());
        }

        self::assertSame(
            count(Notifica::cases()),
            count(Notifica::push()) + count(Notifica::locali()),
            '⛔ Qualche notifica non finisce in nessuno dei due elenchi.',
        );
    }

    /**
     * ⛔ **E `PushSender` resta raggiungibile solo perché la porta lo usa.**
     *
     * 💡 Non si può renderlo `private` — è un servizio nel contenitore — quindi
     * la garanzia è il test qui sopra. ⚠️ Questo verifica solo che la porta
     * **ci sia**: se qualcuno cancellasse `LeNotifiche` credendo di semplificare,
     * il test precedente diventerebbe verde per il motivo sbagliato.
     */
    #[Test]
    public function la_porta_esiste_e_usa_il_mittente(): void
    {
        self::assertTrue(class_exists(LeNotifiche::class));

        $costruttore = (new \ReflectionClass(LeNotifiche::class))->getConstructor();

        self::assertNotNull($costruttore);

        $tipi = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $costruttore->getParameters(),
        );

        self::assertContains(PushSender::class, $tipi);
    }

    /**
     * 🆕 🧭 **Ogni push dice dove porta, e nessuna dice un percorso** — 27/09/2026.
     *
     * 📌 Il committente: *«ci deve essere un altro parametro nelle notifiche: dove
     * rimanda. Cioè a che pagina dell'app o a che url»*.
     *
     * ══ 🚨 LE DUE META' DELLA REGOLA ══════════════════════════════════════
     *
     * 1. **Una push senza destinazione è una notifica che si può solo scartare.**
     *    ⛔ E non si vedrebbe come un difetto: si vedrebbe come «l'ho toccata e
     *    non è successo niente» — il sintomo con cui il difetto di `avviso` è
     *    sopravvissuto due giorni a settembre.
     * 2. **Una destinazione non è un indirizzo.** 🚨 Se qui ci finisse
     *    `/acquisti` invece di `abbonamento`, il server deciderebbe **quale
     *    schermata** apre l'app — e da lì, cambiando una stringa, qualunque
     *    schermata. 💡 Il vincolo si prova qui, dove è facile violarlo per
     *    comodità.
     */
    #[Test]
    public function ogni_push_dichiara_dove_porta_e_lo_fa_con_un_nome(): void
    {
        foreach (Notifica::push() as $n) {
            $dove = $n->destinazione();

            self::assertNotNull(
                $dove,
                "⛔ {$n->value} parte dal server e non dice dove porta: toccarla non farebbe niente.",
            );

            self::assertMatchesRegularExpression(
                '/^[a-z]+(-[a-z]+)*$/',
                $dove,
                "🚨 {$n->value}: «{$dove}» non è un nome. ⛔ Una destinazione è un nome che "
                .'l\'app traduce con un elenco chiuso, non un percorso che apre e basta.',
            );
        }
    }

    /**
     * 🆕 📱 **E le locali non ne hanno, perché non passano da qui.**
     *
     * ⚖️ Non è un dettaglio formale: una locale con una destinazione dichiarata
     * sarebbe il segno che qualcuno **sta pensando di mandarla dal server** — e
     * `manda()` la rifiuterebbe, ma solo a cose fatte. 💡 Meglio che il catalogo
     * dica di no prima.
     */
    #[Test]
    public function le_locali_non_dichiarano_nessuna_destinazione(): void
    {
        foreach (Notifica::locali() as $n) {
            self::assertNull(
                $n->destinazione(),
                "⛔ {$n->value} è locale: la programma l'app, che sa già dove sta. "
                .'🚨 Una destinazione qui è il sintomo di una locale che qualcuno vuole mandare in push.',
            );
        }
    }

    /**
     * 🆕 📦 **La destinazione finisce davvero nel carico, e la mette la porta.**
     *
     * ══ 🚨 PERCHE' NON BASTA PROVARE `destinazione()` ══════════════════════
     *
     * ⛔ Un metodo che restituisce il nome giusto e un carico che non lo contiene
     * sono **compatibili**: il catalogo direbbe la verità e l'app non la
     * riceverebbe mai. 💡 Quindi qui si guarda cosa arriva a `PushSender`, non
     * cosa dice l'enum.
     *
     * 🔑 E si verifica l'altra metà: che `manda()` **non abbia** un parametro per
     * la destinazione. ⚠️ Finché non c'è, due notifiche dello stesso tipo non
     * possono finire su pagine diverse — che è tutto il senso di averla messa in
     * catalogo.
     *
     * ══ ⚠️ CHIAMA `consegnaAdesso()`, ED E' L'UNICO POSTO DOVE E' GIUSTO ══
     *
     * 🔄 Dal 27/09 `manda()` **accoda**, quindi il carico non esiste ancora quando
     * torna. 💡 Qui interessa proprio il carico, cioè il pezzo che il job esegue:
     * si prova la consegna. ⛔ Passare da `manda()` + `Queue::fake()` proverebbe
     * che il job è stato accodato, che è un'altra cosa — e la prova dell'altra
     * cosa c'è già, in `la_porta_accoda_e_non_manda_dentro_la_richiesta`.
     */
    #[Test]
    public function la_consegna_mette_la_destinazione_nel_carico(): void
    {
        $visti = [];

        $finto = new class($visti) extends PushSender
        {
            /** @param array<int, array<string, mixed>> $visti */
            public function __construct(private array &$visti) {}

            public function toUser(User $utente, string $titolo, string $corpo, array $dati = []): int
            {
                $this->visti[] = $dati;

                return 1;
            }
        };

        $porta = new LeNotifiche($finto);
        $utente = User::factory()->create();

        foreach (Notifica::push() as $n) {
            $porta->consegnaAdesso($n, $utente, 'titolo', 'corpo');
        }

        self::assertCount(count(Notifica::push()), $visti);

        foreach (Notifica::push() as $i => $n) {
            self::assertSame($n->value, $visti[$i]['tipo'] ?? null);

            self::assertSame(
                $n->destinazione(),
                $visti[$i]['dove'] ?? null,
                "⛔ {$n->value}: il carico non porta la destinazione dichiarata. "
                .'🚨 Il catalogo direbbe il vero e l\'app non lo saprebbe.',
            );
        }

        /* 🔑 L'altra metà: nessuno può passarla da fuori. */
        $parametri = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(LeNotifiche::class, 'manda'))->getParameters(),
        );

        self::assertNotContains(
            'dove',
            $parametri,
            '🚨 `manda()` ha un parametro per la destinazione: ⛔ così due notifiche dello '
            .'stesso tipo tornano a poter finire su pagine diverse, e il catalogo non decide più.',
        );
    }

    /**
     * 🆕 🔒 **`daFare()` non può mentire** — voce 62, 27/09/2026.
     *
     * ══ 🚨 A COSA SERVE UNA GUARDIA SU UN ELENCO DI BUONE INTENZIONI ══════
     *
     * `daFare()` dice quali notifiche sono **dichiarate e non ancora fatte**.
     * ⛔ Un elenco del genere si degrada da solo in due modi opposti, e nessuno
     * dei due dà un errore:
     *
     *   1. 🚨 **si implementa una notifica e si dimentica di togliere il caso**:
     *      il documento dice «da fare» per sempre, e qualcuno la rifà da capo;
     *   2. 🚨 **si toglie il caso senza implementare niente**: il catalogo
     *      dichiara fatta una notifica che **nessuno manda**, ed è peggio —
     *      perché è la cosa che si crede e non si controlla.
     *
     * 💡 Questo test lega le due metà: una notifica fuori da `daFare()` deve
     * avere un punto del codice che la manda, e una dentro non deve averlo.
     *
     * ══ 🚨 E COPRE SOLO LE PUSH — VERIFICATO, NON SUPPOSTO ════════════════
     *
     * ⚠️ Il ciclo gira su `Notifica::push()`, e **non è una svista**: una notifica
     * locale la programma **l'app**, quindi in `app/` non c'è niente da trovare e
     * qualunque controllo qui direbbe sempre «non fatta».
     *
     * 🔎 Provato il 27/09 togliendo `SchedaFinita` da `daFare()`: questo test
     * resta **verde**, perché `SchedaFinita` è locale. ⛔ Quindi la guardia sulle
     * quattro locali **non è qui**: sta in
     * `trainingfe/test/catalogo_delle_notifiche_test.dart`, che cerca nel codice
     * dell'app — l'unico posto dove una locale può essere implementata.
     *
     * 💡 Scritto perché la prossima persona non creda che questo test copra
     * sedici notifiche: ne copre undici.
     *
     * ══ ⚠️ COME CERCA, E PERCHE' COSI' ════════════════════════════════════
     *
     * 🔎 Cerca `Notifica::NomeDelCaso` in `app/`, escludendo l'enum stesso e la
     * porta — che li nominano tutti per definizione. ⚠️ Una ricerca testuale e
     * non un'analisi: ⛔ un analizzatore statico per questa domanda sarebbe un
     * secondo progetto, e la ricerca sbaglia solo **in sicurezza** (se qualcuno
     * costruisse il caso da una stringa, il test lo direbbe «non fatto» — cioè
     * chiederebbe di guardarci, non di fidarsi).
     */
    #[Test]
    public function chi_non_e_piu_da_fare_ha_davvero_un_posto_che_la_manda(): void
    {
        $ammessi = [
            'app/Enums/Notifica.php',
            'app/Services/Notifiche/LeNotifiche.php',
        ];

        $codice = '';
        $guardati = 0;

        $file = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));

            if (in_array($relativo, $ammessi, true)) {
                continue;
            }

            $guardati++;
            $codice .= (string) file_get_contents($f->getPathname());
        }

        self::assertGreaterThan(50, $guardati, '⛔ Troppo pochi file guardati: il test non protegge niente.');

        foreach (Notifica::push() as $n) {
            $laManda = str_contains($codice, 'Notifica::'.$this->nomeDelCaso($n));

            if ($n->daFare()) {
                self::assertFalse(
                    $laManda,
                    "🚨 {$n->value} è segnata «da fare» ma c'è del codice che la manda. "
                    .'💡 Se è fatta, va togliata da daFare(): lasciarla lì la fa rifare a qualcuno.',
                );

                continue;
            }

            self::assertTrue(
                $laManda,
                "🚨 {$n->value} NON è segnata «da fare», ma nessun punto del codice la manda. "
                .'⛔ Il catalogo dichiara fatta una notifica che non parte mai — ed è la cosa '
                .'che si crede e non si controlla.',
            );
        }
    }

    /** 🔤 Dal valore al nome del caso: `incasso_fallito` → `IncassoFallito`. */
    private function nomeDelCaso(Notifica $n): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $n->value)));
    }

    /** ⚠️ Un utente serve solo a non far fallire per altro. */
    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(class_exists(User::class));
    }
}
