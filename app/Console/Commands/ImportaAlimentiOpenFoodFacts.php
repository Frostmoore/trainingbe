<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Food;
use App\Services\Nutrition\Catalogo\AlimentoAmmissibile;
use App\Services\Nutrition\Catalogo\ChiaveAlimento;
use Illuminate\Console\Command;

/**
 * Importa i prodotti confezionati da Open Food Facts — 17/08/2026.
 *
 * ── 💡 Perche' due fonti e non una ─────────────────────────────────────────
 *
 * Sono **complementari**, non alternative. CREA copre gli alimenti generici —
 * «petto di pollo», «pane bianco» — con dati sperimentali di un ente pubblico.
 * Open Food Facts copre i **prodotti di marca**, quelli con il codice a barre,
 * che sono la meta' della spesa di chiunque e che CREA non ha per definizione.
 *
 * ── 🚨 Le due licenze, che sono DIVERSE ────────────────────────────────────
 *
 * | | |
 * |---|---|
 * | I **dati** | `ODbL 1.0` + `Database Contents License` |
 * | Le **immagini** | `CC BY-SA 3.0` |
 *
 * ⚠️ **L'ODbL ha una clausola di condivisione allo stesso modo**: chi usa
 * pubblicamente un database derivato deve renderlo disponibile con la stessa
 * licenza. Le righe importate qui restano riconoscibili da `fonte` e `note`
 * proprio per questo — il giorno in cui va assolto quell'obbligo, si sa
 * **esattamente** quali righe ne fanno parte, senza doverle indovinare.
 *
 * 🚨 Le immagini **non si copiano**: si conserva l'indirizzo. Ricopiarle sul
 * nostro server sarebbe ridistribuzione, con gli obblighi di `CC BY-SA` a
 * carico nostro; puntarle le lascia dove stanno, con il loro autore.
 *
 * ── ⚠️ Il file e' enorme, quindi si legge a flusso ─────────────────────────
 *
 * L'esportazione completa e' ~0,9 GB compressi e ~9 GB in chiaro: non entra in
 * memoria e non si decomprime su disco senza pensarci. Qui si legge **riga per
 * riga** dal `.gz`, si scarta subito quello che non serve, e in memoria non
 * resta mai piu' di una riga per volta.
 *
 * 💡 Si filtra per paese: l'esportazione e' mondiale, e importare i cereali per
 * la colazione di tutto il mondo riempirebbe i suggerimenti di roba che in
 * Italia nessuno compra.
 */
class ImportaAlimentiOpenFoodFacts extends Command
{
    protected $signature = 'alimenti:importa-off
        {file : Il .csv.gz scaricato da static.openfoodfacts.org}
        {--paese=en:italy : Il tag di paese da tenere}
        {--limite=0 : Quanti prodotti al massimo, 0 = tutti}
        {--min-nome=3 : Lunghezza minima del nome}';

    protected $description = 'Importa i prodotti di marca da un export di Open Food Facts';

    /** 🚨 L'attribuzione richiesta dall\'ODbL. Va su ogni riga. */
    private const NOTA = 'Fonte: Open Food Facts (openfoodfacts.org) — dati ODbL 1.0, immagini CC BY-SA 3.0';

    /**
     * ⚠️ I nomi delle colonne **come stanno nell'intestazione del file**, non
     * come li chiamiamo noi. Cambiano raramente ma cambiano, ed e' il motivo
     * per cui l'intestazione si legge invece di dare per scontata la posizione.
     */
    /*
     * \U0001F4A1 `GerarchiaFonti` non e' iniettata, ed e' voluto.
     *
     * La regola che serve qui e' una sola \u2014 «Open Food Facts non tocca le
     * righe del CREA» \u2014 e chiederla al servizio riga per riga significava
     * un'interrogazione al database per prodotto. \u26A0\uFE0F Le chiavi protette
     * sono 832: si caricano una volta in un insieme in memoria, e il confronto
     * costa niente.
     *
     * \U0001F6A8 Il giorno in cui si aggiungesse una fonte di rango superiore a `OFF`
     * e molto piu' grande, questo insieme andrebbe ripensato \u2014 non il
     * principio, la sua taglia. La gerarchia completa resta in `GerarchiaFonti`
     * e la usa `CatalogoAlimenti`, dove i confronti sono uno alla volta davvero.
     */
    private const COLONNE = [
        'code', 'product_name', 'brands', 'quantity', 'countries_tags',
        'energy-kcal_100g', 'proteins_100g', 'carbohydrates_100g', 'fat_100g',
        'image_url', 'image_small_url',
    ];

    public function handle(ChiaveAlimento $chiavi, AlimentoAmmissibile $filtro): int
    {
        $percorso = $this->argument('file');

        if (! is_file($percorso)) {
            $this->error("File non trovato: {$percorso}");
            $this->line('Si scarica da: https://static.openfoodfacts.org/data/en.openfoodfacts.org.products.csv.gz');

            return self::FAILURE;
        }

        $flusso = gzopen($percorso, 'rb');

        if ($flusso === false) {
            $this->error('Non si riesce ad aprire il file compresso.');

            return self::FAILURE;
        }

        // 🚨 L'esportazione e' separata da **tabulazioni**, non da virgole: i
        // nomi dei prodotti sono pieni di virgole, e un file a virgole sarebbe
        // illeggibile.
        $intestazione = fgetcsv($flusso, 0, "\t", '"', '\\');

        if ($intestazione === false) {
            $this->error('File vuoto o illeggibile.');
            gzclose($flusso);

            return self::FAILURE;
        }

        $indice = $this->indiceColonne($intestazione);

        if ($indice === null) {
            gzclose($flusso);

            return self::FAILURE;
        }

        $paese = (string) $this->option('paese');
        $limite = (int) $this->option('limite');
        $minNome = (int) $this->option('min-nome');

        // 🚨 Le chiavi che Open Food Facts non puo' sovrascrivere, caricate
        // una volta sola: sono quelle delle fonti di rango superiore.
        $intoccabili = Food::query()
            ->where('fonte', 'like', 'CREA:%')
            ->pluck('chiave')
            ->flip()
            ->all();

        $this->info(count($intoccabili).' righe protette da fonti superiori.');

        // ⚠️ Un istante solo per tutta l'importazione: `now()` chiamato due
        // milioni di volte e' lavoro sprecato, e le date servono a sapere
        // **quando e' stata fatta l'importazione**, non il singolo prodotto.
        $adesso = now();

        $scrittura = new ScritturaABlocchi;

        $letti = $tenuti = $scartati = 0;

        while (($riga = fgetcsv($flusso, 0, "\t", '"', '\\')) !== false) {
            $letti++;

            if ($letti % 100000 === 0) {
                $this->line("  letti {$letti} · tenuti {$tenuti}");
            }

            $prendi = fn (string $c): string => trim((string) ($riga[$indice[$c]] ?? ''));

            // ⚠️ Il filtro di paese per primo: e' quello che scarta il 98 %
            // delle righe, e farlo prima di ogni altro controllo e' la
            // differenza fra dieci minuti e un'ora.
            if (! str_contains($prendi('countries_tags'), $paese)) {
                continue;
            }

            $nome = $prendi('product_name');

            if (mb_strlen($nome) < $minNome) {
                $scartati++;

                continue;
            }

            $per100 = [
                'kcal_100' => $this->numero($prendi('energy-kcal_100g')),
                'protein_100' => $this->numero($prendi('proteins_100g')),
                'carbs_100' => $this->numero($prendi('carbohydrates_100g')),
                'fat_100' => $this->numero($prendi('fat_100g')),
            ];

            /*
             * 🚨 Il filtro di coerenza serve **soprattutto** qui.
             *
             * Open Food Facts e' collaborativo: i valori li digitano le
             * persone, dalle etichette, con la tastiera del telefono. ⚠️ Le
             * virgole messe male ci sono, e senza questo controllo finirebbero
             * dritte nei suggerimenti di tutti.
             */
            if (! $filtro->ammesso($nome, $per100, ['da_fonte' => true])) {
                $scartati++;

                continue;
            }

            $marca = $this->primaMarca($prendi('brands'));

            $chiave = $chiavi->da($nome, $marca);

            /*
             * 🚨 **L'unico controllo di gerarchia che serve qui, e sta in
             * memoria.**
             *
             * Chiedere al database, riga per riga, «questa chiave e' gia' di
             * qualcun altro?» significava **due interrogazioni per prodotto**:
             * su due milioni di prodotti sono quattro milioni di viaggi, ed e'
             * cio' che rendeva l'importazione lunga giorni invece di ore.
             *
             * 💡 Le uniche righe che Open Food Facts non puo' toccare sono
             * quelle del CREA, e sono **832**: stanno in un insieme in memoria,
             * e il confronto costa niente.
             *
             * ⚠️ Se un giorno si aggiungesse una fonte di rango superiore a
             * `OFF` e piu' grande di qualche migliaio di righe, questo insieme
             * andrebbe ripensato — non il principio, la sua taglia.
             */
            if (isset($intoccabili[$chiave])) {
                $scartati++;

                continue;
            }

            /*
             * ⚠️ **Lo stesso file contiene la stessa chiave piu' volte.**
             *
             * Varianti di formato dello stesso prodotto — stesso nome, stessa
             * marca, codici a barre diversi — finiscono sulla stessa chiave.
             * 🚨 In una scrittura a blocchi, due righe con la stessa chiave nel
             * **medesimo** `upsert` fanno fallire l'intera operazione: MySQL non
             * sa quale delle due tenere. Qui l'ultima sovrascrive la precedente
             * **prima** che il blocco parta, che e' lo stesso esito che dava la
             * scrittura una-per-volta.
             */
            $scrittura->aggiungi([
                'chiave' => $chiave,
                'nome' => mb_substr($nome, 0, 120),
                'marca' => $marca,
                ...$per100,
                'basis' => 'g',
                'origine' => Food::ORIGINE_MANUALE,
                'fonte' => 'OFF:'.$prendi('code'),
                'note' => self::NOTA,
                'codice_a_barre' => mb_substr($prendi('code'), 0, 20) ?: null,
                'immagine_url' => $this->url($prendi('image_url')),
                'immagine_piccola_url' => $this->url($prendi('image_small_url')),
                'pubblico' => true,
                'conferme' => Food::CONFERME_PER_PUBBLICARE,
                'usi' => 0,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ]);

            $tenuti++;

            if ($limite > 0 && $tenuti >= $limite) {
                break;
            }
        }

        // ⚠️ L'ultimo blocco, quasi sempre incompleto: senza questa riga si
        // perderebbero fino a mille prodotti, in silenzio.
        $scrittura->scarica();

        gzclose($flusso);

        $this->newLine();
        $this->info("Righe lette: {$letti} · importate: {$tenuti} · scartate dai filtri: {$scartati}");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $intestazione
     * @return array<string, int>|null
     */
    private function indiceColonne(array $intestazione): ?array
    {
        $posizioni = array_flip($intestazione);
        $indice = [];

        foreach (self::COLONNE as $colonna) {
            if (! isset($posizioni[$colonna])) {
                // 🚨 Si ferma invece di proseguire con una colonna in meno: un
                // import a cui manca `proteins_100g` scriverebbe migliaia di
                // righe con le proteine a zero, e sembrerebbero dati veri.
                $this->error("Colonna mancante nell'export: «{$colonna}». Il formato e' cambiato.");

                return null;
            }

            $indice[$colonna] = $posizioni[$colonna];
        }

        return $indice;
    }

    private function numero(string $valore): ?float
    {
        if ($valore === '') {
            return null;
        }

        return is_numeric($valore) ? (float) $valore : null;
    }

    /**
     * 💡 Il campo `brands` porta un elenco separato da virgole
     * («Barilla,Mulino Bianco»). Per la chiave ne serve **una**, sempre la
     * stessa: prendendo la prima, lo stesso prodotto cade sempre sulla stessa
     * riga anche se qualcuno riordina l'elenco a monte.
     */
    private function primaMarca(string $brands): ?string
    {
        if ($brands === '') {
            return null;
        }

        $prima = trim(explode(',', $brands)[0]);

        return $prima === '' ? null : mb_substr($prima, 0, 60);
    }

    /** ⚠️ Solo indirizzi veri: un campo sporco diventerebbe un'immagine rotta. */
    private function url(string $valore): ?string
    {
        if ($valore === '' || ! str_starts_with($valore, 'https://')) {
            return null;
        }

        return mb_substr($valore, 0, 255);
    }
}
