<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Support\Debug\CasiDiProva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🔒 **La vista dei 25 casi non esiste fuori da locale** — 63.H.4, 27/09/2026.
 *
 * ══ 🚨 PERCHE' QUESTO FILE E' IL PIU' IMPORTANTE DI TUTTA LA 63.H ══════════
 *
 * `/debug/casi` è un elenco di collegamenti che **entrano in un account con un
 * clic**. ⛔ Su un server sarebbe la porta peggiore che questo progetto abbia mai
 * avuto — peggiore dell'impersonazione, perché non chiede nemmeno di essere
 * autenticati.
 *
 * 🔑 Le guardie sono **tre e indipendenti**:
 *
 * | # | Dove | Cosa protegge |
 * |---|---|---|
 * | 1 | `app()->environment('local')` in `routes/web.php` | la rotta |
 * | 2 | `config('casi_di_prova.attiva')` | la rotta, di nuovo |
 * | 3 | `CasiDiProva::__construct()` | **la classe**, da qualunque altra strada |
 *
 * ⚠️ **Sembrano tre copie e non lo sono.** Le prime due proteggono la rotta; la
 * terza protegge la classe da un comando, da un test, da un pezzo di codice
 * scritto fra un anno che la istanzia senza sapere cosa fa.
 *
 * ── ⛔ Perché in `testing` la classe si costruisce ──────────────────────────
 *
 * 🔑 Perché la matrice automatica (63.A) usa **lo stesso** costruttore di
 * scenari della vista: *una regola che serve in due posti non appartiene a
 * nessuno dei due*. ⚠️ Ma la **rotta** pretende `local`, quindi in `testing` la
 * porta non esiste comunque — ed è quello che questi test verificano.
 */
class LaVistaDeiCasiEsisteSoloInLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🚨 **La prova principale.** I test girano in `testing`, non in `local`:
     * quindi qui le rotte **non devono esistere**.
     *
     * ⚠️ Si guarda `404`, non `403`: un 403 vorrebbe dire che la rotta **c'è** e
     * qualcosa l'ha negata. ⛔ La differenza conta: una rotta che esiste è una
     * rotta che un giorno qualcuno sblocca per sbaglio.
     */
    #[Test]
    public function fuori_da_locale_le_rotte_non_esistono(): void
    {
        foreach (['/debug/casi', '/debug/casi/entra/P1'] as $percorso) {
            $this->get($percorso)->assertNotFound();
        }

        $this->post('/debug/casi/ricostruisci')->assertNotFound();
    }

    /**
     * 🚨 **La tabella di verità delle due guardie, interrogata davvero.**
     *
     * ══ ⛔ IL TEST CHE C'ERA PRIMA ERA VACUO ═══════════════════════════════
     *
     * Si chiamava `nemmeno_accendendo_linterruttore_la_rotta_compare`: metteva
     * `config(['casi_di_prova.attiva' => true])` e poi chiedeva la pagina,
     * aspettandosi un 404. ⛔ **Passava qualunque cosa facessero le guardie**,
     * perché le rotte si registrano **all'avvio**: cambiare la configurazione
     * dentro un test non ne fa comparire nessuna.
     *
     * 💡 Un test che si legge come una garanzia e non lo è — esattamente il
     * genere di cosa che ha fatto perdere la fiducia il 27/09. Trovato provando
     * a falsificarlo.
     *
     * 🔑 Ora si interroga `CasiDiProva::vistaAttiva()`, che **è** la condizione
     * che il file delle rotte valuta. ⚠️ Con l'ambiente `testing` deve dire `no`
     * in tutti e due i casi: sia con l'interruttore spento, sia **acceso**. ⛔ Se
     * la seconda riga cadesse, una riga nel `.env` di un server basterebbe ad
     * aprire la porta.
     */
    #[Test]
    public function le_due_guardie_dicono_no_anche_con_linterruttore_acceso(): void
    {
        config(['casi_di_prova.attiva' => false]);
        self::assertFalse(CasiDiProva::vistaAttiva());

        config(['casi_di_prova.attiva' => true]);
        self::assertFalse(
            CasiDiProva::vistaAttiva(),
            '🚨 Con l\'interruttore acceso la vista si apre anche fuori da locale: '
            .'una riga nel .env di un server basterebbe ad aprire un elenco di link '
            .'che entrano in un account con un clic.',
        );
    }

    /**
     * 🔒 **E il dominio è l'ultima guardia.**
     *
     * ⚠️ Non si prova passando dalla rotta — in `testing` non c'è — ma sulla
     * **classe**: `utente()` cerca solo indirizzi che finiscono col dominio dei
     * casi, quindi un nome inventato non trova mai un account vero.
     *
     * 💡 È la riga che impedisce a `/debug/casi/entra/<qualcosa>` di diventare un
     * modo per entrare in un account qualsiasi — e in locale un database vero ci
     * finisce spesso, ripristinato da una copia di sicurezza.
     */
    #[Test]
    public function la_classe_cerca_solo_gli_account_col_suo_dominio(): void
    {
        $casi = app(CasiDiProva::class);

        self::assertStringEndsWith(
            '@'.CasiDiProva::DOMINIO,
            $casi->email('P1'),
            '⛔ Gli indirizzi dei casi non sono riconoscibili: la guardia sull\'ingresso non può funzionare.',
        );

        self::assertNull(
            $casi->utente('ZZZZ'),
            '⛔ Ha trovato un utente per una chiave inventata.',
        );
    }

    /**
     * 📋 **E l'elenco è completo: venticinque, con i verdetti.**
     *
     * 🔑 I verdetti sono **la metà che conta**: per venticinque casi il problema
     * non è entrare, è sapere se quello che si vede è giusto. ⛔ Un caso senza
     * verdetti sposterebbe la fiducia dai test alla mia memoria.
     */
    #[Test]
    public function i_casi_sono_venticinque_e_ognuno_dice_cosa_aspettarsi(): void
    {
        $casi = app(CasiDiProva::class)->elenco();

        self::assertCount(25, $casi);

        $pannello = array_filter($casi, fn (array $c): bool => $c['dove'] === 'pannello');
        $app = array_filter($casi, fn (array $c): bool => $c['dove'] === 'app');

        self::assertCount(10, $pannello, '⛔ I casi del pannello devono essere dieci.');
        self::assertCount(15, $app, '⛔ I casi dell\'app devono essere quindici.');

        foreach ($casi as $caso) {
            self::assertNotEmpty(
                $caso['deve'],
                "🚨 Il caso {$caso['chiave']} non dice cosa si deve vedere: è un link senza verdetto.",
            );
        }
    }
}
