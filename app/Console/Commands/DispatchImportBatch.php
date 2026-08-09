<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ParseWorkoutPdf;
use App\Models\Tenant;
use App\Models\WorkoutPlanImport;
use Illuminate\Console\Command;

/**
 * Accoda in blocco gli import in attesa di una palestra — B7.6.
 *
 * 🚨 **Serve all'onboarding, che e' un caso diverso dal singolo upload.**
 * Quando una palestra entra caricando tutto il proprio storico — decine o
 * centinaia di schede — la latenza non conta: nessuno sta aspettando davanti allo
 * schermo. E' esattamente la situazione della Batches API, che costa la meta'.
 *
 * ⚠️ **Oggi questo comando accoda job normali.** Il percorso Batch vero (invio
 * in blocco + poller dei risultati) e' debito tecnico dichiarato: farlo adesso
 * significherebbe scrivere e provare un poller senza avere ancora un solo
 * onboarding reale da cui capire i volumi. Il comando esiste comunque perche' il
 * punto di ingresso non cambi quando lo si implementera': chi lo usa oggi ottiene
 * il risultato giusto, solo a prezzo pieno.
 *
 * `--limit` c'e' perche' accodare quattrocento job insieme satura la coda e
 * blocca tutto il resto della piattaforma per ore.
 */
class DispatchImportBatch extends Command
{
    protected $signature = 'imports:dispatch-batch
                            {tenant : id o slug della palestra}
                            {--limit=50 : quanti accodarne al massimo}';

    protected $description = 'Accoda gli import PDF in attesa di una palestra (onboarding).';

    public function handle(): int
    {
        $chiave = (string) $this->argument('tenant');

        $palestra = Tenant::query()
            ->where('id', is_numeric($chiave) ? (int) $chiave : 0)
            ->orWhere('slug', $chiave)
            ->first();

        if ($palestra === null) {
            $this->error("Palestra «{$chiave}» non trovata.");

            return self::FAILURE;
        }

        $limite = max(1, (int) $this->option('limit'));

        $inAttesa = WorkoutPlanImport::withoutGlobalScopes()
            ->where('tenant_id', $palestra->id)
            ->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Failed->value])
            ->limit($limite)
            ->get();

        if ($inAttesa->isEmpty()) {
            $this->info('Niente da accodare.');

            return self::SUCCESS;
        }

        foreach ($inAttesa as $import) {
            $import->forceFill(['status' => ImportStatus::Queued, 'error' => null])->save();

            ParseWorkoutPdf::dispatch($import->id);
        }

        $this->info("Accodati {$inAttesa->count()} import per «{$palestra->name}».");
        $this->warn('Percorso sincrono a prezzo pieno: la Batches API (-50%) e\' debito tecnico dichiarato.');

        return self::SUCCESS;
    }
}
