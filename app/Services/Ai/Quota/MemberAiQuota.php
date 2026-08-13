<?php

declare(strict_types=1);

namespace App\Services\Ai\Quota;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Billing\PianoAttivo;
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
     * ── 🚨 Cinque livelli, dal piu' specifico al piu' generale — F4.3, D3 ──
     *
     * | # | Livello | Chi lo decide |
     * |---|---|---|
     * | 1 | `users.ai_monthly_token_cap` | l'eccezione per **una persona** |
     * | 2 | `tenants.ai_monthly_tokens_per_member` | **la palestra**, se ce n'e' una |
     * | 3 | il trainer indipendente che l'ha invitata | solo se non c'e' una palestra |
     * | 4 | `plans.ai_monthly_tokens_per_member` | il **piano** in corso |
     * | 5 | `ai.quota.default_monthly_tokens_per_user` | il default di sistema |
     *
     * 🚨 In **tutti** i livelli, `0` vale «illimitato» e `null` vale «non
     * impostato, scendi al livello successivo». Sono due cose diverse: senza la
     * distinzione non si potrebbe sbloccare una persona sola lasciando il
     * default a tutte le altre — si potrebbe solo alzare il tetto a tutti.
     *
     * ⚠️ E proprio per questo **qui dentro non si decide se l'AI spetti**: non
     * esiste un valore che significhi «niente AI». Quella domanda ha un cancello
     * suo, `RequirePlanWithAi`, che gira **prima** (D2).
     *
     * ── 🚨 La palestra PRIMA del trainer, non viceversa ────────────────────
     *
     * E' la decisione D3, e il motivo va tenuto a mente perche' l'ordine
     * inverso sembrerebbe altrettanto sensato: se un iscritto di una palestra si
     * fa seguire **anche** da un trainer indipendente, non deve poter drenare il
     * monte token di quel trainer — che se lo paga di tasca sua per i **suoi**
     * utenti. Chi paga per quella persona e' la palestra, e il tetto e' suo.
     *
     * 💡 Il livello 3 si guarda **solo** se il tenant non e' una palestra: e' la
     * stessa condizione detta al contrario, e tenerla esplicita evita che
     * qualcuno, un giorno, «semplifichi» togliendo il controllo su `ePersonale()`.
     */
    public function capFor(User $utente): ?int
    {
        $suo = $utente->ai_monthly_token_cap;

        if ($suo !== null) {
            return $suo > 0 ? $suo : null;
        }

        $tenant = $utente->tenant;

        // 2. La palestra. ⚠️ Solo se e' una palestra vera: il tetto di un tenant
        // personale sarebbe il tetto che una persona si e' data da sola.
        if ($tenant !== null && ! $tenant->ePersonale()) {
            $dallaPalestra = $tenant->ai_monthly_tokens_per_member;

            if ($dallaPalestra !== null) {
                return $dallaPalestra > 0 ? $dallaPalestra : null;
            }
        }

        // 3. Il trainer indipendente che l'ha invitata, se non c'e' una palestra.
        $dalTrainer = $this->tettoDalTrainerIndipendente($utente);

        if ($dalTrainer !== null) {
            return $dalTrainer > 0 ? $dalTrainer : null;
        }

        // 4. Il piano in corso.
        $dalPiano = app(PianoAttivo::class)->per($utente)->ai_monthly_tokens_per_member;

        if ($dalPiano !== null) {
            return $dalPiano > 0 ? $dalPiano : null;
        }

        // 5. Il default di sistema.
        $default = (int) config('ai.quota.default_monthly_tokens_per_user');

        return $default > 0 ? $default : null;
    }

    /**
     * Il tetto che arriva dal trainer indipendente che segue questa persona.
     *
     * 🚨 **Solo per chi non ha una palestra.** Se ce l'ha, il livello 2 ha gia'
     * risposto e questo metodo non viene nemmeno interrogato — ma la condizione
     * resta scritta anche qui, perche' il giorno in cui qualcuno riordinasse i
     * livelli il vincolo non deve dipendere dall'ordine.
     *
     * ⚠️ Se piu' di un trainer indipendente segue la stessa persona si prende il
     * tetto **piu' alto**: e' l'unica scelta che non danneggia nessuno dei due —
     * quello piu' generoso ha gia' deciso di concedere quel tetto, e prendere il
     * piu' basso vorrebbe dire che farsi seguire da un secondo trainer
     * *peggiora* il servizio.
     */
    private function tettoDalTrainerIndipendente(User $utente): ?int
    {
        $tenant = $utente->tenant;

        if ($tenant === null || ! $tenant->ePersonale()) {
            return null;
        }

        $tetti = $utente->assignedTrainers()
            ->get()
            ->map(static fn (User $t): ?int => $t->ai_monthly_token_cap)
            ->filter(static fn (?int $v): bool => $v !== null);

        if ($tetti->isEmpty()) {
            return null;
        }

        // 💡 Uno `0` (illimitato) vince su qualunque numero: e' il tetto piu'
        // alto che esista, e ordinarlo come «zero» lo farebbe perdere.
        if ($tetti->contains(0)) {
            return 0;
        }

        return (int) $tetti->max();
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
