<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PagamentoIncassato;
use App\Services\Fatturazione\EmissioneDelleFatture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 🧾 La fattura di un incasso, fuori dal webhook — fase F8, 23/09/2026.
 *
 * 🚨 **In coda, e dopo il commit.** Il webhook di Stripe deve rispondere 200
 * appena il pagamento è registrato: se l'emissione della fattura fallisse lì
 * dentro, Stripe ritenterebbe l'evento e al secondo giro troverebbe il servizio
 * già acceso. Qui un errore riprova da solo, e non tocca il pagamento.
 *
 * 💡 **Idempotente**: `EmissioneDelleFatture::perIncasso()` restituisce la
 * fattura che c'è già, quindi i ritentativi e i webhook doppi non ne creano due.
 */
class EmettiLaFattura implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $pagamentoId)
    {
        $this->afterCommit();
    }

    public function handle(EmissioneDelleFatture $emissione): void
    {
        $pagamento = PagamentoIncassato::withoutGlobalScopes()->find($this->pagamentoId);

        if ($pagamento !== null) {
            $emissione->perIncasso($pagamento);
        }
    }
}
