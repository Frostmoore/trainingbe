<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Un job che non fa niente, e serve a sapere che qualcuno lo esegue.
 *
 * 🚨 **Esiste perche' «il worker gira» non vuol dire «il worker lavora».**
 * `supervisorctl status` dice che il processo e' vivo: non dice che sia
 * collegato al database giusto, che veda la coda giusta, che il PHP sia quello
 * con le estensioni che servono, ne' che il codice non sia rimasto a una
 * versione precedente. Tutte cose gia' successe altrove, e tutte silenziose —
 * i job si accumulano e nessuno se ne accorge finche' un utente non chiede
 * perche' l'import della sua scheda e' fermo da tre giorni.
 *
 * Scrive un segno in cache e basta: nessuna riga a database, niente da
 * ripulire, e funziona con qualunque driver di cache.
 */
class QueueSelfTest implements ShouldQueue
{
    use Queueable;

    /**
     * ⚠️ Un solo tentativo. Se fallisce, il rimedio non e' riprovare: e'
     * guardare perche'.
     */
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(private readonly string $marcatore) {}

    public static function chiaveCache(string $marcatore): string
    {
        return "queue-self-test.{$marcatore}";
    }

    public function handle(): void
    {
        // Cinque minuti: abbastanza perche' il comando che aspetta lo trovi,
        // abbastanza poco da non lasciare residui in giro.
        Cache::put(self::chiaveCache($this->marcatore), now()->toIso8601String(), 300);
    }
}
