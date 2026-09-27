<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Services\Chat\CancelloDellaChat;
use App\Support\Sicurezza\SessionePerContoTerzi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔒 **Non si entra nei panni di nessuno** — voce 64, 27/09/2026.
 *
 * ══ 🔄 QUESTO FILE SOSTITUISCE `ImpersonationTest`, CHE AVEVA 15 CASI ══════
 *
 * ⛔ Quel file provava che l'impersonazione **funzionasse**: che la sessione
 * sopravvivesse alla richiesta successiva, che l'audit registrasse, che la fascia
 * rossa comparisse. 🚨 Era un test corretto di una funzione che non doveva
 * esistere.
 *
 * 📌 Il committente, 27/09: *«NIENTE IMPERSONATOR, l'avevamo tolto»* … *«va
 * assolutamente rimosso l'impersonator, è un data breach costruito apposta per
 * farmi mandare in galera»*.
 *
 * 🔑 **E la decisione era già stata presa a voce, settimane prima, senza essere
 * scritta**: il codice è rimasto vivo in produzione mentre i documenti lo
 * descrivevano come una funzione voluta. ⚠️ È per questo che adesso sta in
 * `decisioni.md`, in `divieti.md`, e qui.
 *
 * ══ ⛔ COSA PROVA QUESTO FILE ══════════════════════════════════════════════
 *
 * Non che «l'impersonazione sia disattivata»: che **non esista**. 💡 La differenza
 * conta, perché una funzione disattivata è una funzione che si riaccende.
 */
class NonSiEntraNeiPanniDiNessunoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **La classe che permetteva di entrare non esiste più.**
     *
     * ⚠️ Si guarda il **nome della classe**, non un comportamento: ⛔ un test sul
     * comportamento passerebbe anche se la classe tornasse con un altro
     * interruttore.
     */
    #[Test]
    public function la_classe_che_impersonava_non_esiste_piu(): void
    {
        self::assertFalse(
            class_exists('App\Support\Impersonation\Impersonator'),
            '🚨 `Impersonator` è tornato. ⛔ `divieti.md` del 27/09/2026 lo vieta senza scadenza: '
            .'un accesso nei panni di un cliente è indistinguibile, nei log, da un accesso di quel cliente.',
        );
    }

    /**
     * 🚪 **E nessuna rotta la serve.**
     *
     * 💡 Si guardano **tutte** le rotte registrate, non un percorso noto: ⛔ un
     * test su `/impersonation/stop` non vedrebbe una rotta rimessa con un altro
     * nome, ed è proprio così che una funzione torna.
     */
    #[Test]
    public function nessuna_rotta_parla_di_impersonazione(): void
    {
        foreach (Route::getRoutes() as $rotta) {
            $uri = (string) $rotta->uri();
            $nome = (string) $rotta->getName();

            self::assertStringNotContainsStringIgnoringCase(
                'imperson',
                $uri,
                "🚨 Esiste una rotta che parla di impersonazione: {$uri}",
            );

            self::assertStringNotContainsStringIgnoringCase('imperson', $nome);
        }
    }

    /**
     * 🔒 **E la guardia delle chat resta, e risponde sempre «no».**
     *
     * ══ 🚨 PERCHE' LA GUARDIA NON E' STATA CANCELLATA ═════════════════════
     *
     * ⛔ Cancellarla sarebbe stata la scorciatoia sbagliata. Se un giorno una
     * funzione «entra come» tornasse — scritta da qualcun altro, chiamata in un
     * altro modo — **la riservatezza delle chat sarebbe già caduta**, e nessun
     * test lo direbbe.
     *
     * ⚠️ E il difetto si manifesterebbe il giorno in cui qualcuno legge i messaggi
     * fra un iscritto e il suo trainer: infortuni, disturbi alimentari, foto.
     */
    #[Test]
    public function la_guardia_delle_chat_resta_e_dice_sempre_no(): void
    {
        self::assertFalse(
            app(SessionePerContoTerzi::class)->inCorso(),
            '⛔ La guardia dice che questa sessione agisce per conto di qualcun altro.',
        );

        /* 🔑 E chi la interroga la interroga davvero: la dipendenza è dichiarata. */
        self::assertTrue(
            class_exists(CancelloDellaChat::class),
            'Il cancello della chat deve esistere: è lui che chiede.',
        );
    }

    /**
     * 🔌 **E la guardia è AGGANCIATA, non orfana.**
     *
     * ══ 🚨 PERCHE' QUESTO TEST SOSTITUISCE QUELLO CHE AVEVO SCRITTO PRIMA ══
     *
     * ⛔ Il primo giro provava che il super admin ricevesse **un rifiuto** da
     * `GET /api/v1/conversations`. 🚨 Ma riceve **200 con la lista vuota**, ed è
     * **giusto**: dal 27/09 ha uno spazio personale suo, e quella rotta gli
     * restituisce le **sue** conversazioni, che sono zero.
     *
     * ⚠️ Cioè stavo scrivendo un test che accusava il sistema di un difetto
     * inesistente — e se l'avessi «fatto passare» cambiando il codice, avrei rotto
     * qualcosa per far quadrare una mia idea sbagliata.
     *
     * ── 🔑 Quello che invece va provato ────────────────────────────────────
     *
     * Il rischio vero, dopo aver tolto una classe e rinominato il suo sostituto, è
     * una guardia **orfana**: un controllo che nessuno chiama più. ⛔ È la trappola
     * già pagata con `RunnerDebug.entitlements` — un file che c'era e che nessuno
     * leggeva. 💡 Qui si verifica che la dipendenza sia **dichiarata**, e che la
     * policy la nomini.
     */
    #[Test]
    public function la_guardia_e_agganciata_e_non_orfana(): void
    {
        $costruttore = (new \ReflectionClass(CancelloDellaChat::class))->getConstructor();

        self::assertNotNull($costruttore);

        $tipi = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $costruttore->getParameters(),
        );

        self::assertContains(
            SessionePerContoTerzi::class,
            $tipi,
            '🚨 `CancelloDellaChat` non chiede più la guardia: è diventata orfana. '
            .'⛔ Il giorno in cui una funzione «entra come» tornasse, la riservatezza '
            .'delle chat sarebbe già caduta e nessun test lo direbbe.',
        );

        $policy = (string) file_get_contents(app_path('Policies/ConversationPolicy.php'));

        self::assertStringContainsString(
            'SessionePerContoTerzi',
            $policy,
            '🚨 `ConversationPolicy` non nomina più la guardia.',
        );
    }
}
