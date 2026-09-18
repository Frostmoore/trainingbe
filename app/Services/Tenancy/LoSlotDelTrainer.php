<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🎟️ **Lo slot del trainer quando l'allievo entra in palestra** — AH.1-ter.6
 * (18/09/2026).
 *
 * ══ 📌 LA REGOLA, DETTATA DAL COMMITTENTE ═════════════════════════════════
 *
 * > *«Si deve chiedere: se il trainer decide di mantenere lo slot occupato,
 * > l'allievo passa alla palestra, poi se si dissocia dalla palestra torna al
 * > trainer. Se il trainer decide di liberare lo slot, l'allievo passa alla
 * > palestra e se si dissocia da essa non torna al trainer.»*
 *
 * 💡 **Si paga per prenotarsi il ritorno.** Lo slot occupato non è una tassa, è
 * un'**opzione**: la si compra, o non la si compra.
 *
 * ══ 🚨 COSA C'ERA PRIMA ═══════════════════════════════════════════════════
 *
 * ⛔ Il posto lo pagavano **tutti e due**, e non l'aveva deciso nessuno: veniva
 * fuori da solo dal fatto che `trainer_member` resta indietro quando si entra
 * in una palestra. ⚠️ E non dava niente in più al trainer — quella persona la
 * vede comunque, perché è iscritta dove lui allena.
 *
 * ══ ⚠️ SOLO SE IL TRAINER LAVORA IN QUELLA PALESTRA ═══════════════════════
 *
 * 🚨 **È la condizione che fa la differenza fra una regola e un danno.** Se un
 * allievo entra in una palestra dove il suo trainer **non** lavora, il rapporto
 * personale non c'entra niente e non si tocca: sono due cose separate, e
 * sospenderlo vorrebbe dire rompere un rapporto che nessuno ha chiuso.
 *
 * 💡 La sovrapposizione esiste **solo** quando il trainer è dentro quella
 * palestra — ed è l'unico caso in cui il posto verrebbe pagato due volte.
 */
class LoSlotDelTrainer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ChiSiVede $chiSiVede,
    ) {}

    /**
     * L'allievo è appena entrato in una palestra: sospendi i rapporti con i
     * trainer che **lavorano lì**, e segnati che vanno interpellati.
     *
     * ⚠️ **Non chiude niente e non toglie nessuno slot.** Fino a che il trainer
     * non risponde, il posto resta suo: ⛔ liberarlo di default vorrebbe dire
     * decidere al posto suo la cosa che costa — e nella direzione che gli toglie
     * l'allievo.
     *
     * @return int quanti rapporti sono stati sospesi
     */
    public function sospendiPerIngressoInPalestra(User $allievo, Tenant $palestra): int
    {
        return $this->context->runWithoutTenant(function () use ($allievo, $palestra): int {
            $trainerDentro = $this->trainerDiQuestaPalestra($palestra);

            if ($trainerDentro->isEmpty()) {
                return 0;
            }

            $quanti = DB::table('trainer_member')
                ->where('member_id', $allievo->getKey())
                ->whereIn('trainer_id', $trainerDentro->all())
                ->whereNull('disattivato_il')
                ->whereNull('sospeso_il')
                ->update([
                    'sospeso_il' => Carbon::now(),
                    'sospeso_per_tenant_id' => $palestra->getKey(),

                    /*
                     * 🚨 **La decisione si azzera**, anche se c'era già.
                     *
                     * ⛔ Chi era già stato interpellato per un ingresso
                     * **precedente** in quella palestra deve essere interpellato
                     * di nuovo: è passato del tempo, e il committente lo ha
                     * chiesto esplicitamente per il caso «l'allievo torna in
                     * palestra» — *«al trainer appaia di nuovo la modale»*.
                     */
                    'slot_deciso_il' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($quanti > 0) {
                Log::info('Slot sospesi per ingresso in palestra', [
                    'allievo' => $allievo->getKey(),
                    'palestra' => $palestra->getKey(),
                    'quanti' => $quanti,
                ]);
            }

            return $quanti;
        });
    }

    /**
     * Le decisioni che questo trainer deve ancora prendere.
     *
     * ⛔ **Non c'è nessuna tabella di «domande in sospeso»**, ed è una scelta:
     * uno stato duplicato si disallinea da quello che descrive, e il giorno che
     * si disallinea si chiede due volte la stessa cosa o non si chiede affatto.
     * 💡 Le domande **sono** le righe sospese senza risposta.
     *
     * @return Collection<int, object{member_id: int, nome: string, palestra: string, sospeso_il: string}>
     */
    public function daDecidere(User $trainer): Collection
    {
        return $this->context->runWithoutTenant(
            fn (): Collection => collect(DB::table('trainer_member as tm')
                ->join('users as u', 'u.id', '=', 'tm.member_id')
                ->leftJoin('tenants as t', 't.id', '=', 'tm.sospeso_per_tenant_id')
                ->where('tm.trainer_id', $trainer->getKey())
                ->whereNotNull('tm.sospeso_il')
                ->whereNull('tm.slot_deciso_il')
                ->whereNull('tm.disattivato_il')
                /*
                 * ⚠️ Chi è stato cancellato o spento non si chiede: sarebbe una
                 * domanda su una persona che non c'è più.
                 */
                ->whereNull('u.deleted_at')
                ->where('u.is_active', true)
                ->orderBy('tm.sospeso_il')
                ->get([
                    'tm.member_id',
                    'u.name as nome',
                    't.name as palestra',
                    'tm.sospeso_il',
                ])),
        );
    }

    /**
     * Il trainer risponde: **tengo** lo slot, o lo **libero**.
     *
     * 🚨 **Tenere lo slot non fa niente di visibile, e va bene così.** La riga
     * resta sospesa e continua a contare il posto — `postiRimasti()` filtra su
     * `disattivato_il`, non su `sospeso_il`. 💡 Quello che cambia è il
     * **futuro**: uscendo dalla palestra quel rapporto si riaccende.
     *
     * ⛔ **Liberarlo invece chiude il rapporto**, e non torna più: è
     * precisamente quello che il committente ha chiesto — *«se si dissocia da
     * essa non torna al trainer»*.
     *
     * @return bool `false` se non c'era niente da decidere
     */
    public function decidi(User $trainer, User $allievo, bool $tengoLoSlot): bool
    {
        return $this->context->runWithoutTenant(function () use ($trainer, $allievo, $tengoLoSlot): bool {
            $riga = DB::table('trainer_member')
                ->where('trainer_id', $trainer->getKey())
                ->where('member_id', $allievo->getKey())
                ->whereNotNull('sospeso_il')
                ->whereNull('slot_deciso_il')
                ->whereNull('disattivato_il')
                ->first();

            if ($riga === null) {
                return false;
            }

            $ora = Carbon::now();

            DB::table('trainer_member')
                ->where('id', $riga->id)
                ->update($tengoLoSlot
                    ? ['slot_deciso_il' => $ora, 'updated_at' => $ora]
                    : [
                        'slot_deciso_il' => $ora,
                        'disattivato_il' => $ora,
                        'updated_at' => $ora,
                    ]);

            Log::info('Slot deciso dal trainer', [
                'trainer' => $trainer->getKey(),
                'allievo' => $allievo->getKey(),
                'tengo' => $tengoLoSlot,
            ]);

            return true;
        });
    }

    /**
     * L'allievo ha lasciato la palestra: riaccendi i rapporti che erano stati
     * sospesi **da quella** palestra.
     *
     * ⚠️ **Solo da quella**, e l'ha imposto il vincolo di `sospeso_per_tenant_id`:
     * riattivare tutto vorrebbe dire riaprire legami sospesi altrove, che
     * nessuno ha chiesto di riaprire.
     *
     * ⛔ E si riaccendono **solo** quelli non chiusi: chi ha liberato lo slot ha
     * `disattivato_il` valorizzato, e resta chiuso. 💡 È la metà della regola
     * che dà senso all'altra.
     *
     * @return int quanti rapporti sono tornati attivi
     */
    public function riattivaPerUscitaDallaPalestra(User $allievo, Tenant $palestra): int
    {
        return $this->context->runWithoutTenant(function () use ($allievo, $palestra): int {
            $quanti = DB::table('trainer_member')
                ->where('member_id', $allievo->getKey())
                ->where('sospeso_per_tenant_id', $palestra->getKey())
                ->whereNotNull('sospeso_il')
                ->whereNull('disattivato_il')
                ->update([
                    'sospeso_il' => null,
                    'sospeso_per_tenant_id' => null,
                    'slot_deciso_il' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($quanti > 0) {
                Log::info('Slot riattivati per uscita dalla palestra', [
                    'allievo' => $allievo->getKey(),
                    'palestra' => $palestra->getKey(),
                    'quanti' => $quanti,
                ]);
            }

            return $quanti;
        });
    }

    /**
     * Gli id dei trainer che lavorano in questa palestra.
     *
     * ⚠️ **Si legge dai ruoli spatie**, non da `users.tenant_id`: un trainer con
     * un portfolio suo lavora nella palestra **senza** avere lì il proprio
     * tenant, e contarlo dalla colonna lo lascerebbe fuori — cioè proprio il
     * caso per cui questa classe esiste.
     *
     * @return Collection<int, int>
     */
    private function trainerDiQuestaPalestra(Tenant $palestra): Collection
    {
        return collect(DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')
                    ->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('mhr.tenant_id', $palestra->getKey())
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->whereIn('r.name', [UserRole::Trainer->value, UserRole::FreeTrainer->value])
            ->distinct()
            ->pluck('mhr.model_id'))
            ->map(fn ($id): int => (int) $id);
    }
}
