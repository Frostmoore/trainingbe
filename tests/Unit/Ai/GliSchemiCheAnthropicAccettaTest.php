<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Prompts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gli schemi di uscita rispettano le regole di Anthropic — K7, 03/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE, E PERCHE' NON ESISTEVA PRIMA ═══════════════════════
 *
 * Uno schema sbagliato non si vede **da nessuna parte** nella suite: tutti i
 * test passano da `FakeAiProvider`, che uno schema non lo valida — restituisce
 * quello che gli si dice di restituire. ⛔ Il primo controllo vero e' la
 * chiamata al fornitore, cioe' la produzione.
 *
 * 🚨 **E il fornitore risponde 400**, che il nostro codice traduce in
 * `ai_unavailable`: a chi guarda arriva *«l'AI non e' disponibile»*. Sembra un
 * guasto loro ed e' una richiesta malformata nostra, quindi non si aggiusta da
 * sola e nessuno sa da dove cominciare.
 *
 * ══ ⛔ E' GIA' SUCCESSO DUE VOLTE ═════════════════════════════════════════
 *
 * La prima con `minimum`/`maximum`, che ha tenuto ferme **tutte** le funzioni
 * AI. La regola e' stata scritta in testa a `Prompts` — e il 03/09/2026 la
 * prova su un documento vero (K7) ha trovato che `pianoAlimentareSchema()` la
 * violava **lo stesso**, piu' un secondo difetto (`additionalProperties`).
 *
 * 💡 Cioe': l'importazione dei piani alimentari **non ha mai funzionato** dal
 * 19/08 al 03/09, e nessun test se n'era accorto.
 *
 * ⚠️ **Una regola scritta in un commento non diventa mai rossa.** Questo file
 * e' la stessa regola, scritta in modo che si rompa.
 */
class GliSchemiCheAnthropicAccettaTest extends TestCase
{
    /**
     * Ogni schema che mandiamo come `output_config.format.schema`.
     *
     * 🚨 **Chi ne aggiunge uno lo aggiunge qui.** Un elenco scritto a mano e'
     * il posto dove ci si dimentica: e' il prezzo da pagare perche' gli schemi
     * siano metodi statici e non oggetti registrati da qualche parte.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function schemi(): array
    {
        return [
            'cibo' => [Prompts::foodSchema()],
            'piano alimentare' => [Prompts::pianoAlimentareSchema()],
            'scheda' => [Prompts::workoutPlanSchema()],
            'progresso' => [Prompts::progressoSchema()],
        ];
    }

    /**
     * ⛔ *«For 'number' type, properties maximum, minimum are not supported»*.
     *
     * 💡 Il vincolo di intervallo va scritto **nel prompt**, dove il modello se
     * lo aspetta: ogni `confidence` ha la sua regola «da 0 a 1».
     *
     * @param  array<string, mixed>  $schema
     */
    #[Test]
    #[DataProvider('schemi')]
    public function nessuno_schema_usa_minimum_o_maximum(array $schema): void
    {
        $trovati = $this->cerca($schema, ['minimum', 'maximum'], '');

        self::assertSame(
            [],
            $trovati,
            "Anthropic risponde 400 e il messaggio che arriva a chi guarda e' ".
            "«l'AI non e' disponibile». Il vincolo va scritto nel prompt.",
        );
    }

    /**
     * ⛔ *«For 'object' type, 'additionalProperties' must be explicitly set to
     * false»*.
     *
     * ⚠️ **Esplicitamente**, non per assenza: uno schema senza quella chiave e'
     * rifiutato tale e quale a uno che la mette a `true`.
     *
     * @param  array<string, mixed>  $schema
     */
    #[Test]
    #[DataProvider('schemi')]
    public function ogni_oggetto_chiude_le_proprieta_in_piu(array $schema): void
    {
        $aperti = $this->oggettiSenzaChiusura($schema, '');

        self::assertSame(
            [],
            $aperti,
            "Ogni `'type' => 'object'` vuole `'additionalProperties' => false`, ".
            'esplicitamente.',
        );
    }

    /**
     * ⚠️ **Ogni proprieta' di un oggetto sta in `required`.**
     *
     * 💡 Il modo di dire «puo' mancare» e' il **tipo** `null`, non l'assenza da
     * `required`: uno schema che lascia un campo facoltativo produce un campo
     * che il modello omette quasi sempre — ed e' esattamente cio' che
     * renderebbe inutili meta' dei campi che chiediamo.
     *
     * @param  array<string, mixed>  $schema
     */
    #[Test]
    #[DataProvider('schemi')]
    public function ogni_proprieta_e_richiesta(array $schema): void
    {
        $mancanti = $this->proprietaFuoriDaRequired($schema, '');

        self::assertSame([], $mancanti, 'Per dire «puo\' mancare» si usa il tipo `null`.');
    }

    // ───────────────────────── i setacci ─────────────────────────

    /**
     * @param  array<string, mixed>  $nodo
     * @param  list<string>  $vietate
     * @return list<string>
     */
    private function cerca(array $nodo, array $vietate, string $dove): array
    {
        $trovati = [];

        foreach ($nodo as $chiave => $valore) {
            $percorso = $dove === '' ? (string) $chiave : $dove.'.'.$chiave;

            if (in_array($chiave, $vietate, true)) {
                $trovati[] = $percorso;
            }

            if (is_array($valore)) {
                $trovati = [...$trovati, ...$this->cerca($valore, $vietate, $percorso)];
            }
        }

        return $trovati;
    }

    /**
     * @param  array<string, mixed>  $nodo
     * @return list<string>
     */
    private function oggettiSenzaChiusura(array $nodo, string $dove): array
    {
        $aperti = [];

        if (($nodo['type'] ?? null) === 'object' && ($nodo['additionalProperties'] ?? null) !== false) {
            $aperti[] = $dove === '' ? '(radice)' : $dove;
        }

        foreach ($nodo as $chiave => $valore) {
            if (is_array($valore)) {
                $percorso = $dove === '' ? (string) $chiave : $dove.'.'.$chiave;

                $aperti = [...$aperti, ...$this->oggettiSenzaChiusura($valore, $percorso)];
            }
        }

        return $aperti;
    }

    /**
     * @param  array<string, mixed>  $nodo
     * @return list<string>
     */
    private function proprietaFuoriDaRequired(array $nodo, string $dove): array
    {
        $mancanti = [];

        if (($nodo['type'] ?? null) === 'object' && isset($nodo['properties'])) {
            $richieste = (array) ($nodo['required'] ?? []);

            foreach (array_keys((array) $nodo['properties']) as $proprieta) {
                if (! in_array($proprieta, $richieste, true)) {
                    $mancanti[] = ($dove === '' ? '' : $dove.'.').$proprieta;
                }
            }
        }

        foreach ($nodo as $chiave => $valore) {
            if (is_array($valore)) {
                $percorso = $dove === '' ? (string) $chiave : $dove.'.'.$chiave;

                $mancanti = [...$mancanti, ...$this->proprietaFuoriDaRequired($valore, $percorso)];
            }
        }

        return $mancanti;
    }
}
