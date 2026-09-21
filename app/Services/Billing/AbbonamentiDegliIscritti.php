<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AbbonamentoTolto;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\IContiDellaPalestra;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ✂️ **L'abbonamento che la palestra dà — o toglie — a un suo iscritto** —
 * 23/09/2026, F5.
 *
 * 📌 Il committente, sull'elenco degli iscritti: *«mi serve il tasto per
 * togliere l'abbonamento all'allievo»* · *«mi serve la revenue totale
 * dall'allievo (quindi prezzo fatto all'allievo meno costo dello slot tutto per
 * tutti i rinnovi dell'abbonamento pagati dall'allievo)»*. E sulla sua pagina:
 * *«un grafico con gli abbonamenti nel tempo»* e *«un grafico con la revenue da
 * quell'allievo nel tempo (stessi criteri del numero in pagina principale)»*.
 *
 * ══ 🚨 COME FUNZIONA, IN TRE RIGHE ══════════════════════════════════════
 *
 * 1. Un iscritto di palestra è **coperto** finché non c'è un periodo aperto in
 *    `abbonamenti_tolti`. Nessuna riga = coperto, com'è sempre stato.
 * 2. **Tolto** vuol dire: resta in palestra, ma ricade sul **gratuito**
 *    (`PianoAttivo::per()`) e **non occupa più un posto pagato**
 *    (`ContoDegliIscritti`).
 * 3. La **revenue** è `(quota − costo) × mesi coperti`.
 *
 * ══ ⚠️ LA REVENUE È UNA STIMA, E VA DETTO OGNI VOLTA ════════════════════
 *
 * 📌 *«per tutti i rinnovi dell'abbonamento pagati dall'allievo»*. ⛔ Quei
 * pagamenti **non passano da noi**: l'allievo paga la palestra, in contanti o
 * col suo POS. 💡 Quello che sappiamo è **quanti mesi era coperto**, e ogni
 * mese coperto è un rinnovo — che si assume pagato.
 *
 * 🚨 E il **costo** è quello di **oggi**: se il piano è cambiato sei mesi fa,
 * quei mesi si contano al prezzo nuovo. ⏳ Per il costo storico servirebbe la
 * storia dei piani mese per mese, che `plan_subscriptions` ha — è il debito
 * dichiarato di questa classe.
 */
class AbbonamentiDegliIscritti
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly IContiDellaPalestra $conti,
    ) {}

    /** ✅ L'iscritto ha l'abbonamento della sua palestra, adesso? */
    public function haLAbbonamento(User $iscritto): bool
    {
        return ! $this->eTolto($iscritto->tenant_id, $iscritto->getKey());
    }

    /**
     * La domanda che si fa `PianoAttivo::per()` a ogni chiamata: per questo
     * prende gli id e non i modelli, e fa **una** query sull'indice.
     */
    public function eTolto(?int $tenantId, int $userId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return $this->context->runWithoutTenant(
            fn (): bool => AbbonamentoTolto::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->aperti()
                ->exists(),
        );
    }

    /**
     * ✂️ Toglie l'abbonamento.
     *
     * ⚠️ **Idempotente**: se è già tolto non apre un secondo periodo. ⛔ Due
     * periodi aperti insieme conterebbero lo stesso mese due volte fra quelli
     * scoperti, e la revenue andrebbe sotto il vero.
     */
    public function togli(User $iscritto, ?User $chi = null): void
    {
        if (! $this->haLAbbonamento($iscritto)) {
            return;
        }

        $this->context->runWithoutTenant(fn () => AbbonamentoTolto::query()->create([
            'tenant_id' => $iscritto->tenant_id,
            'user_id' => $iscritto->getKey(),
            'dal' => Carbon::now(),
            'tolto_da_id' => $chi?->getKey(),
        ]));
    }

    /** ✅ Glielo ridà: chiude il periodo aperto. */
    public function ridai(User $iscritto, ?User $chi = null): void
    {
        $this->context->runWithoutTenant(fn () => AbbonamentoTolto::query()
            ->where('tenant_id', $iscritto->tenant_id)
            ->where('user_id', $iscritto->getKey())
            ->aperti()
            ->update([
                'al' => Carbon::now(),
                'ridato_da_id' => $chi?->getKey(),
                'updated_at' => Carbon::now(),
            ]));
    }

    /**
     * 📅 **Da quando sta in questa palestra.**
     *
     * 💡 La data dell'invito accettato, se è entrato così; altrimenti quella
     * del suo account. ⛔ Solo `created_at` sbaglierebbe per chi aveva già un
     * account personale da un anno e poi è entrato in palestra: gli si
     * attribuirebbe un anno di rinnovi mai esistiti.
     */
    public function inPalestraDal(User $iscritto): ?Carbon
    {
        $invito = $this->context->runWithoutTenant(
            fn () => DB::table('tenant_invites')
                ->where('tenant_id', $iscritto->tenant_id)
                ->where('accepted_by', $iscritto->getKey())
                ->whereNotNull('used_at')
                ->orderByDesc('used_at')
                ->value('used_at'),
        );

        if ($invito !== null) {
            return Carbon::parse($invito);
        }

        return $iscritto->created_at;
    }

    /**
     * 📊 **Coperto sì o no, mese per mese** — il grafico *«gli abbonamenti nel
     * tempo»*.
     *
     * ⚠️ Un mese conta come coperto se lo era **per almeno un giorno**: è il
     * mese in cui la palestra gli ha venduto l'abbonamento, anche se a metà
     * glielo ha tolto.
     *
     * @return array<string, bool> `YYYY-MM` → coperto
     */
    public function copertoPerMese(User $iscritto, int $mesi = IContiDellaPalestra::MESI): array
    {
        $dal = $this->inPalestraDal($iscritto);
        $tolti = $this->periodiTolti($iscritto);

        $serie = [];

        foreach ($this->conti->mesi($mesi) as $mese) {
            $inizio = Carbon::parse($mese.'-01')->startOfMonth();
            $fine = $inizio->copy()->endOfMonth();

            $serie[$mese] = $dal !== null
                && $dal->lte($fine)
                && ! $this->toltoTuttoIlMese($tolti, $inizio, $fine);
        }

        return $serie;
    }

    /**
     * 🔢 **Quanti mesi è stato coperto, dall'ingresso a oggi** — cioè quanti
     * rinnovi si assumono pagati.
     */
    public function mesiCoperti(User $iscritto): int
    {
        $dal = $this->inPalestraDal($iscritto);

        if ($dal === null) {
            return 0;
        }

        $tolti = $this->periodiTolti($iscritto);
        $cursore = $dal->copy()->startOfMonth();
        $oggi = Carbon::now()->endOfMonth();
        $mesi = 0;

        while ($cursore->lte($oggi)) {
            if (! $this->toltoTuttoIlMese($tolti, $cursore->copy(), $cursore->copy()->endOfMonth())) {
                $mesi++;
            }

            $cursore->addMonth();
        }

        return $mesi;
    }

    /**
     * 💶 **La revenue totale dall'iscritto**, in centesimi.
     *
     * ⚠️ `null` se la palestra non ha scritto la sua quota (D9): ⛔ un margine
     * calcolato su un prezzo mai scritto sarebbe «meno il costo», cioè un rosso
     * inventato da noi.
     */
    public function revenueCent(User $iscritto, ?Tenant $palestra = null): ?int
    {
        $palestra ??= $iscritto->tenant;
        $margine = $palestra === null ? null : $this->conti->marginePerAllievoCent($palestra);

        return $margine === null ? null : $margine * $this->mesiCoperti($iscritto);
    }

    /**
     * 📈 La revenue **cumulata**, mese per mese — il grafico *«la revenue da
     * quell'allievo nel tempo»*.
     *
     * 💡 Cumulata e non mensile: con una quota fissa, la serie mensile sarebbe
     * una riga piatta che dice solo «coperto sì / no», che l'altro grafico dice
     * già meglio.
     *
     * @return array<string, int> `YYYY-MM` → centesimi
     */
    public function revenuePerMese(User $iscritto, int $mesi = IContiDellaPalestra::MESI): array
    {
        $palestra = $iscritto->tenant;
        $margine = $palestra === null ? null : $this->conti->marginePerAllievoCent($palestra);

        $coperto = $this->copertoPerMese($iscritto, $mesi);

        /*
         * 🚨 **Il cumulato parte da quello che c'era prima della finestra**:
         * ⛔ altrimenti un iscritto di due anni avrebbe una curva che parte da
         * zero a gennaio, come se fosse arrivato ieri.
         */
        $prima = max(0, $this->mesiCoperti($iscritto) - count(array_filter($coperto)));
        $totale = $margine === null ? 0 : $prima * $margine;

        $serie = [];

        foreach ($coperto as $mese => $si) {
            $totale += ($si && $margine !== null) ? $margine : 0;
            $serie[$mese] = $totale;
        }

        return $serie;
    }

    // ───────────────────────── gli aiuti ─────────────────────────

    /** @return list<array{0: Carbon, 1: ?Carbon}> */
    private function periodiTolti(User $iscritto): array
    {
        return $this->context->runWithoutTenant(
            fn (): array => AbbonamentoTolto::query()
                ->where('tenant_id', $iscritto->tenant_id)
                ->where('user_id', $iscritto->getKey())
                ->orderBy('dal')
                ->get()
                ->map(fn (AbbonamentoTolto $t): array => [$t->dal, $t->al])
                ->all(),
        );
    }

    /**
     * Il mese è stato **tutto** senza abbonamento?
     *
     * @param  list<array{0: Carbon, 1: ?Carbon}>  $tolti
     */
    private function toltoTuttoIlMese(array $tolti, Carbon $inizio, Carbon $fine): bool
    {
        foreach ($tolti as [$dal, $al]) {
            $finoA = $al ?? Carbon::now()->addCentury();

            if ($dal->lte($inizio) && $finoA->gte($fine)) {
                return true;
            }
        }

        return false;
    }
}
