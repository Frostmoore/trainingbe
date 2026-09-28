<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 📄 **I testi legali esistono in due copie, e devono dire la stessa cosa** —
 * voce 62.8, 28/09/2026.
 *
 * ══ 🚨 IL DIFETTO CHE QUESTO TEST CHIUDE ══════════════════════════════════
 *
 * I testi legali stanno in **due posti**:
 *
 * | Dove | Chi la legge |
 * |---|---|
 * | `trainingbe/resources/legal/` | 🌍 **le persone** — la serve `SitoController::documento()` |
 * | `legale/` nel repo dei documenti | 👤 **noi**, quando ragioniamo |
 *
 * ⛔ **E il 28/09 erano divergite.** L'informativa servita aveva 41 righe che il
 * documentale non aveva (una sezione intera sul nome utente), e il documentale ne
 * aveva 4 che la servita non aveva. Le condizioni d'uso, lo stesso in piccolo.
 *
 * 🚨 **Perché è grave, e non è una questione di ordine**: una modifica fatta al
 * posto sbagliato **non arriva a nessuno**, e una promessa scritta solo nel
 * documentale **non è stata fatta**. ⚠️ Il rischio è invisibile: tutti e due i
 * file si aprono, tutti e due sembrano l'informativa.
 *
 * ══ 💡 E UNA COSA CHE SI E' VISTA SOLO CONFRONTANDOLE ═════════════════════
 *
 * 🔎 Il confronto ha trovato che l'informativa **pubblicata** diceva *«Registro di
 * controllo (accessi, impersonazioni)»* — e l'impersonazione era stata **togliata
 * dal prodotto il 27/09** (voce 64). ⛔ Un documento pubblico dichiarava un
 * trattamento che non esisteva più, e nessuno se ne era accorto.
 *
 * ⚖️ Non l'ha trovato una rilettura: l'ha trovato una **macchina che confronta**.
 * È il motivo per cui questo test esiste.
 */
class ITestiLegaliSonoUnoSoloTest extends TestCase
{
    /**
     * 📑 I quattro documenti serviti dalla rotta pubblica.
     *
     * ⚠️ **L'elenco è quello di `SitoController::documento()`**, e va tenuto
     * uguale: ⛔ un documento nuovo servito e non elencato qui sfuggirebbe al
     * confronto — che è esattamente il modo in cui le due copie sono divergite la
     * prima volta.
     *
     * @return list<string>
     */
    private function documenti(): array
    {
        return [
            'informativa-privacy',
            'condizioni-uso',
            'liberatoria-palestra',
            'liberatoria-trainer',
        ];
    }

    private function servita(string $quale): string
    {
        return resource_path("legal/{$quale}.md");
    }

    /**
     * 🔎 La copia del repo dei documenti, che sta **fuori** da questo repo.
     *
     * ⚠️ Se non c'è — perché qualcuno ha clonato solo il backend — il test si
     * **salta con un motivo**, invece di fallire accusando il codice di un
     * difetto che non ha. 💡 È la stessa scelta del confronto fra i due cataloghi
     * delle notifiche.
     */
    private function documentale(string $quale): ?string
    {
        foreach ([base_path("../legale/{$quale}.md"), base_path("legale/{$quale}.md")] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * 🔑 **Le due copie devono essere identiche, byte per byte.**
     *
     * ⚖️ Non «simili» né «allineate nei contenuti»: **identiche**. 💡 Un confronto
     * morbido — a paragrafi, o ignorando gli spazi — lascerebbe passare proprio le
     * differenze che contano, perché una parola cambiata in un testo legale è una
     * differenza piccola da guardare e grande da subire.
     */
    #[Test]
    public function le_due_copie_di_ogni_testo_legale_sono_identiche(): void
    {
        $diversi = [];
        $confrontati = 0;

        foreach ($this->documenti() as $quale) {
            $altrove = $this->documentale($quale);

            if ($altrove === null) {
                continue;
            }

            $confrontati++;

            /*
             * ⚠️ Si normalizzano **solo le fine-riga**, e per una ragione
             * precisa: Git su Windows può riscriverle al checkout, e un test che
             * fallisse per quello direbbe «i testi legali divergono» quando non è
             * vero. 🚨 Tutto il resto — spazi, maiuscole, accenti — conta.
             */
            $a = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($this->servita($quale)));
            $b = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($altrove));

            if ($a !== $b) {
                $diversi[] = sprintf(
                    '%s (servita: %d righe, documentale: %d)',
                    $quale,
                    substr_count($a, "\n"),
                    substr_count($b, "\n"),
                );
            }
        }

        if ($confrontati === 0) {
            self::markTestSkipped(
                'Il repo dei documenti non è accanto a questo: il confronto non si può fare. '
                .'Non è un difetto del backend.'
            );
        }

        self::assertSame(
            [],
            $diversi,
            "🚨 Le due copie di questi testi legali dicono cose diverse:\n  "
            .implode("\n  ", $diversi)."\n"
            .'⛔ Quella servita da `resources/legal/` è quella che leggono le persone; '
            ."l'altra è quella che rileggiamo noi. 💡 Il committente, 28/09: «la più recente, "
            .'ovviamente» — ma prima di ricopiare, guardare: il 28/09 su una riga era la '
            .'**servita** a essere sbagliata.',
        );
    }

    /**
     * 🚨 **Nessun testo legale parla ancora di impersonazione.**
     *
     * ⛔ L'impersonazione è stata togliata dal prodotto il 27/09 (voce 64): non
     * esiste più nessun modo di entrare nei panni di un altro. ⚠️ Un documento
     * pubblico che la nomina dichiara un trattamento **che non avviene**, ed è il
     * genere di errore che nessuno cerca.
     *
     * 💡 Trovato proprio così il 28/09, confrontando le copie: l'informativa
     * pubblicata diceva *«Registro di controllo (accessi, impersonazioni)»*.
     */
    #[Test]
    public function nessun_testo_legale_pubblico_parla_di_impersonazione(): void
    {
        $colpevoli = [];

        foreach ($this->documenti() as $quale) {
            $testo = mb_strtolower((string) file_get_contents($this->servita($quale)));

            if (str_contains($testo, 'impersona')) {
                $colpevoli[] = $quale;
            }
        }

        self::assertSame(
            [],
            $colpevoli,
            '🚨 Un testo legale pubblico parla di impersonazione: '.implode(', ', $colpevoli)."\n"
            .'⛔ Ma non esiste più dal 27/09 (voce 64). Un documento che dichiara un trattamento '
            .'che non avviene è peggio di uno incompleto.',
        );
    }
}
