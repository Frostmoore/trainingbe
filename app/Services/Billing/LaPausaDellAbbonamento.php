<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * ⏸️ **L'abbonamento personale si ferma entrando in palestra, e riprende
 * uscendo** — 3b-AH, regola 8.1.6 (18/09/2026).
 *
 * ══ 🚨 IL BUCO CHE CHIUDE, ED ERA DI SOLDI VERI ═══════════════════════════
 *
 * 📌 Il committente, 18/09: *«Assicurati che l'abbonamento dell'allievo venga
 * sospeso se si iscrive a una palestra, se era abbonato, e che riprenda quando
 * l'allievo torna libero.»*
 *
 * ⛔ **Prima non succedeva niente.** `plan_subscriptions` sta in
 * `UnisciAUnaPalestra::RESTANO_INDIETRO`, quindi l'abbonamento restava su un
 * tenant che veniva sospeso e cancellato — e **nessuno toccava Stripe**.
 * 🚨 Quella persona **continuava a pagare** mentre era già coperta dalla
 * palestra, e non c'era nessun errore da nessuna parte: il pagamento riesce, il
 * servizio c'è, e l'unico modo di accorgersene è l'estratto conto.
 *
 * ══ 💡 DUE META', E TUTTE E DUE SERVONO ══════════════════════════════════
 *
 * | Metà | Cosa | Se manca |
 * |---|---|---|
 * | **La nostra** | `in_pausa_dal` sulla riga | L'abbonamento risulta attivo e la persona ha due coperture |
 * | **Quella di Stripe** | `pause_collection` | ⛔ **Si continua a pagare.** La sospensione sarebbe solo nostra, cioè finta |
 *
 * ⚠️ **Se Stripe fallisce, la pausa nostra si fa lo stesso** e l'errore va nel
 * log. ⛔ Il contrario — non sospendere perché Stripe non risponde — lascerebbe
 * la persona con due coperture *e* un addebito: due danni invece di uno.
 *
 * ══ 📌 I GIORNI GIA' PAGATI SI RICONOSCONO — decisione del 18/09 ══════════
 *
 * > *«Si riconoscono alla ripresa.»*
 *
 * 🚨 Per questo si salva `restavano_giorni` **al momento della pausa**: dopo
 * non è più ricostruibile. È la stessa ragione per cui `addebiti_mensili`
 * congela il conteggio — un numero che serve dopo si scrive quando lo si sa.
 */
class LaPausaDellAbbonamento
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Mette in pausa l'abbonamento personale di chi entra in una palestra.
     *
     * ⚠️ **Il tenant di partenza si passa esplicitamente**: quando questo
     * metodo viene chiamato, `$utente->tenant_id` può già essere cambiato — e
     * allora non ci sarebbe più modo di sapere quale abbonamento fermare.
     *
     * ⛔ **Non tocca l'abbonamento della palestra**, ed è la riga più
     * importante: si ferma solo quello del tenant **personale** da cui la
     * persona sta uscendo.
     *
     * @return bool `true` se qualcosa è stato messo in pausa
     */
    public function metti(User $utente, Tenant $spazioDiPartenza, Tenant $palestra): bool
    {
        return $this->context->runWithoutTenant(function () use ($utente, $spazioDiPartenza, $palestra): bool {
            /*
             * 🚨 **Solo se quello spazio era SUO.** Un abbonamento su uno spazio
             * di qualcun altro — il tenant di un trainer, un'altra palestra —
             * non è suo da mettere in pausa.
             */
            if (! $spazioDiPartenza->eDi($utente)) {
                return false;
            }

            $riga = PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazioDiPartenza->getKey())
                ->whereNull('in_pausa_dal')
                ->attivi()
                ->orderByDesc('starts_at')
                ->first();

            if ($riga === null) {
                return false;
            }

            $ora = Carbon::now();

            /*
             * ⚠️ **`null` non è `0`.** `ends_at IS NULL` è la convenzione «non
             * scade»: lì non c'è niente da riconoscere, e scriverci `0`
             * direbbe «era l'ultimo giorno» — cioè farebbe riprendere un
             * abbonamento perpetuo già scaduto.
             */
            /*
             * 🚨 **`ceil` e non un troncamento**, ed è costato un test rosso:
             * fra il momento in cui la scadenza è stata scritta e questo
             * istante passano microsecondi, quindi venti giorni pieni misurano
             * `19,999…` — e `(int)` li tronca a **diciannove**.
             *
             * ⚠️ Un giorno perso a ogni pausa, sempre nella stessa direzione:
             * contro chi paga. 💡 Arrotondando per eccesso l'errore, quando
             * c'è, va a favore del cliente — che è il verso giusto in cui
             * sbagliare su una cosa che ha pagato.
             */
            $restavano = $riga->ends_at === null
                ? null
                : max(0, (int) ceil($ora->diffInDays($riga->ends_at, false)));

            $riga->forceFill([
                'in_pausa_dal' => $ora,
                'in_pausa_per_tenant_id' => $palestra->getKey(),
                'restavano_giorni' => $restavano,
            ])->save();

            $this->fermaStripe($riga);

            Log::info('Abbonamento personale messo in pausa', [
                'utente' => $utente->getKey(),
                'abbonamento' => $riga->getKey(),
                'palestra' => $palestra->getKey(),
                'restavano_giorni' => $restavano,
            ]);

            return true;
        });
    }

    /**
     * Riprende l'abbonamento di chi esce da una palestra, **sullo spazio nuovo**.
     *
     * 🚨 **La riga si sposta.** Uscendo, `EsciDaUnaPalestra` crea un tenant
     * personale **nuovo**: lasciare l'abbonamento sul vecchio — sospeso e
     * cancellato — vorrebbe dire riattivare qualcosa che nessuno troverà più.
     * ⛔ È esattamente il motivo per cui prima non tornava niente.
     *
     * ⚠️ **La nuova scadenza è `adesso + quello che restava`**, non «adesso +
     * un mese»: 📌 *«si riconoscono alla ripresa»*.
     *
     * @return bool `true` se qualcosa è ripreso
     */
    public function riprendi(User $utente, Tenant $palestra, Tenant $spazioNuovo): bool
    {
        return $this->context->runWithoutTenant(function () use ($utente, $palestra, $spazioNuovo): bool {
            $riga = PlanSubscription::withoutGlobalScopes()
                ->whereNotNull('in_pausa_dal')
                ->where('in_pausa_per_tenant_id', $palestra->getKey())
                ->whereIn(
                    'tenant_id',
                    Tenant::withoutGlobalScopes()
                        ->withTrashed()
                        ->where('proprietario_id', $utente->getKey())
                        ->select('id'),
                )
                ->orderByDesc('in_pausa_dal')
                ->first();

            if ($riga === null) {
                return false;
            }

            $ora = Carbon::now();

            $riga->forceFill([
                'tenant_id' => $spazioNuovo->getKey(),
                'in_pausa_dal' => null,
                'in_pausa_per_tenant_id' => null,
                'restavano_giorni' => null,

                /*
                 * 💡 `restavano_giorni` nullo voleva dire «non scadeva»: e
                 * allora non scade nemmeno adesso.
                 */
                'ends_at' => $riga->restavano_giorni === null
                    ? null
                    : $ora->copy()->addDays($riga->restavano_giorni),
            ])->save();

            $this->riprendiStripe($riga);

            Log::info('Abbonamento personale ripreso', [
                'utente' => $utente->getKey(),
                'abbonamento' => $riga->getKey(),
                'giorni_riconosciuti' => $riga->restavano_giorni,
            ]);

            return true;
        });
    }

    /**
     * 🚨 **Ferma davvero l'addebito**, non solo il nostro conto.
     *
     * ⚠️ `behavior: void` — durante la pausa **non si matura niente**: né si
     * addebita, né si accumula un arretrato da pagare al ritorno. ⛔ Il default
     * di Stripe (`keep_as_draft`) presenterebbe il conto tutto insieme alla
     * ripresa, che è il contrario di quello che è stato promesso.
     */
    private function fermaStripe(PlanSubscription $riga): void
    {
        if ($riga->stripe_subscription_id === null) {
            return;
        }

        try {
            $this->stripe()->subscriptions->update($riga->stripe_subscription_id, [
                'pause_collection' => ['behavior' => 'void'],
            ]);
        } catch (Throwable $e) {
            /*
             * ⛔ **Non si rilancia.** La pausa nostra è già scritta: fermarsi
             * qui lascerebbe la persona con due coperture **e** l'addebito.
             * 💡 Meglio un errore nel log, che si vede e si rimedia a mano.
             */
            Log::error('Stripe: non sono riuscito a mettere in pausa.', [
                'abbonamento' => $riga->getKey(),
                'stripe' => $riga->stripe_subscription_id,
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Toglie la pausa, **spostando la data del rinnovo**.
     *
     * 🚨 **Senza `trial_end` Stripe riaddebita subito.** Il periodo è scaduto
     * durante la pausa, quindi alla ripresa il rinnovo risulta dovuto **da
     * giorni**: la persona si vedrebbe un addebito immediato nel momento esatto
     * in cui le stiamo dicendo che i giorni pagati le vengono riconosciuti.
     */
    private function riprendiStripe(PlanSubscription $riga): void
    {
        if ($riga->stripe_subscription_id === null) {
            return;
        }

        try {
            $parametri = ['pause_collection' => null];

            if ($riga->ends_at !== null) {
                $parametri['trial_end'] = $riga->ends_at->getTimestamp();
                $parametri['proration_behavior'] = 'none';
            }

            $this->stripe()->subscriptions->update($riga->stripe_subscription_id, $parametri);
        } catch (Throwable $e) {
            Log::error('Stripe: non sono riuscito a togliere la pausa.', [
                'abbonamento' => $riga->getKey(),
                'stripe' => $riga->stripe_subscription_id,
                'errore' => $e->getMessage(),
            ]);
        }
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
