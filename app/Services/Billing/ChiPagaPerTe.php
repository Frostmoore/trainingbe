<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * 💰 **Chi può pagare l'abbonamento di questa persona** — F1.1 di
 * `memory/plan_spazi_personali.md`, 26/09/2026.
 *
 * ══ 🚨 IL DIFETTO CHE QUESTA CLASSE CHIUDE ════════════════════════════════
 *
 * 📌 Il committente: *«il mio abbonamento deve rimanere attivo fintanto che
 * anche uno solo di questi enti paga per il mio abbonamento»*.
 *
 * ⛔ Prima `PianoAttivo::per()` guardava **il solo `$utente->tenant_id`**: una
 * scatola sola. 🚨 Quindi una persona seguita da due trainer perdeva le
 * funzioni se smetteva di pagare **quello a cui puntava il suo `tenant_id`** —
 * anche se l'altro pagava regolarmente. È dimostrato da
 * `tests/Feature/Billing/DueTrainerUnoPagaTest.php`.
 *
 * 💡 Qui si risponde alla domanda giusta: **chi paga per te**, al plurale.
 *
 * ══ ⚠️ PERCHE' SI LEGGE FUORI DAL GLOBAL SCOPE ════════════════════════════
 *
 * 🚨 I trainer stanno in **altri** spazi. Dentro `TenantScope` non si
 * vedrebbero proprio — ed è **esattamente il difetto** che si sta chiudendo:
 * lo scope serve a impedire a una palestra di vedere gli iscritti di un'altra,
 * ⛔ non a impedire a una persona di sapere chi le sta pagando l'abbonamento.
 *
 * 🔑 **Questa classe non restituisce dati**, solo **id di tenant**: non è una
 * fessura nell'isolamento, perché con un id non si legge niente. Chi poi
 * interroga gli abbonamenti resta responsabile di guardare **solo** quelli.
 */
class ChiPagaPerTe
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Gli id dei tenant che possono pagare per questa persona.
     *
     * 🔑 Nell'ordine: **il proprio spazio** (che oggi è anche quello della
     * palestra, finché F2 non separa le due cose) e **ogni trainer che la
     * segue**.
     *
     * ⚠️ Senza duplicati, e senza `null`: chi chiama deve poter fare un
     * `whereIn` senza pensarci.
     *
     * @return list<int>
     */
    public function tenantIds(User $utente): array
    {
        $suo = $utente->tenant_id;

        $deiTrainer = $this->context->runWithoutTenant(
            fn (): array => DB::table('trainer_member')
                ->where('member_id', $utente->getKey())
                /*
                 * ⚠️ **Solo le assegnazioni vive.** ⛔ Un trainer che ti ha
                 * messo da parte (`disattivato_il`) non ti sta più pagando
                 * niente: contarlo vorrebbe dire regalare l'abbonamento a chi
                 * non lo usa più.
                 */
                ->whereNull('disattivato_il')
                ->pluck('tenant_id')
                ->all(),
        );

        $tutti = array_merge($suo === null ? [] : [$suo], $deiTrainer);

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($tutti, static fn (mixed $id): bool => $id !== null),
        )));
    }
}
