<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Promozione;
use App\Services\Promozioni\ConsegnaDeiPremi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 🚀 **«Esegui»: i regali a tutti i destinatari** — 24/09/2026, P2 di
 * `plan_promozioni.md` (opzione 31).
 *
 * In coda perché i destinatari possono essere migliaia: il pannello risponde
 * subito, e i gettoni arrivano nei secondi dopo.
 *
 * ⚠️ **Un tentativo solo**: il giro ha un riferimento per persona
 * (`massa:<ts>:<user>`), quindi ripeterlo non regalerebbe due volte — ma un
 * giro fallito a metà si rilancia a mano, guardando il registro, non da solo.
 */
class ConsegnaLaPromozioneATutti implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $promozioneId)
    {
        $this->afterCommit();
    }

    public function handle(ConsegnaDeiPremi $consegna): void
    {
        $promozione = Promozione::query()->with(['premi', 'bersagli'])->find($this->promozioneId);

        if ($promozione === null) {
            return;
        }

        $quante = $consegna->eseguiPerTutti($promozione);

        Log::info('Promozioni: accredito di massa eseguito.', ['promozione' => $promozione->codice, 'persone' => $quante]);
    }
}
