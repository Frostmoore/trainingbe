<?php

declare(strict_types=1);

namespace App\Services\Ai\Quota;

use App\Models\AiUsageLog;
use App\Models\Tenant;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use Illuminate\Support\Carbon;

/**
 * Il tetto mensile di token per palestra — B6.5.
 *
 * 🚨 **Il limite si controlla PRIMA della chiamata, non dopo.** Controllarlo
 * dopo vorrebbe dire aver gia' pagato i token che si sta rifiutando di
 * concedere: il tetto servirebbe a dire «hai sforato», non a impedire di
 * sforare.
 *
 * Il tetto e' per **palestra**, non per utente, perche' e' la palestra che paga:
 * un limite per iscritto renderebbe il costo della piattaforma proporzionale al
 * numero di persone invece che all'abbonamento venduto.
 */
class TenantAiQuota
{
    /**
     * Il tetto di questa palestra.
     *
     * `null` significa **nessun limite**, ed e' diverso da «non impostato»: chi
     * non ha mai avuto un valore prende il default di configurazione, chi ha
     * `null` esplicito e' stato sbloccato apposta. Per distinguerli senza una
     * seconda colonna, `ai_monthly_token_cap = 0` vale «illimitato».
     */
    public function capFor(Tenant $tenant): ?int
    {
        $cap = $tenant->ai_monthly_token_cap;

        if ($cap === null) {
            $default = (int) config('ai.quota.default_monthly_tokens');

            return $default > 0 ? $default : null;
        }

        return $cap > 0 ? $cap : null;
    }

    public function usedThisMonth(Tenant $tenant, ?Carbon $month = null): int
    {
        return AiUsageLog::tokensForTenant((int) $tenant->getKey(), $month);
    }

    /** Quanti token restano. `null` = illimitato. */
    public function remaining(Tenant $tenant): ?int
    {
        $cap = $this->capFor($tenant);

        if ($cap === null) {
            return null;
        }

        return max(0, $cap - $this->usedThisMonth($tenant));
    }

    public function hasQuotaLeft(Tenant $tenant): bool
    {
        $rimasti = $this->remaining($tenant);

        return $rimasti === null || $rimasti > 0;
    }

    /**
     * @throws AiQuotaExceededException
     */
    public function assertWithinQuota(Tenant $tenant): void
    {
        if ($this->hasQuotaLeft($tenant)) {
            return;
        }

        throw new AiQuotaExceededException(
            resetsAt: Carbon::now()->addMonthNoOverflow()->startOfMonth(),
            capTokens: $this->capFor($tenant),
        );
    }

    /**
     * La percentuale consumata, per la dashboard.
     *
     * `null` quando non c'e' tetto: mostrare «0%» a chi e' illimitato darebbe
     * l'impressione di avere un limite enorme invece di non averne.
     */
    public function usedPercent(Tenant $tenant): ?float
    {
        $cap = $this->capFor($tenant);

        if ($cap === null || $cap === 0) {
            return null;
        }

        return round(min(100, $this->usedThisMonth($tenant) / $cap * 100), 1);
    }
}
