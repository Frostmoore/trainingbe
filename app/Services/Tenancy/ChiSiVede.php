<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chi vede chi — la regola, scritta in un posto solo. 15/09/2026.
 *
 * ══ 📌 LA REGOLA, DETTA DAL COMMITTENTE ═══════════════════════════════════
 *
 * *«Ogni trainer di palestra vede tutti gli allievi di quella palestra, sempre,
 * senza assegnazioni, e tutti gli allievi di quella palestra vedono tutti i
 * trainer di quella palestra. Un trainer indipendente vede i suoi allievi e i
 * suoi allievi vedono lui. Un trainer indipendente che lavora anche in una
 * palestra vede sia i suoi allievi personali — che devono comunque essere
 * limitati al suo livello di abbonamento — che tutti gli allievi della
 * palestra, e tutti gli allievi della palestra più tutti gli allievi del
 * trainer individuale possono vedere lui.»*
 *
 * ══ 🚨 COSA C'ERA PRIMA, E PERCHE' ERA ROTTO ══════════════════════════════
 *
 * ⛔ **Un trainer di palestra non vedeva nessuno.** `/trainer/members` leggeva
 * solo `trainer_member`, cioe' i rapporti uno-a-uno che nascono quando un
 * trainer **indipendente** invita qualcuno. Per un trainer di palestra quella
 * tabella e' vuota, e resta vuota: nessuno crea quelle righe, perche' gli
 * iscritti della palestra sono di tutti i trainer.
 *
 * 🚨 Provato su staging il 15/09: in una palestra con tre iscritti,
 * `/trainer/members` rispondeva `[]` e `/conversations/contacts` mostrava
 * soltanto l'altro trainer. **Un trainer che non vede nessuno e non puo'
 * scrivere a nessuno.**
 *
 * ⚠️ E il messaggio di errore diceva una cosa falsa — *«gli iscritti della
 * palestra te li assegna la palestra»* — quando non glieli assegna nessuno.
 *
 * ══ 💡 LE DUE SORGENTI, E PERCHE' SONO DUE ════════════════════════════════
 *
 * | Sorgente | Chi lega | Dove vive |
 * |---|---|---|
 * | **La palestra** | ogni trainer ↔ ogni iscritto, senza eccezioni | i ruoli, in `model_has_roles.tenant_id` |
 * | **Il rapporto personale** | un trainer ↔ un suo allievo | `trainer_member` |
 *
 * 🚨 **Il tetto dei posti vale solo sulla seconda.** Gli iscritti di una
 * palestra li paga la palestra col suo piano; gli allievi personali li paga il
 * trainer col suo. Contarli insieme farebbe pagare due volte lo stesso posto.
 *
 * ⚠️ **`tenant_id` della persona non basta a dire dove allena.** Un trainer con
 * un giro suo **resta nel proprio spazio** (AH.1-quater) e ha il ruolo `trainer`
 * dentro la palestra: e' il ruolo a dirlo, non la colonna.
 */
final class ChiSiVede
{
    /**
     * Le palestre in cui questa persona **allena**.
     *
     * 🚨 Si guardano i **ruoli per tenant**, non `users.tenant_id`: chi ha un
     * portfolio proprio sta nel suo spazio e allena altrove.
     *
     * @return Collection<int, int> gli id dei tenant
     */
    public function palestreDoveAllena(User $trainer): Collection
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('tenants', 'tenants.id', '=', 'model_has_roles.tenant_id')
            ->where('model_has_roles.model_id', $trainer->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', UserRole::Trainer->value)
            /*
             * ⚠️ **Solo le palestre vere.** Un `trainer` dentro un tenant
             * personale non esiste come caso — li' si e' `free_trainer` — ma se
             * ci finisse, trattarlo come una palestra vorrebbe dire mostrargli
             * tutti gli abitanti di quello spazio.
             */
            ->whereNull('tenants.deleted_at')
            ->pluck('model_has_roles.tenant_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * 🆕 **Gli spazi di cui questa persona fa parte** — 16/09/2026.
     *
     * 💡 Il suo, piu' le palestre dove allena. ⚠️ Per quasi tutti e' un elenco
     * di uno solo: il proprio tenant. Per chi porta due cappelli sono due, ed e'
     * la ragione per cui questo metodo esiste.
     *
     * 🚨 **Serve dove il `tenant_id` veniva usato come cintura di sicurezza** —
     * `Conversation::scopeForUser()` e `ConversationPolicy` — e dove quella
     * cintura, da sola, tagliava fuori una persona dalle proprie conversazioni:
     * il filo con un iscritto della palestra vive **nella palestra**, e lui la
     * guarda dal proprio spazio.
     *
     * ⚖️ **Non allarga niente a nessun altro**: chi non ha ruoli altrove
     * ottiene esattamente quello che otteneva prima, il proprio tenant. E chi
     * esce da una palestra perde il ruolo, quindi la perde anche da qui.
     *
     * @return Collection<int, int> gli id dei tenant
     */
    public function spaziDi(User $persona): Collection
    {
        return $this->palestreDoveAllena($persona)
            ->push($persona->tenant_id)
            ->merge($this->doveAppartiene($persona))
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * 🆕 🏠 **Gli spazi a cui questa persona appartiene** — F2.3 di
     * `memory/plan_spazi_personali.md`, 27/09/2026.
     *
     * ══ 🚨 PERCHE' SI AGGIUNGE INVECE DI SOSTITUIRE ═══════════════════════
     *
     * ⛔ Fino a oggi «di quali spazi fai parte» si deduceva da `users.tenant_id`
     * e dai ruoli — cioè dal fatto che la persona **ci abitasse dentro**, dopo
     * il trasloco. 🚨 Quando il trasloco sparirà (F2.3) quella deduzione
     * smetterà di funzionare, e la risposta dovrà venire da `appartenenze`.
     *
     * 💡 **Oggi le due fonti dicono la stessa cosa** — la tabella è stata
     * riempita da lì. Aggiungendo questa senza togliere quella, se la suite
     * resta verde è la **prova** che la tabella descrive il mondo com'è. ⚠️ È
     * il momento buono per fare il passaggio: dopo, le due fonti divergono e
     * confrontarle non dimostra più niente.
     *
     * ⚖️ **Non allarga niente**: restituisce solo appartenenze **vive** di
     * questa persona. ⛔ Chi è uscito da una palestra ha la riga chiusa e non
     * la vede più.
     *
     * @return Collection<int, int>
     */
    public function doveAppartiene(User $persona): Collection
    {
        return DB::table('appartenenze')
            ->join('tenants', 'tenants.id', '=', 'appartenenze.tenant_id')
            ->where('appartenenze.user_id', $persona->getKey())
            ->whereNull('appartenenze.finita_il')
            // ⚠️ Una palestra cancellata non è uno spazio in cui si sta.
            ->whereNull('tenants.deleted_at')
            ->pluck('appartenenze.tenant_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Gli allievi che questo trainer vede: i suoi, piu' quelli delle palestre in
     * cui allena.
     *
     * @return Collection<int, User>
     */
    public function allieviDi(User $trainer): Collection
    {
        /*
         * 🆕 ⛔ **Chi ha liberato il posto non lo ritrova fra i suoi** —
         * 22/09/2026 (Q-A5-2). Restava in elenco con «Riapri la conversazione»:
         * un rapporto che l'allievo non aveva chiesto, riaperto da una parte
         * sola. 📌 *«se vuole reinvitarlo gli deve mandare un link di invito»*.
         * ⚠️ Se è ancora iscritto in una palestra dove il trainer allena, lo
         * rivede qui sotto come iscritto della palestra: è lì che lo segue.
         */
        $suoi = $trainer->assignedMembers()
            ->where(fn ($q) => LoSlotDelTrainer::nonLiberati($q))
            ->get();

        $palestre = $this->palestreDoveAllena($trainer);

        if ($palestre->isEmpty()) {
            return $suoi->values();
        }

        return $suoi
            ->merge($this->conRuoloNeiTenant(UserRole::Member, $palestre, $trainer))
            ->unique('id')
            ->values();
    }

    /**
     * I trainer che questa persona vede: i suoi, piu' tutti quelli della
     * palestra di cui fa parte.
     *
     * @return Collection<int, User>
     */
    public function trainerDi(User $membro): Collection
    {
        /*
         * 🆕 ⛔ Il trainer che ha liberato il posto non è più «il tuo trainer» —
         * 22/09/2026 (Q-A5-2), lo specchio di [allieviDi]. ⚠️ E da qui dipende
         * anche la chat (`CancelloDellaChat::ancoraLegati`): fuori da una
         * palestra comune tornano i tre messaggi.
         */
        $suoi = $membro->assignedTrainers()
            ->where(fn ($q) => LoSlotDelTrainer::nonLiberati($q))
            ->get();

        $palestra = $membro->tenant;

        if ($palestra === null || $palestra->ePersonale()) {
            return $suoi->values();
        }

        return $suoi
            ->merge($this->conRuoloNeiTenant(
                UserRole::Trainer,
                collect([$palestra->getKey()]),
                $membro,
            ))
            ->unique('id')
            ->values();
    }

    /**
     * Chi ha quel ruolo **dentro quei tenant**, chiunque sia il suo.
     *
     * 🚨 `withoutGlobalScopes` e il join a mano su `model_has_roles`: un trainer
     * con un portfolio proprio ha `users.tenant_id` che punta al **suo** spazio,
     * quindi lo scope del tenant lo escluderebbe dalla palestra in cui allena —
     * ed e' proprio la persona che questa regola esiste per includere.
     *
     * @param  Collection<int, int>  $tenant
     * @return Collection<int, User>
     */
    private function conRuoloNeiTenant(UserRole $ruolo, Collection $tenant, User $tranne): Collection
    {
        return User::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->whereKeyNot($tranne->getKey())
            ->where('is_active', true)
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', $ruolo->value)
                ->where('model_has_roles.model_type', User::class)
                ->whereIn('model_has_roles.tenant_id', $tenant->all())
                ->pluck('model_has_roles.model_id'))
            ->get();
    }
}
