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
 * ══ ⛔ QUESTA CLASSE NON SOSPENDE NESSUN RAPPORTO ═════════════════════════
 *
 * 🚨 **Va detto subito, perché il contrario è quello che sembra.** Nessun metodo
 * qui dentro interrompe il legame fra allievo e trainer: `assignedMembers()` e
 * `assignedTrainers()` non guardano `sovrapposto_dal`, quindi il trainer
 * continua a vedere il suo allievo e l'allievo il suo trainer.
 *
 * 📌 Il committente, 18/09, correggendo esattamente questo fraintendimento —
 * che era nato da un nome sbagliato che avevo dato a una colonna:
 *
 * > *«L'allievo di un free_trainer che si iscrive in una palestra non deve
 * > avere il rapporto col free_trainer sospeso: un allievo deve poter essere
 * > iscritto a infiniti free_trainer e una sola palestra, anche
 * > contemporaneamente. L'unico caso limite è se un allievo di un free trainer
 * > si iscrive in una palestra in cui lavora quel free trainer: allora il free
 * > trainer deve ricevere la modale.»*
 *
 * 💡 **L'unica cosa in ballo sono i soldi**: chi paga il posto. Il rapporto non
 * c'entra, e resta.
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
 * marcarlo vorrebbe dire fargli una domanda che non lo riguarda.
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
     * L'allievo è appena entrato in una palestra: **segna la sovrapposizione**
     * con i trainer che **lavorano lì**, e che vanno interpellati sullo slot.
     *
     * ⛔ **Non sospende, non chiude e non toglie nessuno slot.** Il rapporto
     * resta esattamente com'era: quello che si scrive è solo *«questa persona è
     * entrata in una palestra dove lavori anche tu, e c'è una domanda da farti
     * sul posto che stai pagando»*.
     *
     * ⚠️ Fino a che il trainer non risponde il posto resta suo: liberarlo di
     * default vorrebbe dire decidere al posto suo la cosa che costa — e nella
     * direzione che gli toglie l'allievo.
     *
     * @return int quante sovrapposizioni sono state segnate
     */
    public function segnalaLaSovrapposizione(User $allievo, Tenant $palestra): int
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
                ->whereNull('sovrapposto_dal')
                ->update([
                    'sovrapposto_dal' => Carbon::now(),
                    'sovrapposto_in_tenant_id' => $palestra->getKey(),

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
                Log::info('Sovrapposizione segnata: allievo entrato dove lavora il suo trainer', [
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
     * 💡 Le domande **sono** le righe sovrapposte senza risposta.
     *
     * @return Collection<int, object{member_id: int, nome: string, palestra: string, sovrapposto_dal: string}>
     */
    public function daDecidere(User $trainer): Collection
    {
        return $this->context->runWithoutTenant(
            fn (): Collection => collect(DB::table('trainer_member as tm')
                ->join('users as u', 'u.id', '=', 'tm.member_id')
                ->leftJoin('tenants as t', 't.id', '=', 'tm.sovrapposto_in_tenant_id')
                ->where('tm.trainer_id', $trainer->getKey())
                ->whereNotNull('tm.sovrapposto_dal')
                ->whereNull('tm.slot_deciso_il')
                ->whereNull('tm.disattivato_il')
                /*
                 * ⚠️ Chi è stato cancellato o spento non si chiede: sarebbe una
                 * domanda su una persona che non c'è più.
                 */
                ->whereNull('u.deleted_at')
                ->where('u.is_active', true)
                ->orderBy('tm.sovrapposto_dal')
                ->get([
                    'tm.member_id',
                    'u.name as nome',
                    't.name as palestra',
                    'tm.sovrapposto_dal',
                ])),
        );
    }

    /**
     * Il trainer risponde: **tengo** lo slot, o lo **libero**.
     *
     * 🚨 **Tenere lo slot non fa niente di visibile, e va bene così.** Il
     * rapporto era già intero e resta intero, e continua a contare il posto — `postiRimasti()` filtra su
     * `disattivato_il`, non su `sovrapposto_dal`. 💡 Quello che cambia è il
     * **futuro**: uscendo dalla palestra quell'allievo resta suo.
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
                ->whereNotNull('sovrapposto_dal')
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
     * L'allievo ha lasciato la palestra: **chiudi la sovrapposizione** nata
     * lì, e il rapporto torna a essere quello di prima.
     *
     * ⚠️ **Solo da quella palestra**, e l'ha imposto `sovrapposto_in_tenant_id`:
     * chiudere tutto vorrebbe dire rimettere in discussione slot decisi
     * altrove, che nessuno ha toccato.
     *
     * ⛔ E si riaccendono **solo** quelli non chiusi: chi ha liberato lo slot ha
     * `disattivato_il` valorizzato, e resta chiuso. 💡 È la metà della regola
     * che dà senso all'altra.
     *
     * @return int quante sovrapposizioni sono state chiuse
     */
    public function chiudiLaSovrapposizione(User $allievo, Tenant $palestra): int
    {
        return $this->context->runWithoutTenant(function () use ($allievo, $palestra): int {
            $quanti = DB::table('trainer_member')
                ->where('member_id', $allievo->getKey())
                ->where('sovrapposto_in_tenant_id', $palestra->getKey())
                ->whereNotNull('sovrapposto_dal')
                ->whereNull('disattivato_il')
                ->update([
                    'sovrapposto_dal' => null,
                    'sovrapposto_in_tenant_id' => null,
                    'slot_deciso_il' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($quanti > 0) {
                Log::info('Sovrapposizione chiusa: allievo uscito dalla palestra', [
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
