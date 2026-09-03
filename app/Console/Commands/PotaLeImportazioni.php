<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportazionePiano;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Butta le importazioni di piani alimentari scadute — N20.
 *
 * ── 🚨 Perche' non basta la cancellazione alla conferma ────────────────────
 *
 * Un'importazione se ne va quando l'app la chiude: bozza confermata e portata
 * sul telefono, oppure scartata. Ma se nessuno la chiude **mai** — la persona
 * apre il PDF, si stanca alla decima riga e non torna piu' — quella riga e quel
 * file resterebbero li'.
 *
 * ⚠️ E non e' un file qualunque: e' un PDF con dentro **la dieta di qualcuno**,
 * cioe' la cosa piu' delicata che passi da questo server. Sette giorni sono
 * larghi per chi ci mette qualche giorno a controllare riga per riga, e stretti
 * abbastanza da non farne un archivio di diete.
 *
 * ── ⚠️ Anche i file orfani ────────────────────────────────────────────────
 *
 * `ImportazionePiano::apri()` scrive **prima il file e poi la riga**, di
 * proposito: nell'ordine inverso esisterebbe un istante in cui la riga promette
 * un PDF che non c'e', e il job partirebbe a vuoto. Il prezzo e' che un guasto
 * fra le due operazioni lascia un file senza riga — e quello non lo
 * cancellerebbe nessuno. Lo raccoglie questa seconda passata.
 */
class PotaLeImportazioni extends Command
{
    protected $signature = 'piani:pota-importazioni {--dry-run : Dice cosa butterebbe senza buttarlo}';

    protected $description = 'Butta le importazioni di piani alimentari scadute, e i PDF rimasti orfani';

    public function handle(): int
    {
        $prova = (bool) $this->option('dry-run');

        /*
         * 🚨 `withoutGlobalScopes()`: gira da riga di comando, dove non c'e'
         * nessuna palestra corrente. Senza, il filtro non troverebbe niente e il
         * comando direbbe «zero scadute» ogni notte, per sempre.
         */
        $scadute = ImportazionePiano::withoutGlobalScopes()
            ->where('scade_il', '<=', now())
            ->get();

        foreach ($scadute as $importazione) {
            if (! $prova) {
                $importazione->butta();
            }
        }

        $this->info(($prova ? '[prova] ' : '').'Importazioni scadute: '.$scadute->count());

        $orfani = $this->orfani($prova);

        $this->info(($prova ? '[prova] ' : '').'PDF orfani: '.$orfani);

        return self::SUCCESS;
    }

    /**
     * I PDF sul disco che non hanno piu' una riga.
     *
     * ⚠️ **Solo quelli piu' vecchi di un'ora.** Un file appena scritto potrebbe
     * essere di un'importazione che sta nascendo proprio adesso, fra il `put()`
     * e il `create()`: cancellarlo vorrebbe dire rompere una richiesta in corso.
     */
    private function orfani(bool $prova): int
    {
        $disco = Storage::disk('local');
        $soglia = now()->subHour()->getTimestamp();

        /*
         * 🆕 **I token stanno dentro `documenti`** — K1, 03/09/2026.
         *
         * ⛔ Qui c'era `pluck('token')`: una colonna sola, un documento solo.
         * 🚨 Dopo K1 un'importazione ne ha fino a cinque, e leggerne uno
         * vorrebbe dire che gli altri quattro risultano **orfani** — e questo
         * comando li cancellerebbe da sotto a un'importazione viva.
         */
        $vivi = [];

        foreach (ImportazionePiano::withoutGlobalScopes()->pluck('documenti') as $documenti) {
            foreach ((array) $documenti as $documento) {
                $vivi[$documento['token']] = true;
            }
        }

        $quanti = 0;

        foreach ($disco->files(ImportazionePiano::CARTELLA) as $percorso) {
            $nome = basename($percorso);

            if (isset($vivi[$nome])) {
                continue;
            }

            if ($disco->lastModified($percorso) > $soglia) {
                continue;
            }

            $quanti++;

            if (! $prova) {
                $disco->delete($percorso);
            }
        }

        return $quanti;
    }
}
