<?php

declare(strict_types=1);

namespace Tests\Unit\Nutrition;

use App\Services\Ai\Providers\AnthropicProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Del documento parte il documento, e nient'altro — K1-ter.4.
 *
 * ══ 🚨 COSA DIFENDONO QUESTI TEST ═════════════════════════════════════════
 *
 * 📌 Quello che si promette a chi carica, nella schermata del consenso: *«se non
 * ci sono dati personali sul foglio, quello che parte e' un foglio di numeri e
 * basta: nessuno, dall'altra parte, ha modo di collegarlo a te»*.
 *
 * ⛔ E' una promessa che si rompe **in silenzio**. Basta che qualcuno aggiunga
 * un `metadata.user_id` alla richiesta — cosa che i fornitori invitano a fare,
 * per il rate limiting — e da quel momento ogni piano alimentare parte con un
 * identificatore stabile della persona sopra. Nessun errore, nessun test rosso,
 * e un'informativa che dice una cosa falsa.
 *
 * 💡 `AiCallContext` porta palestra e utente, ma **solo per la nostra
 * contabilita' dei gettoni**: da questa classe entra e finisce **soltanto** nel
 * registro. Questi test sono cio' che se ne accorge il giorno che smette di
 * essere vero.
 */
class NienteDiChiSiaTest extends TestCase
{
    /*
     * ⚠️ **`Tests\TestCase` e non `PHPUnit\Framework\TestCase`**, anche se qui
     * non si tocca il database: `blocchiDelDocumento()` legge
     * `config('ai.pdf.max_bytes')`, e senza l'applicazione avviata quella
     * chiamata non esiste.
     */

    /**
     * ⚠️ Le uniche forme ammesse per una riga che nomina `$ctx`.
     *
     * 🚨 Chiunque ne aggiunga una nuova deve **passare di qui** e spiegare
     * perche': e' esattamente il momento in cui si deciderebbe, per distrazione,
     * di mandare l'identita' a un fornitore.
     *
     * @return list<string>
     */
    private function formeAmmesse(): array
    {
        return [
            // La dichiarazione del parametro.
            'AiCallContext $ctx',

            // Il passaggio agli strati interni di questa stessa classe.
            '$ctx,',
            '$this->rawCall($ctx,',
            '$this->recordFailure($ctx,',
        ];
    }

    /**
     * 🚨 **`$ctx` non compare mai dentro il corpo di una richiesta.**
     *
     * ⛔ Il test guarda il sorgente e non il comportamento, e non e' pigrizia:
     * il comportamento sbagliato non si vede: una richiesta con un
     * `user_id` in piu' funziona benissimo, risponde uguale, e costa uguale.
     * L'unico posto dove il difetto e' visibile e' il testo.
     */
    #[Test]
    public function il_contesto_non_entra_mai_in_una_richiesta(): void
    {
        $file = (new ReflectionClass(AnthropicProvider::class))->getFileName();

        self::assertIsString($file);

        $righe = explode("\n", (string) file_get_contents($file));

        $fuoriPosto = [];

        foreach ($righe as $numero => $riga) {
            if (! str_contains($riga, '$ctx')) {
                continue;
            }

            foreach ($this->formeAmmesse() as $forma) {
                if (str_contains($riga, $forma)) {
                    continue 2;
                }
            }

            $fuoriPosto[] = ($numero + 1).': '.trim($riga);
        }

        self::assertSame(
            [],
            $fuoriPosto,
            "Una riga usa \$ctx in un modo nuovo. Se sta mettendo l'utente o la ".
            "palestra dentro il corpo di una richiesta, la promessa fatta nella ".
            'schermata del consenso non e\' piu\' vera: aggiornala prima, o non farlo.',
        );
    }

    /**
     * ⚠️ **Un blocco del documento porta il documento e basta.**
     *
     * 🚨 Il rischio non e' teorico: il nome del file e' il posto piu' naturale
     * del mondo dove infilare un'etichetta comoda («piano-anna-marzo.pdf»), e i
     * fornitori accettano un campo `title` proprio li'. 💡 Quel nome lo scrive
     * chi carica, e spessissimo contiene il suo.
     */
    #[Test]
    public function un_blocco_del_documento_porta_solo_i_byte(): void
    {
        $percorso = tempnam(sys_get_temp_dir(), 'doc').'.pdf';

        // ⚠️ Un PDF vero e non un file a caso: il tipo si legge dai byte.
        file_put_contents($percorso, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        try {
            $metodo = new ReflectionMethod(AnthropicProvider::class, 'blocchiDelDocumento');

            $blocchi = $metodo->invoke(
                (new ReflectionClass(AnthropicProvider::class))->newInstanceWithoutConstructor(),
                [$percorso],
            );

            self::assertCount(1, $blocchi);

            $blocco = $blocchi[0];

            // 🚨 **Esattamente** queste chiavi: `assertArrayHasKey` lascerebbe
            // passare una chiave in piu', che e' tutto il difetto.
            self::assertSame(['type', 'source'], array_keys($blocco));
            self::assertSame('document', $blocco['type']);
            self::assertSame(['type', 'media_type', 'data'], array_keys($blocco['source']));

            // ⛔ E il nome del file non c'e' da nessuna parte.
            self::assertStringNotContainsString(
                basename($percorso),
                json_encode($blocco, JSON_THROW_ON_ERROR),
            );
        } finally {
            @unlink($percorso);
        }
    }
}
