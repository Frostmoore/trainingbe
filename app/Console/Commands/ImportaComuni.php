<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Comune;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carica i comuni italiani da `database/data/comuni.jsonl` — 18/08/2026. **M1.1.**
 *
 * ── 🚨 Perche' legge un file invece di scaricare ───────────────────────────
 *
 * Il file consolidato nasce da **tre fonti** (ISTAT per l'elenco, un derivato per
 * il CAP, un altro per le coordinate: vedi `database/data/LEGGIMI.md`). ⚠️ Un
 * comando che le scarica tutte e tre a ogni esecuzione e' un comando che si
 * rompe il giorno che uno dei tre indirizzi cambia — e si rompe **durante un
 * deploy**, che e' il momento peggiore.
 *
 * 💡 Quindi le fonti si uniscono a mano con `database/data/costruisci-comuni.php`,
 * il risultato si committa, e questo comando legge un file che c'e' sempre. E'
 * la stessa lezione del catalogo alimenti: **un import lungo si fa una volta e
 * poi si sposta il risultato**, non si rifa' a ogni occasione.
 *
 * ── 🚨 Non cancella mai niente ─────────────────────────────────────────────
 *
 * Quando due comuni si fondono l'ISTAT toglie i vecchi dall'elenco. Qui invece
 * vengono messi a `attivo = false`: spariscono dalla ricerca, ma **chi li aveva
 * scelti sul proprio profilo continua a vedere il nome giusto**. ⚠️ Cancellarli
 * vorrebbe dire azzerare un dato di una persona per un riordino amministrativo
 * che non la riguarda.
 *
 * ── 💡 Ripetibile senza danno ──────────────────────────────────────────────
 *
 * Si puo' rilanciare quante volte si vuole: le righe si riconoscono dal codice
 * ISTAT, che e' l'unico identificatore stabile — i comuni cambiano nome e
 * cambiano provincia, il codice no.
 */
class ImportaComuni extends Command
{
    protected $signature = 'comuni:importa
        {--file= : Il percorso del JSONL, se diverso da quello standard}
        {--secco : Non scrive niente, dice solo cosa farebbe}';

    protected $description = 'Carica i comuni italiani (fonte ISTAT) da database/data/comuni.jsonl';

    /**
     * 🚨 Quante righe per `upsert`.
     *
     * ⚠️ Non e' un numero a caso: e' la lezione misurata sul catalogo alimenti.
     * `updateOrCreate()` riga per riga fa ~180 righe al secondo perche' e' una
     * `SELECT` piu' una `INSERT` per ciascuna; un `upsert` a blocchi ne fa
     * ~14.000. Su ottomila comuni la differenza e' fra quarantacinque secondi e
     * meno di uno.
     *
     * 💡 E mille e' il limite in su, non in giu': blocchi molto piu' grandi
     * sbattono contro `max_allowed_packet` di MySQL, con un errore che non parla
     * di dimensioni.
     */
    private const QUANTI = 1000;

    public function handle(ChiaveComune $chiavi): int
    {
        $file = (string) ($this->option('file') ?: database_path('data/comuni.jsonl'));

        if (! is_readable($file)) {
            $this->error("Non trovo il file dei comuni: {$file}");
            $this->line('Si costruisce con `php database/data/costruisci-comuni.php` — vedi database/data/LEGGIMI.md');

            return self::FAILURE;
        }

        $secco = (bool) $this->option('secco');

        /*
         * 🚨 Un solo istante per tutto l'import, fotografato **prima** di
         * leggere, e usato sia come `updated_at` delle righe scritte sia come
         * taglio per riconoscere quelle che l'import non ha toccato.
         *
         * ⚠️ Deve essere **lo stesso valore** nei due punti. Con `now()` chiamato
         * due volte i due istanti differiscono di qualche millisecondo, e nel
         * verso sbagliato: le righe appena scritte risulterebbero piu' vecchie
         * del taglio, e l'import spegnerebbe tutto quello che ha appena caricato.
         */
        $inizio = now();

        $righe = [];
        $scartate = 0;
        $lette = 0;

        $f = fopen($file, 'r');

        while (($riga = fgets($f)) !== false) {
            $riga = trim($riga);
            if ($riga === '') {
                continue;
            }

            $lette++;
            $d = json_decode($riga, true);

            if (! is_array($d) || ! isset($d['c'], $d['n'], $d['p'])) {
                $scartate++;

                continue;
            }

            $nome = (string) $d['n'];
            $altro = isset($d['a']) && $d['a'] !== null ? (string) $d['a'] : null;

            $righe[] = [
                'codice' => (string) $d['c'],
                'nome' => $nome,
                'nome_altro' => $altro,
                'chiave' => $chiavi->da($nome),
                'chiave_altro' => $altro !== null ? $chiavi->da($altro) : null,
                'provincia' => (string) $d['p'],
                'provincia_nome' => (string) ($d['pn'] ?? $d['p']),
                'regione' => (string) ($d['r'] ?? ''),
                'cap' => isset($d['cap']) && $d['cap'] !== null ? (string) $d['cap'] : null,
                'popolazione' => $d['pop'] ?? null,
                'lat' => $d['lat'] ?? null,
                'lng' => $d['lng'] ?? null,
                'attivo' => true,
                'created_at' => $inizio,
                'updated_at' => $inizio,
            ];
        }

        fclose($f);

        $this->line("Lette {$lette} righe".($scartate > 0 ? ", scartate {$scartate} illeggibili" : ''));

        if ($righe === []) {
            $this->error('Nessun comune valido nel file: non tocco niente.');

            return self::FAILURE;
        }

        if ($secco) {
            $adesso = Comune::query()->count();
            $this->info('Prova a secco: scriverei '.count($righe)." comuni (adesso in banca dati: {$adesso}).");

            return self::SUCCESS;
        }

        $prima = Comune::query()->count();

        foreach (array_chunk($righe, self::QUANTI) as $blocco) {
            /*
             * ⚠️ `created_at` **non** e' fra le colonne aggiornabili: e' la data
             * in cui la riga e' entrata la prima volta, e riscriverla a ogni
             * import cancellerebbe l'unica informazione che dice da quando quel
             * comune e' in banca dati.
             */
            Comune::query()->upsert(
                $blocco,
                ['codice'],
                [
                    'nome', 'nome_altro', 'chiave', 'chiave_altro',
                    'provincia', 'provincia_nome', 'regione', 'cap', 'popolazione',
                    'lat', 'lng', 'attivo', 'updated_at',
                ],
            );
        }

        /*
         * 🚨 Quelli che non erano nel file: spenti, non cancellati.
         *
         * ⚠️ `whereNotIn` con ottomila valori sarebbe una query enorme. Si fa al
         * contrario: si spegne tutto quello che questo import **non** ha
         * toccato, riconoscendolo da `updated_at`. Funziona perche' l'`upsert`
         * qui sopra ha appena riscritto `updated_at` su ogni riga presente nel
         * file.
         */
        $spenti = Comune::query()
            ->where('attivo', true)
            ->where('updated_at', '<', $inizio)
            ->update(['attivo' => false]);

        $dopo = Comune::query()->count();
        $nuovi = $dopo - $prima;

        $province = DB::table('comuni')->distinct()->count('provincia');
        $senzaCoord = Comune::query()->whereNull('lat')->count();

        $this->newLine();
        $this->info("Comuni in banca dati: {$dopo} (+{$nuovi} nuovi)");
        $this->line("Province: {$province}");

        if ($senzaCoord > 0) {
            /*
             * 💡 Non e' un errore, ed e' importante che non lo sembri: la fonte
             * delle coordinate e' del 2018 e i comuni nati dopo non ne hanno.
             * `Vicinanza` li gestisce ripiegando su provincia e regione.
             */
            $this->line("Senza coordinate: {$senzaCoord} — per loro la vicinanza usa provincia e regione");
        }

        if ($spenti > 0) {
            $this->warn("Spenti (non piu' nell'elenco ISTAT, NON cancellati): {$spenti}");
        }

        return self::SUCCESS;
    }
}
