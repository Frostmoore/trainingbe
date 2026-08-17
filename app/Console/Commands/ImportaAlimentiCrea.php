<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Food;
use App\Services\Nutrition\Catalogo\AlimentoAmmissibile;
use App\Services\Nutrition\Catalogo\ChiaveAlimento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Importa le Tabelle di Composizione degli Alimenti del CREA — 17/08/2026.
 *
 * ── 🚨 Perche' si estrae dal sito invece di scaricare un file ──────────────
 *
 * Perche' **il file non esiste**. Verificato il 17/08: niente CSV, niente
 * Excel, niente API, e nessun mirror pubblico in formato leggibile. Le ~830
 * schede si consultano una pagina per volta, e per averle tutte non c'e' altra
 * strada che chiederle una per volta.
 *
 * ── La licenza, che e' il motivo per cui si puo' fare ──────────────────────
 *
 * Testuale da `alimentinutrizione.it`: *«Dati e testi […] non possono essere
 * copiati o in altro modo riprodotti […] senza una chiara indicazione della
 * fonte originale»*. 🚨 Cioe' **copiare si puo', citando**. L'attribuzione
 * finisce in `foods.note` riga per riga, non in un commento qui dentro: il
 * giorno che si mescolano due fonti nella stessa tabella, una nota nel codice
 * non dice piu' quale riga viene da dove.
 *
 * ── ⚠️ Si va piano, e non e' una gentilezza ────────────────────────────────
 *
 * E' un sito di un ente pubblico. Ottocento richieste consecutive senza pausa
 * sono indistinguibili da un piccolo attacco, e la prima reazione ragionevole
 * di chiunque amministri quel server e' bloccare l'indirizzo. La pausa
 * predefinita e' 700 ms: l'importazione dura una decina di minuti e si fa
 * **una volta sola**.
 *
 * 💡 Per questo il comando e' **ripetibile senza danno**: se si interrompe, si
 * rilancia e riprende: gli alimenti gia' presenti vengono aggiornati, non
 * duplicati.
 */
class ImportaAlimentiCrea extends Command
{
    protected $signature = 'alimenti:importa-crea
        {--pausa=700 : Millisecondi fra una richiesta e l\'altra}
        {--limite=0 : Quanti alimenti al massimo, 0 = tutti}
        {--riprendi : Salta gli alimenti gia\' in banca dati}';

    protected $description = 'Importa le Tabelle di Composizione degli Alimenti del CREA';

    private const INDICE = 'https://www.alimentinutrizione.it/tabelle-nutrizionali/ricerca-per-alimento';

    private const SCHEDA = 'https://www.alimentinutrizione.it/tabelle-nutrizionali/';

    /** 🚨 L'attribuzione richiesta dalla licenza. Va su ogni riga. */
    private const NOTA = 'Fonte: CREA Centro di ricerca Alimenti e Nutrizione — Tabelle di Composizione degli Alimenti (alimentinutrizione.it)';

    /**
     * ⚠️ Il nome del nutriente **come appare nella pagina**, non come lo
     * chiamiamo noi. «Carboidrati disponibili» e' il nome CREA: cercare
     * «Carboidrati» prenderebbe anche altre righe.
     */
    private const NUTRIENTI = [
        'kcal_100' => 'Energia (kcal)',
        'protein_100' => 'Proteine (g)',
        'fat_100' => 'Lipidi (g)',
        'carbs_100' => 'Carboidrati disponibili (g)',
    ];

    /**
     * 🚨 Non finiscono nel catalogo, ma servono a **verificarlo**.
     *
     * La fibra porta ~2 kcal/g e l'alcol 7: senza, il controllo di coerenza
     * scartava carciofi, crusca, lamponi e birra — cioe' alimenti veri, per un
     * difetto della formula e non del dato.
     */
    private const PER_IL_CONTROLLO = [
        'fibra_100' => 'Fibra totale (g)',
        'alcol_100' => 'Alcool (g)',
    ];

    public function handle(ChiaveAlimento $chiavi, AlimentoAmmissibile $filtro): int
    {
        $codici = $this->codici();

        if ($codici === []) {
            $this->error('Nessun codice trovato nell\'indice: il sito potrebbe essere cambiato.');

            return self::FAILURE;
        }

        $this->info(count($codici).' alimenti nell\'indice.');

        $limite = (int) $this->option('limite');
        if ($limite > 0) {
            $codici = array_slice($codici, 0, $limite);
        }

        $pausa = (int) $this->option('pausa') * 1000;
        $scritti = $saltati = $falliti = 0;
        $barra = $this->output->createProgressBar(count($codici));

        foreach ($codici as $codice) {
            $barra->advance();

            $chiaveFonte = 'CREA:'.$codice;

            if ($this->option('riprendi') && Food::query()->where('fonte', $chiaveFonte)->exists()) {
                $saltati++;

                continue;
            }

            $scheda = $this->scheda($codice);

            if ($scheda === null) {
                $falliti++;
                usleep($pausa);

                continue;
            }

            // ⚠️ Il filtro di coerenza vale **anche** per una fonte
            // autorevole: una pagina puo' avere un nutriente mancante, e una
            // riga con tre macro su quattro sporca il catalogo come le altre.
            $motivo = $filtro->motivoDelRifiuto($scheda['nome'], $scheda['per100'], $scheda['contesto']);

            if ($motivo !== null) {
                $this->line("  ↷ {$scheda['nome']}: {$motivo}", 'comment');
                $saltati++;
                usleep($pausa);

                continue;
            }

            Food::query()->updateOrCreate(
                ['chiave' => $chiavi->da($scheda['nome'])],
                [
                    'nome' => $scheda['nome'],
                    'marca' => null,
                    ...$scheda['per100'],
                    'basis' => 'g',
                    'origine' => Food::ORIGINE_MANUALE,
                    'fonte' => $chiaveFonte,
                    'note' => self::NOTA,
                    // 🚨 Pubblici da subito: non passano dalla soglia delle due
                    // conferme perche' quella soglia difende da nomi scritti a
                    // mano da una persona sola, e qui non c'e' nessuna persona.
                    'pubblico' => true,
                    'conferme' => Food::CONFERME_PER_PUBBLICARE,
                ],
            );

            $scritti++;
            usleep($pausa);
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Scritti: {$scritti} · saltati: {$saltati} · falliti: {$falliti}");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function codici(): array
    {
        $risposta = Http::timeout(60)->get(self::INDICE);

        if (! $risposta->successful()) {
            return [];
        }

        preg_match_all('~/tabelle-nutrizionali/(\d{6})~', $risposta->body(), $trovati);

        return array_values(array_unique($trovati[1]));
    }

    /**
     * Una scheda, oppure `null` se non si e' potuta leggere.
     *
     * @return array{nome: string, per100: array<string, float|null>, contesto: array<string, mixed>}|null
     */
    private function scheda(string $codice): ?array
    {
        $risposta = rescue(
            fn () => Http::timeout(30)->retry(2, 2000)->get(self::SCHEDA.$codice),
            null,
            false,
        );

        if ($risposta === null || ! $risposta->successful()) {
            return null;
        }

        $html = $risposta->body();

        // Il nome sta nel titolo: «AlimentiNUTrizione - Pane bianco».
        if (preg_match('~<title>\s*AlimentiNUTrizione\s*-\s*(.+?)\s*</title>~u', $html, $m) !== 1) {
            return null;
        }

        $nome = $this->ripulisci(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $celle = $this->nutrienti($html);

        /*
         * 🚨 **La cella vuota vale zero, e solo da una fonte dichiarata.**
         *
         * CREA lascia la cella vuota dove il valore e' zero o in tracce: i
         * grassi di un succo d'arancia, le proteine della grappa. ⚠️ Trattarle
         * come «manca il dato» scartava 55 alimenti su 832, quasi tutti cose
         * che si scrivono nel diario ogni giorno.
         *
         * 💡 Non e' un'assunzione a occhi chiusi: lo zero passa comunque dal
         * controllo di coerenza. Se le calorie dichiarate non tornano con i
         * macro **compreso quello zero**, l'alimento viene scartato lo stesso.
         * Lo zero e' un'ipotesi che i numeri devono confermare.
         */
        $per100 = [];
        foreach (self::NUTRIENTI as $campo => $etichetta) {
            $per100[$campo] = $celle[$etichetta] ?? ($etichetta === 'Energia (kcal)' ? null : 0.0);
        }

        $contesto = ['da_fonte' => true];
        foreach (self::PER_IL_CONTROLLO as $campo => $etichetta) {
            $contesto[$campo] = $celle[$etichetta] ?? 0.0;
        }

        return ['nome' => $nome, 'per100' => $per100, 'contesto' => $contesto];
    }

    /**
     * 🚨 **Un difetto della fonte, non nostro.**
     *
     * Alcuni nomi nell'archivio CREA arrivano cosi': `"|Caramelle tipo ""mou""|"`.
     * E' un residuo di **quotatura CSV** — delimitatore `|`, virgolette
     * raddoppiate — entrato nel loro database durante qualche importazione e
     * mai ripulito: si vede tale e quale anche sulla loro pagina.
     *
     * ⚠️ Non si puo' lasciarlo passare: quel nome finirebbe nei suggerimenti di
     * tutti. E la chiave normalizzata non basta — toglie la punteggiatura per
     * il **confronto**, ma il nome **mostrato** resterebbe illeggibile.
     *
     * 💡 Si ripulisce solo la forma riconoscibile: virgolette e barre agli
     * estremi, e le virgolette raddoppiate. Il resto non si tocca — un nome
     * legittimo puo' contenere virgolette in mezzo.
     */
    private function ripulisci(string $nome): string
    {
        $n = trim($nome);

        if (str_starts_with($n, '"') && str_ends_with($n, '"')) {
            $n = trim(mb_substr($n, 1, -1));
        }

        $n = trim($n, '|');
        $n = str_replace('""', '"', $n);

        return trim($n);
    }

    /**
     * Tutti i nutrienti della pagina, per etichetta.
     *
     * ── ⚠️ Perche' si isola la riga prima di leggere la colonna ────────────
     *
     * La prima versione cercava «l'etichetta, poi salta una cella, poi prendi
     * il numero» con una sola espressione su tutta la pagina. 🚨 Funziona
     * finche' **ogni** riga ha lo stesso numero di celle: il giorno che una
     * riga ne ha una in meno, la ricerca prosegue **dentro la riga successiva**
     * e restituisce il valore di un altro nutriente — senza fallire e senza
     * dirlo.
     *
     * 💡 Isolando prima il `<tr>`, l'errore peggiore possibile diventa «non
     * trovo il valore», che si vede.
     *
     * ⚠️ La terza cella e' il valore **per 100 g**; piu' avanti nella riga ce
     * n'e' un secondo, che e' il valore **per porzione**.
     *
     * @return array<string, float>
     */
    private function nutrienti(string $html): array
    {
        preg_match_all('~<tr class="corponutriente">(.*?)</tr>~us', $html, $righe);

        $valori = [];

        foreach ($righe[1] as $riga) {
            preg_match_all('~<td[^>]*>(.*?)</td>~us', $riga, $celle);

            if (count($celle[1]) < 3) {
                continue;
            }

            $etichetta = $this->testo($celle[1][0]);
            $valore = $this->testo($celle[1][2]);

            if ($etichetta === '' || preg_match('~^[\d.,]+$~', $valore) !== 1) {
                continue;
            }

            $valori[$etichetta] = (float) str_replace(',', '.', $valore);
        }

        return $valori;
    }

    private function testo(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $t));
    }
}
