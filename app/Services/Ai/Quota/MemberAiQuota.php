<?php

declare(strict_types=1);

namespace App\Services\Ai\Quota;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use Illuminate\Support\Carbon;

/**
 * Il tetto mensile di token **di ciascun iscritto** — C20.
 *
 * 🚨 **Il limite si controlla PRIMA della chiamata, non dopo.** Controllarlo
 * dopo vorrebbe dire aver gia' pagato i token che si sta rifiutando di
 * concedere: il tetto servirebbe a dire «hai sforato», non a impedire di
 * sforare.
 *
 * 🚨 **Per iscritto e non per palestra, ed e' un cambio di rotta voluto.**
 * Il tetto per palestra era un pozzo comune: con 2 milioni di token a palestra
 * e un consumo medio di 551.000 a testa, bastava per tre o quattro persone, e
 * la quarta restava senza AI per il consumo di qualcun altro. Chi consumava di
 * piu' non pagava di piu': pagavano gli altri, restando fuori. Con un tetto di
 * ciascuno, un utente pesante esaurisce **il proprio** e nessun altro se ne
 * accorge.
 *
 * ⚠️ La palestra continua a pagare il conto, ma lo governa decidendo **quanto
 * dare a ognuno** (`tenants.ai_monthly_tokens_per_member`), che e' una leva
 * prevedibile: il costo massimo di un mese e' iscritti × tetto, un numero che
 * si puo' calcolare prima di venderlo.
 */
class MemberAiQuota
{
    /**
     * Il tetto di questa persona, in token al mese. `null` = illimitato.
     *
     * Tre livelli, dal piu' specifico al piu' generale:
     *
     *   1. `users.ai_monthly_token_cap` — l'eccezione per una persona;
     *   2. `tenants.ai_monthly_tokens_per_member` — la scelta della palestra;
     *   3. `ai.quota.default_monthly_tokens_per_user` — il default di sistema.
     *
     * 🚨 In tutti e tre, **`0` vale «illimitato»** e `null` vale «non
     * impostato, scendi al livello successivo». Sono due cose diverse: senza la
     * distinzione non si potrebbe sbloccare una persona sola lasciando il
     * default a tutte le altre — si potrebbe solo alzare il tetto a tutti.
     */
    public function capFor(User $utente): ?int
    {
        $suo = $utente->ai_monthly_token_cap;

        if ($suo !== null) {
            return $suo > 0 ? $suo : null;
        }

        $dallaPalestra = $utente->tenant?->ai_monthly_tokens_per_member;

        if ($dallaPalestra !== null) {
            return $dallaPalestra > 0 ? $dallaPalestra : null;
        }

        $default = (int) config('ai.quota.default_monthly_tokens_per_user');

        return $default > 0 ? $default : null;
    }

    public function usedThisMonth(User $utente, ?Carbon $month = null): int
    {
        return AiUsageLog::tokensForUser((int) $utente->getKey(), $month);
    }

    /** Quanti token restano. `null` = illimitato. */
    public function remaining(User $utente): ?int
    {
        $cap = $this->capFor($utente);

        if ($cap === null) {
            return null;
        }

        return max(0, $cap - $this->usedThisMonth($utente));
    }

    public function hasQuotaLeft(User $utente): bool
    {
        $rimasti = $this->remaining($utente);

        return $rimasti === null || $rimasti > 0;
    }

    /**
     * @throws AiQuotaExceededException
     */
    public function assertWithinQuota(User $utente): void
    {
        if ($this->hasQuotaLeft($utente)) {
            return;
        }

        throw new AiQuotaExceededException(
            resetsAt: Carbon::now()->addMonthNoOverflow()->startOfMonth(),
            capTokens: $this->capFor($utente),
        );
    }

    /**
     * La percentuale consumata, per la dashboard.
     *
     * `null` quando non c'e' tetto: mostrare «0%» a chi e' illimitato darebbe
     * l'impressione di avere un limite enorme invece di non averne.
     */
    public function usedPercent(User $utente): ?float
    {
        $cap = $this->capFor($utente);

        if ($cap === null || $cap === 0) {
            return null;
        }

        return round(min(100, $this->usedThisMonth($utente) / $cap * 100), 1);
    }
}
