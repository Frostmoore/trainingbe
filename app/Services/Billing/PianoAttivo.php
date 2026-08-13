<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Qual è il piano in corso di una persona — F4 della Parte B.
 *
 * 🚨 **È il punto unico da cui passa la domanda «questa persona ha diritto
 * all'AI?»**. Due implementazioni della stessa regola divergono sempre: è la
 * trappola già pagata con `TenantAiQuota`, e il motivo per cui questa classe
 * esiste invece di due `if` in due controller.
 *
 * ── ⚠️ Perché il piano si legge dal TENANT e non dall'utente ───────────────
 *
 * Perché ogni utente ne ha uno: gli iscritti a una palestra hanno quello della
 * palestra — ed è la palestra a pagare — e chi si è iscritto da solo ha il suo
 * tenant personale (F1). Un abbonamento per utente moltiplicherebbe le righe
 * per gli iscritti di una palestra, che invece sono già coperti.
 *
 * ── 💡 Il piano di riserva, e perché non è `null` ─────────────────────────
 *
 * Chi non ha nessun abbonamento **non resta senza piano**: ricade su
 * `Plan::FREE`. Restituire `null` avrebbe voluto dire che ogni chiamante deve
 * ricordarsi di trattare quel caso, e chi se lo dimentica scriverebbe
 * `$piano->ai_enabled` su `null` — cioè un errore fatale nel percorso dell'AI,
 * oppure, peggio, un `?->ai_enabled` che vale `null` e passa per «falso» solo
 * per fortuna.
 *
 * 🚨 E se nemmeno il piano `free` esistesse a database — installazione nuova,
 * seeder non girato — si restituisce un piano **costruito al volo e non
 * salvato**, con `ai_enabled = false`. ⚠️ La direzione dell'errore è quella
 * giusta: un'installazione mal configurata deve **negare** l'AI, non regalarla.
 */
class PianoAttivo
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Il piano in corso per questa persona. Mai `null`.
     */
    public function per(User $utente): Plan
    {
        $tenantId = $utente->tenant_id;

        if ($tenantId === null) {
            /*
             * ⚠️ Il super admin non ha tenant, quindi non ha abbonamento.
             * Ricade sul gratuito, e va benissimo: il pannello `/god` non usa
             * l'AI, e se un giorno la usasse dovrebbe essere una decisione
             * esplicita e non un effetto collaterale di questa riga.
             */
            return $this->piadinoDiRiserva();
        }

        /*
         * 🚨 `runWithoutTenant` e non il contesto corrente.
         *
         * `PlanSubscription` usa `BelongsToTenant`, quindi la sua query è
         * filtrata dal tenant **del contesto**. Questo metodo però viene
         * chiamato anche da posti dove il contesto è vuoto o è un altro — un
         * comando artisan, un job in coda, il pannello `/god` — e lì la ricerca
         * non troverebbe niente e la persona risulterebbe senza piano.
         *
         * ⚠️ Il filtro non si perde: si rimette **a mano** con `where('tenant_id')`.
         * Non è un bypass dello scoping, è lo stesso filtro applicato in modo
         * esplicito invece che ambientale.
         */
        $abbonamento = $this->context->runWithoutTenant(
            fn (): ?PlanSubscription => PlanSubscription::query()
                ->where('tenant_id', $tenantId)
                ->attivi()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first(),
        );

        return $abbonamento?->plan ?? $this->piadinoDiRiserva();
    }

    /** L'AI è compresa nel piano di questa persona? */
    public function haLaAi(User $utente): bool
    {
        return $this->per($utente)->ai_enabled;
    }

    /**
     * Il piano gratuito, o un piano finto che nega tutto.
     *
     * 🚨 Il nome è volutamente buffo perché questo metodo va letto: qui si
     * decide cosa succede a un'installazione **mal configurata**, ed è l'unico
     * punto in cui la risposta può essere inventata. La direzione è sempre la
     * stessa — **negare**, mai concedere.
     */
    private function piadinoDiRiserva(): Plan
    {
        $free = $this->context->runWithoutTenant(
            fn (): ?Plan => Plan::query()->where('code', Plan::FREE)->first(),
        );

        return $free ?? new Plan([
            'code' => Plan::FREE,
            'name' => 'Gratuito',
            'ai_enabled' => false,
        ]);
    }
}
