<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Jobs\EmettiLaFattura;
use App\Models\PagamentoIncassato;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 💰 **Chi scrive nel registro degli incassi** — 22/09/2026, fase F3.
 *
 * ══ 🚨 UNA SOLA PORTA, E NON È PIGNOLERIA ════════════════════════════════
 *
 * I soldi arrivano da **quattro strade** — l'abbonamento personale, i pacchetti
 * di gettoni, i piani di trainer e palestre, la fattura a consumo — e ognuna ha
 * il suo webhook. ⛔ Se ognuna scrivesse per conto suo, il giorno che una
 * dimentica una riga il totale della palestra sarebbe **sbagliato in meno**, e
 * un totale sbagliato in meno non dà nessun sospetto.
 *
 * ══ ⚠️ E NON DEVE MAI FAR FALLIRE UN PAGAMENTO ═══════════════════════════
 *
 * 🚨 Questo registro serve a **noi**, non al cliente: se scrivere la riga
 * fallisce, il pagamento è comunque andato a buon fine e il servizio va
 * acceso. ⛔ Lanciare da qui farebbe rispondere 500 a Stripe, che ritenterebbe
 * l'evento — e al secondo giro troverebbe l'abbonamento già attivo.
 */
class RegistroDeiPagamenti
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Segna un incasso.
     *
     * 🚨 **Idempotente sull'id di Stripe**: i webhook arrivano due volte, e un
     * registro che raddoppia è peggio di uno che manca — perché sembra giusto.
     */
    public function segna(
        Tenant|int $tenant,
        string $tipo,
        int $importoCent,
        ?string $stripeId = null,
        ?User $utente = null,
        ?string $cosa = null,
        int $scontoCent = 0,
        ?Carbon $quando = null,
    ): ?PagamentoIncassato {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        try {
            $riga = $this->context->runWithoutTenant(function () use (
                $tenantId, $tipo, $importoCent, $stripeId, $utente, $cosa, $scontoCent, $quando
            ): PagamentoIncassato {
                /*
                 * 💡 `updateOrCreate` sull'id di Stripe quando c'è: è la stessa
                 * difesa dell'unicità in tabella, ma senza far esplodere il
                 * webhook con un errore di vincolo.
                 */
                if ($stripeId !== null) {
                    return PagamentoIncassato::query()->updateOrCreate(
                        ['stripe_id' => $stripeId],
                        [
                            'tenant_id' => $tenantId,
                            'user_id' => $utente?->getKey(),
                            'tipo' => $tipo,
                            'importo_cent' => $importoCent,
                            'sconto_cent' => $scontoCent,
                            'cosa' => $cosa,
                            'pagato_il' => $quando ?? Carbon::now(),
                        ],
                    );
                }

                return PagamentoIncassato::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => $utente?->getKey(),
                    'tipo' => $tipo,
                    'importo_cent' => $importoCent,
                    'sconto_cent' => $scontoCent,
                    'cosa' => $cosa,
                    'pagato_il' => $quando ?? Carbon::now(),
                ]);
            });
        } catch (Throwable $e) {
            // ⚠️ Vedi il docblock: il pagamento è avvenuto comunque.
            Log::error('Registro dei pagamenti: riga non scritta.', [
                'tenant' => $tenantId,
                'tipo' => $tipo,
                'importo' => $importoCent,
                'stripe' => $stripeId,
                'errore' => $e->getMessage(),
            ]);

            return null;
        }

        /*
         * 🆕 🧾 **E dall'incasso nasce la fattura** — 23/09/2026, fase F8.
         *
         * 💡 Qui e non nei quattro webhook: questa è già **l'unica porta** da
         * cui passano i soldi, ed è il motivo per cui esiste. Mettere la
         * fattura accanto a ciascun chiamante vorrebbe dire quattro occasioni
         * di dimenticarla.
         *
         * ⚠️ **In coda e dopo il commit** (`EmettiLaFattura`), e dentro un
         * `try`: come la riga del registro, la fattura non deve mai far fallire
         * un pagamento. Chi la emette, e se tocca a noi farlo, lo decide
         * `EmissioneDelleFatture`.
         */
        try {
            EmettiLaFattura::dispatch((int) $riga->getKey());
        } catch (Throwable $e) {
            Log::error('Registro dei pagamenti: fattura non messa in coda.', [
                'pagamento' => $riga->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }

        return $riga;
    }

    /** Quanto ha dato questa palestra, dall'inizio. */
    public function totaleDi(Tenant|int $tenant): int
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return (int) $this->context->runWithoutTenant(
            fn (): int => (int) PagamentoIncassato::query()
                ->where('tenant_id', $tenantId)
                ->sum('importo_cent'),
        );
    }

    /** Quanto ha speso questa persona, dall'inizio. */
    public function totaleDellaPersona(User $utente): int
    {
        return (int) $this->context->runWithoutTenant(
            fn (): int => (int) PagamentoIncassato::query()
                ->where('user_id', $utente->getKey())
                ->sum('importo_cent'),
        );
    }

    /**
     * Gli incassi mese per mese, per i grafici.
     *
     * @return array<string, int> `YYYY-MM` → centesimi
     */
    public function perMese(Tenant|int|null $tenant = null, int $mesi = 12): array
    {
        $da = Carbon::now()->subMonths($mesi - 1)->startOfMonth();

        return $this->context->runWithoutTenant(function () use ($tenant, $da): array {
            $query = PagamentoIncassato::query()
                ->where('pagato_il', '>=', $da)
                ->selectRaw("DATE_FORMAT(pagato_il, '%Y-%m') as mese, SUM(importo_cent) as totale")
                ->groupBy('mese')
                ->orderBy('mese');

            if ($tenant !== null) {
                $query->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant);
            }

            return $query->pluck('totale', 'mese')->map(static fn ($v): int => (int) $v)->all();
        });
    }
}
