<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Promozione;
use App\Services\Promozioni\AvvisaIDestinatari;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 📣 **«Avvisa i destinatari»** — 24/09/2026, P8 di `plan_promozioni.md`.
 *
 * In coda come «Esegui»: i destinatari possono essere migliaia. ⚠️ Un tentativo
 * solo: rimandare lo stesso avviso due volte è peggio di non mandarlo.
 */
class AvvisaLaPromozioneATutti implements ShouldQueue
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

    public function handle(AvvisaIDestinatari $avvisi): void
    {
        $promozione = Promozione::query()->with(['premi', 'bersagli', 'condizioni'])->find($this->promozioneId);

        if ($promozione === null) {
            return;
        }

        Log::info('Promozioni: avviso mandato.', ['promozione' => $promozione->codice, 'persone' => $avvisi->aTutti($promozione)]);
    }
}
