<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Visualizzazione;
use Illuminate\Console\Command;

/**
 * Butta il dettaglio delle visualizzazioni piu' vecchio del dovuto — M5.
 *
 * ── 🚨 Perche' non si conservano per sempre ────────────────────────────────
 *
 * `visualizzazioni` contiene **chi ha visto cosa**: e' un dato personale, e
 * tenerlo all'infinito sarebbe conservare le abitudini di consultazione delle
 * persone per una ragione — «potrebbe servire» — che non e' una ragione.
 *
 * 💡 **Tredici mesi bastano**, e il numero non e' a caso: coprono il confronto
 * con lo stesso mese dell'anno prima, che e' la forma piu' lontana che puo'
 * prendere una contestazione su una fattura.
 *
 * ── ⚠️ Cosa NON si perde ───────────────────────────────────────────────────
 *
 * Il **conto** vive in `campagne.speso_mese_cent` e non viene toccato: la
 * fattura resta corretta senza dover conservare chi era. 🚨 E' la differenza
 * fra «quanto hai speso» — che va conservato — e «chi ti ha visto» — che no.
 */
class PotaLeVisualizzazioni extends Command
{
    protected $signature = 'pubblicita:pota
        {--secco : Non cancella niente, dice solo quante righe toglierebbe}';

    protected $description = 'Cancella il dettaglio delle visualizzazioni oltre il periodo di conservazione';

    public function handle(): int
    {
        $mesi = (int) config('listino.pubblicita.mesi_di_dettaglio', 13);
        $taglio = now()->subMonths($mesi)->startOfDay();

        $quante = Visualizzazione::where('giorno', '<', $taglio)->count();

        $this->line("Righe piu' vecchie del ".$taglio->format('d/m/Y').": {$quante}");

        if ($quante === 0) {
            return self::SUCCESS;
        }

        if ($this->option('secco')) {
            $this->info("Prova a secco: ne cancellerei {$quante}.");

            return self::SUCCESS;
        }

        /*
         * 💡 A blocchi: una cancellazione unica su qualche milione di righe
         * tiene un lock lungo, e questo comando gira **mentre il catalogo
         * risponde**. Meglio molte cancellazioni corte.
         */
        $tolte = 0;

        do {
            $blocco = Visualizzazione::where('giorno', '<', $taglio)->limit(5000)->delete();
            $tolte += $blocco;
        } while ($blocco > 0);

        $this->info("Cancellate {$tolte} righe di dettaglio. Gli importi in `campagne` non sono stati toccati.");

        return self::SUCCESS;
    }
}
