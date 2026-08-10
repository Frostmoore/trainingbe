<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\QueueSelfTest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Verifica che un worker stia davvero consumando la coda.
 *
 * 🚨 **«Il processo e' vivo» e «i job vengono eseguiti» sono due cose diverse.**
 * Un worker collegato al database sbagliato, avviato con un PHP senza le
 * estensioni giuste, o fermo su una versione precedente del codice, risulta
 * `RUNNING` in `supervisorctl status` e non lavora. Il guasto e' silenzioso: i
 * job si accumulano e non se ne accorge nessuno finche' un utente non chiede
 * perche' l'import della sua scheda e' fermo da tre giorni.
 *
 * Va chiamato **dopo ogni deploy**: e' il momento in cui il worker resta
 * indietro, perche' tiene in memoria il codice con cui e' partito. Per lo stesso
 * motivo il deploy deve anche chiamare `queue:restart`.
 *
 * Esce con codice 1 se il job non arriva: cosi' uno script di deploy se ne
 * accorge da solo invece di stampare un messaggio che nessuno legge.
 */
class QueueHealthCheck extends Command
{
    protected $signature = 'queue:health
                            {--wait=20 : secondi da aspettare prima di arrendersi}';

    protected $description = 'Accoda un job finto e verifica che un worker lo esegua.';

    public function handle(): int
    {
        $attesa = max(1, (int) $this->option('wait'));

        // Un marcatore diverso a ogni esecuzione: con una chiave fissa, un
        // valore rimasto dall'esecuzione precedente farebbe passare il controllo
        // anche con la coda ferma — cioe' un controllo che mente proprio nel
        // caso che deve intercettare.
        $marcatore = Str::random(16);

        QueueSelfTest::dispatch($marcatore);

        $this->line("Job accodato. Aspetto fino a {$attesa}s che qualcuno lo esegua…");

        $chiave = QueueSelfTest::chiaveCache($marcatore);
        $scadenza = microtime(true) + $attesa;

        while (microtime(true) < $scadenza) {
            $eseguitoAlle = Cache::get($chiave);

            if ($eseguitoAlle !== null) {
                Cache::forget($chiave);

                $impiegati = round($attesa - ($scadenza - microtime(true)), 1);

                $this->info("✓ Coda viva: il job è stato eseguito in {$impiegati}s (alle {$eseguitoAlle}).");

                return self::SUCCESS;
            }

            usleep(500_000);
        }

        $this->error("✗ Nessun worker ha eseguito il job entro {$attesa}s.");
        $this->newLine();
        $this->line('Cosa guardare, in ordine:');
        $this->line('  1. sudo supervisorctl status training-worker');
        $this->line('  2. tail -50 storage/logs/worker.log');
        $this->line('  3. QUEUE_CONNECTION nel .env (deve essere `database`, non `sync`)');
        $this->line('  4. che il worker usi lo STESSO database dell\'applicazione');

        return self::FAILURE;
    }
}
