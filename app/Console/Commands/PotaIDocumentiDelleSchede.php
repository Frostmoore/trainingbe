<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkoutPlanImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * 🗑️ **Cancella i documenti delle schede importate dal pannello** — A8 di
 * `plan_blocco_A.md`, 22/09/2026.
 *
 * ── 🚨 Perché esiste ──────────────────────────────────────────────────────
 *
 * Una palestra carica il PDF o la foto di una scheda per farla trascrivere
 * (`WorkoutPlanImport`, disco privato, atlante §114.3). Fino al 22/09/2026 quel
 * documento **restava per sempre**, e nel pannello non c'era modo di toglierlo.
 * Se la scheda era intestata a un allievo, il suo nome restava sul server senza
 * scadenza (DPIA, R19).
 *
 * 💡 **Trenta giorni** dal caricamento bastano per rivederla, crearla e
 * confrontarla nel builder col documento accanto; dopo, la scheda vive da sé e il
 * documento non serve più. ⚠️ Si cancella **il file**, non l'import: la riga resta
 * come storia (chi, quando, com'è andata), e la scheda creata non si tocca.
 *
 * Per confronto: i documenti importati **dall'app** si cancellano entro 7 giorni
 * (`piani:pota-importazioni`).
 */
class PotaIDocumentiDelleSchede extends Command
{
    /** Quanti giorni resta il documento dopo il caricamento. */
    public const GIORNI = 30;

    protected $signature = 'schede:pota-documenti {--dry-run : Dice cosa cancellerebbe senza cancellarlo}';

    protected $description = 'Cancella i documenti delle schede importate dal pannello dopo '.self::GIORNI.' giorni';

    public function handle(TenantContext $contesto): int
    {
        $prova = (bool) $this->option('dry-run');

        /*
         * ⚠️ Fuori dal contesto di una palestra: il comando gira di notte per
         * tutte, e con il contesto vuoto il filtro della palestra non c'è.
         */
        /** @var Collection<int, WorkoutPlanImport> $scaduti */
        $scaduti = $contesto->runWithoutTenant(fn (): Collection => WorkoutPlanImport::withoutGlobalScopes()
            ->where('created_at', '<=', now()->subDays(self::GIORNI))
            ->whereHas('media', fn ($q) => $q->where('collection_name', WorkoutPlanImport::COLLECTION))
            ->get());

        if (! $prova) {
            foreach ($scaduti as $import) {
                $import->clearMediaCollection(WorkoutPlanImport::COLLECTION);
            }
        }

        $this->info(($prova ? '[prova] ' : '').'Documenti cancellati: '.$scaduti->count());

        return self::SUCCESS;
    }
}
