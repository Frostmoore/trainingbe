<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Comune;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🌍 **Carica le città del mondo** da `database/data/mondo.jsonl.gz` — F6.1.
 *
 * 📌 Il committente: *«Facciamo stessa tabella ma mondiale. E deve
 * funzionare.»*
 *
 * ══ 🇮🇹 L'ITALIA NON PASSA DA QUI ════════════════════════════════════════
 *
 * Ha il suo comando (`comuni:importa`), la sua fonte (ISTAT) e i suoi dati in
 * più: CAP e sigla di provincia, che GeoNames non ha. ⛔ I due insiemi non si
 * sovrappongono mai — il file compresso l'Italia non ce l'ha proprio.
 *
 * 🚨 **E i due comandi non si spengono a vicenda.** Ognuno spegne solo le
 * righe *del proprio perimetro*: senza quella precauzione, il primo
 * `comuni:importa` dopo questo spegnerebbe duecentomila città perché «non sono
 * nell'elenco ISTAT».
 *
 * ══ ⚠️ PERCHÉ LEGGE UN FILE COMPRESSO, A PEZZI ═══════════════════════════
 *
 * 🚨 Sono **224.000 righe**: caricarle tutte in un array prima di scrivere
 * vorrebbe dire trecento megabyte di memoria, e un `php artisan` che muore con
 * «allowed memory size exhausted» a metà — lasciando la tabella a metà.
 *
 * 💡 Qui si legge a blocchi e si scrive man mano: la memoria resta quella di
 * mille righe, e un'interruzione lascia indietro delle città, non un disastro.
 *
 * ⏱️ Dura **qualche minuto**. È un comando da deploy, non da richiesta web.
 */
class ImportaIlMondo extends Command
{
    protected $signature = 'comuni:importa-mondo
        {--file= : Il percorso del .jsonl.gz, se diverso da quello standard}
        {--secco : Non scrive niente, dice solo cosa farebbe}';

    protected $description = 'Carica le città del mondo (fonte GeoNames) da database/data/mondo.jsonl.gz';

    /** Come in `ImportaComuni`: mille è il limite in su, non in giù. */
    private const QUANTI = 1000;

    public function handle(ChiaveComune $chiavi): int
    {
        $file = (string) ($this->option('file') ?: database_path('data/mondo.jsonl.gz'));

        if (! is_readable($file)) {
            $this->error("Non trovo il file del mondo: {$file}");
            $this->line('Si costruisce con `php database/data/costruisci-mondo.php` — vedi database/data/LEGGIMI.md');

            return self::FAILURE;
        }

        $secco = (bool) $this->option('secco');

        /*
         * 🚨 Un solo istante, fotografato **prima** di leggere: è sia
         * l'`updated_at` delle righe scritte sia il taglio per riconoscere
         * quelle che questo import non ha toccato. ⚠️ Con due `now()` diversi
         * le righe appena scritte risulterebbero più vecchie del taglio, e
         * l'import spegnerebbe tutto quello che ha appena caricato.
         */
        $inizio = now();

        $prima = $this->quante();

        $f = gzopen($file, 'rb');

        if ($f === false) {
            $this->error('Non riesco ad aprire il file compresso.');

            return self::FAILURE;
        }

        $blocco = [];
        $lette = 0;
        $scartate = 0;
        $scritte = 0;
        $paesi = [];

        $barra = $this->output->createProgressBar();
        $barra->start();

        while (($riga = gzgets($f)) !== false) {
            $riga = trim($riga);

            if ($riga === '') {
                continue;
            }

            $lette++;
            $d = json_decode($riga, true);

            if (! is_array($d) || ! isset($d['g'], $d['n'], $d['p'])) {
                $scartate++;

                continue;
            }

            $paese = strtoupper((string) $d['p']);

            /*
             * 🚨 **L'Italia si rifiuta, anche se arrivasse.** Il file non ce
             * l'ha, ma un `--file` sbagliato sì: ⛔ scrivere Milano da GeoNames
             * accanto a quella dell'ISTAT vorrebbe dire due righe per lo stesso
             * posto, con due id, e due profili che puntano a due città diverse
             * che sono la stessa.
             */
            if ($paese === 'IT') {
                $scartate++;

                continue;
            }

            $paesi[$paese] = true;

            $nome = (string) $d['n'];

            /*
             * 🗣️ **I tre nomi**: GeoNames (`Munich`), italiano (`Monaco di
             * Baviera`), locale (`München`). Il perché per esteso sta in
             * `costruisci-mondo.php`.
             *
             * ⚠️ L'italiano va in `nome_altro` e non in una colonna nuova: è
             * **già** il campo che l'app mostra accanto al nome, dal giorno dei
             * comuni bilingui (`Bolzano` / `Bozen`). 💡 Stessa domanda — «come
             * si chiama anche» — e stessa risposta.
             */
            $altro = isset($d['it']) && $d['it'] !== null ? (string) $d['it'] : null;
            $locale = isset($d['l']) && $d['l'] !== null ? (string) $d['l'] : null;

            $blocco[] = [
                /*
                 * 💡 Il codice **è** il geonameid, scritto anche nella sua
                 * colonna: l'uno serve all'unicità `(paese, codice)`, l'altro a
                 * ritrovare la riga quando GeoNames pubblica un dump nuovo.
                 */
                'codice' => (string) $d['g'],
                'paese' => $paese,
                'geonameid' => (int) $d['g'],
                'nome' => $nome,
                'nome_altro' => $altro,
                'chiave' => $chiavi->da($nome),
                'chiave_altro' => $altro !== null ? $chiavi->da($altro) : null,
                'nome_locale' => $locale,
                'chiave_locale' => $locale !== null ? $chiavi->da($locale) : null,

                /*
                 * ⚠️ **`provincia` qui è il paese**, e non è una forzatura: in
                 * Italia quella colonna contiene la sigla che si scrive fra
                 * parentesi accanto al nome, e fuori dall'Italia la cosa che si
                 * scrive fra parentesi è il paese. 💡 `Comune::esteso()` legge
                 * `paese` per decidere quale delle due frasi comporre.
                 */
                'provincia' => $paese,
                'provincia_nome' => (string) ($d['pn'] ?? $paese),
                'admin1' => isset($d['a1']) ? (string) $d['a1'] : null,
                'admin2' => isset($d['a2']) ? (string) $d['a2'] : null,
                'regione' => (string) ($d['r'] ?? $paese),

                // ⛔ GeoNames non ha il CAP in questo dump: `null`, non inventato.
                'cap' => null,
                'popolazione' => $d['pop'] ?? null,
                'lat' => $d['lat'] ?? null,
                'lng' => $d['lng'] ?? null,
                'attivo' => true,
                'created_at' => $inizio,
                'updated_at' => $inizio,
            ];

            if (count($blocco) >= self::QUANTI) {
                $scritte += $secco ? count($blocco) : $this->scrivi($blocco);
                $blocco = [];
                $barra->advance(self::QUANTI);
            }
        }

        gzclose($f);

        if ($blocco !== []) {
            $scritte += $secco ? count($blocco) : $this->scrivi($blocco);
        }

        $barra->finish();
        $this->newLine(2);

        $this->line("Lette {$lette} righe".($scartate > 0 ? ", scartate {$scartate}" : ''));

        if ($scritte === 0) {
            $this->error('Nessuna città valida nel file: non tocco niente.');

            return self::FAILURE;
        }

        if ($secco) {
            $this->info("Prova a secco: scriverei {$scritte} città di ".count($paesi).' paesi.');

            return self::SUCCESS;
        }

        /*
         * 🚨 **Spente, non cancellate**, e **solo fuori dall'Italia**.
         *
         * ⚠️ Cancellare una città vorrebbe dire azzerare la città sul profilo
         * di chi l'aveva scelta — per un riordino amministrativo che non la
         * riguarda. È la stessa regola dei comuni italiani, dal primo giorno.
         */
        $spente = Comune::query()
            ->where('paese', '!=', 'IT')
            ->where('attivo', true)
            ->where('updated_at', '<', $inizio)
            ->update(['attivo' => false]);

        $dopo = $this->quante();

        $this->info("Città nel mondo: {$dopo} (+".($dopo - $prima).' nuove), '.count($paesi).' paesi');
        $this->line('In Italia: '.Comune::query()->where('paese', 'IT')->count().' comuni (fonte ISTAT, a parte)');

        if ($spente > 0) {
            $this->warn("Spente (non piu' nel dump GeoNames, NON cancellate): {$spente}");
        }

        return self::SUCCESS;
    }

    /**
     * Scrive un blocco e dice quante righe ha passato.
     *
     * @param  list<array<string, mixed>>  $blocco
     */
    private function scrivi(array $blocco): int
    {
        DB::table('comuni')->upsert(
            $blocco,
            ['paese', 'codice'],
            [
                'geonameid', 'nome', 'nome_altro', 'chiave', 'chiave_altro',
                'nome_locale', 'chiave_locale',
                'provincia', 'provincia_nome', 'admin1', 'admin2', 'regione',
                'popolazione', 'lat', 'lng', 'attivo', 'updated_at',
            ],
        );

        return count($blocco);
    }

    /** Quante città fuori dall'Italia. */
    private function quante(): int
    {
        return Comune::query()->where('paese', '!=', 'IT')->count();
    }
}
