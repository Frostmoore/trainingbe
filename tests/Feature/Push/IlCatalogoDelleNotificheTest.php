<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\CanaleDellaNotifica;
use App\Enums\Notifica;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Notifiche\LeNotifiche;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     */
    #[Test]
    public function una_notifica_push_non_viene_rifiutata(): void
    {
        $palestra = $this->creaPalestra('Catalogo Due', 'catalogo-due');
        $utente = $this->creaUtente($palestra, UserRole::Member, 'caio@catalogo-due.test');

        /* ⚠️ Nessun dispositivo registrato: torna 0, e va bene. Non lancia. */
        $quanti = app(LeNotifiche::class)->manda(
            Notifica::Messaggio,
            $utente,
            'titolo',
            'corpo',
        );

        self::assertSame(0, $quanti);
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

    /** ⚠️ Un utente serve solo a non far fallire per altro. */
    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(class_exists(User::class));
    }
}
