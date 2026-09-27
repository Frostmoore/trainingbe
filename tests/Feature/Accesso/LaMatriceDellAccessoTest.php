<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Support\Debug\CasiDiProva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🧮 **La matrice dell'accesso, automatica** — 63.A, 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI
 * ACCEDERE»*, dopo che l'accesso si era rotto **tre volte in due giorni**.
 *
 * 🔑 **Costruisce tutti e venticinque i casi con lo stesso codice della vista di
 * debug** (`CasiDiProva`), e su ognuno verifica l'invariante. ⚠️ Non è
 * un'ottimizzazione: *una regola che serve in due posti non appartiene a nessuno
 * dei due*. ⛔ Se la matrice e la vista costruissero gli scenari in due modi
 * diversi, il committente proverebbe a mano una cosa e i test un'altra — ed è
 * esattamente il disallineamento che ha fatto perdere la fiducia.
 *
 * ══ ⚖️ COSA VERIFICA, E COSA NO ════════════════════════════════════════════
 *
 * | Dimensione | Qui |
 * |---|---|
 * | **si entra sempre** | ✅ per tutti e venticinque |
 * | l'app lo vede abbonato? | ✅ per i quindici dell'app |
 * | il pannello si apre? | ✅ per i dieci del pannello |
 * | *cosa si legge* nella schermata | ⛔ **no**: quello lo guarda il committente (63.F) |
 *
 * 💡 L'ultima riga è il punto: una macchina può dire *«la porta si apre»*, non
 * *«il messaggio si capisce»*. Per quello serve la vista di debug, e un paio
 * d'occhi.
 *
 * ══ 🚨 UNA COSA CHE QUESTA MATRICE **NON** PROVA, SCOPERTA FALSIFICANDOLA ══
 *
 * ⛔ Togliendo il filtro sullo **stato dello spazio** in `PianoAttivo::per()`
 * questi test **restano verdi**. Non è un difetto dei test: è che nei venticinque
 * scenari *«non paga»* significa `status = suspended` **e** abbonamento scaduto —
 * lo stato vero di un moroso — e con l'abbonamento già scaduto quel filtro non ha
 * niente da filtrare.
 *
 * 🔑 Il filtro conta in **un caso solo**: spazio sospeso **con** un abbonamento
 * ancora vivo. ⚠️ Cioè una sospensione fatta **a mano** da `/god` — per un abuso —
 * oppure la finestra di un giorno prima del giro notturno.
 *
 * 💡 Quel caso lo prova `UnoSpazioSospesoNonPagaPerNessunoTest`, dove la
 * falsificazione **morde** (cadono 3 casi su 5). ⛔ Sta scritto qui perché chi legge
 * questa matrice non concluda che copra anche quello: **una falsificazione che non
 * fa cadere niente è una notizia, non un successo.**
 */
class LaMatriceDellAccessoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🚨 **L'INVARIANTE: tutti e venticinque entrano.**
     *
     * ⛔ È l'unico test del progetto che, se diventa rosso, va guardato **prima**
     * di qualunque altra cosa: vuol dire che qualcuno non può più pagare.
     *
     * ⚠️ Si prova con l'**accesso vero** (`POST /api/v1/auth/login`), non
     * costruendo una sessione a mano: la porta si prova chiedendo di entrare.
     */
    #[Test]
    public function tutti_e_venticinque_riescono_ad_accedere(): void
    {
        $casi = app(CasiDiProva::class);

        foreach ($casi->elenco() as $caso) {
            $casi->costruisci($caso['chiave']);

            $risposta = $this->postJson('/api/v1/auth/login', [
                'email' => $caso['email'],
                'password' => CasiDiProva::PASSWORD,
            ]);

            self::assertTrue(
                $risposta->isSuccessful(),
                "🚨 Il caso {$caso['chiave']} ({$caso['titolo']}) NON riesce ad accedere. "
                .'Codice: '.$risposta->getStatusCode().'. '
                .'⛔ «Se uno non può entrare, non mi può pagare».',
            );
        }
    }

    /**
     * 📱 **I quindici casi dell'app: l'abbonamento dice il vero.**
     *
     * 🔑 È la dimensione che decide **quale interfaccia** si vede, ed è l'unica
     * verificabile a macchina. ⚠️ Il caso che conta più di tutti è **A13** —
     * palestra sospesa **ma** un trainer che paga — perché è quello in cui un
     * allievo verrebbe punito per un debito che non è né suo né del suo trainer.
     */
    #[Test]
    public function sui_quindici_casi_dellapp_labbonamento_dice_il_vero(): void
    {
        $casi = app(CasiDiProva::class);
        $piano = app(PianoAttivo::class);

        foreach ($casi->elenco() as $caso) {
            if ($caso['dove'] !== 'app') {
                continue;
            }

            $casi->costruisci($caso['chiave']);

            $utente = $casi->utente($caso['chiave']);

            self::assertInstanceOf(User::class, $utente, "Caso {$caso['chiave']} non costruito.");

            self::assertSame(
                $caso['abbonato'],
                $piano->eAbbonato($utente),
                "🚨 Il caso {$caso['chiave']} ({$caso['titolo']}) mostrerebbe l'interfaccia sbagliata. "
                .'⛔ Se l\'app promette e il server rifiuta, sembra un guasto e il cliente telefona '
                .'invece di pagare.',
            );
        }
    }

    /**
     * 🖥️ **I dieci casi del pannello: la porta si apre.**
     *
     * ⚠️ Con una **richiesta HTTP** al pannello, non con `canAccessPanel()`: è la
     * distinzione che il 27/09 ha tenuto nascosti quattro difetti dietro dieci
     * test verdi.
     */
    #[Test]
    public function sui_dieci_casi_del_pannello_la_porta_si_apre(): void
    {
        $casi = app(CasiDiProva::class);

        foreach ($casi->elenco() as $caso) {
            if ($caso['dove'] !== 'pannello') {
                continue;
            }

            $casi->costruisci($caso['chiave']);

            $utente = $casi->utente($caso['chiave']);
            self::assertInstanceOf(User::class, $utente);

            $this->flushSession();

            $risposta = $this->actingAs($utente->fresh() ?? $utente)->get('/admin');

            self::assertNotSame(
                403,
                $risposta->getStatusCode(),
                "🚨 Il caso {$caso['chiave']} ({$caso['titolo']}) riceve 403 dal pannello. "
                .'⛔ Il pannello è il posto da cui si paga.',
            );

            self::assertTrue(
                $risposta->isSuccessful() || $risposta->isRedirect(),
                "🚨 Il caso {$caso['chiave']} riceve ".$risposta->getStatusCode().' dal pannello.',
            );
        }
    }

    /**
     * 🔒 **E la gemella: nessuno dei venticinque entra in `/god`.**
     *
     * ⚠️ Le due regole tirano in direzioni opposte, ed è lì che nascono i
     * difetti: aprire una porta è il momento in cui si apre anche quella accanto.
     * ⛔ Questo test cade se la correzione dell'accesso è stata fatta allargando
     * troppo.
     */
    #[Test]
    public function e_nessuno_dei_venticinque_entra_nel_pannello_god(): void
    {
        $casi = app(CasiDiProva::class);

        foreach ($casi->elenco() as $caso) {
            $casi->costruisci($caso['chiave']);

            $utente = $casi->utente($caso['chiave']);
            self::assertInstanceOf(User::class, $utente);

            $this->flushSession();

            $risposta = $this->actingAs($utente->fresh() ?? $utente)->get('/god');

            self::assertNotSame(
                200,
                $risposta->getStatusCode(),
                "🚨 Il caso {$caso['chiave']} ({$caso['titolo']}) ha aperto il pannello di piattaforma.",
            );
        }
    }
}
