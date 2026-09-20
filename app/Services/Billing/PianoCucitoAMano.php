<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\AuditAction;
use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ✂️ **Il piano cucito a mano, e chi glielo mette addosso** — 21/09/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * > *«Palestra su misura: 20 trainers (tanto c'è scrivici. Mettici un modulo di
 * > contatto così mi scrivono e **creami la possibilità di creare nuovi
 * > abbonamenti per palestre con il numero di trainers che voglio io**)»*.
 *
 * ══ 🚨 PERCHÉ UN SERVIZIO E NON TRE RIGHE DENTRO FILAMENT ═════════════════
 *
 * Perché assegnare un piano **non è** creare una riga: è chiudere quello che
 * c'era, controllare che non ci sia Stripe di mezzo, e scrivere nel registro
 * chi l'ha fatto. ⛔ Dentro una `Action` di Filament quelle regole vivrebbero
 * in una closure, invisibili ai test e impossibili da riusare il giorno che
 * serviranno anche da un comando artisan.
 *
 * ══ ⛔ LE DUE COSE CHE SI RIFIUTA DI FARE ═════════════════════════════════
 *
 * 1. **Assegnare sopra un abbonamento pagato con Stripe.** ⛔ Chiuderlo solo
 *    qui lo lascerebbe **vivo su Stripe**: il cliente continuerebbe a pagare il
 *    piano vecchio mentre usa quello nuovo, e nessuno se ne accorgerebbe fino
 *    all'estratto conto. Prima si disdice di là.
 * 2. **Dare un piano da palestra a chi palestra non è**, e viceversa. 🚨 Un
 *    piano `Gym` su un tenant personale darebbe posti trainer a chi non ha
 *    nessuno da invitare, e `ContoDegliIscritti` conterebbe iscritti che non
 *    esistono.
 *
 * ══ 🚨 NON È PIÙ LA STRADA NORMALE — 21/09/2026, sera ═════════════════════
 *
 * 📌 Il committente, la sera stessa: *«certo che il piano cucito a mano deve
 * essere fatto con stripe»*. ⛔ Ha ragione: **questo metodo non incassa
 * niente**. L'abbonamento nasce senza `stripe_subscription_id`, come i regali,
 * e su un canone vuol dire ricordarsi ogni mese di fatturarlo a mano — cioè,
 * prima o poi, regalarlo.
 *
 * 💡 **La strada normale è `PianiInVendita::attivaOfferta()`**: dal pannello si
 * crea un link, il cliente lo apre e paga su Stripe come da qualunque altro
 * piano.
 *
 * ── ⚠️ Perché questo metodo resta ─────────────────────────────────────────
 *
 * Perché non tutti pagano con carta: c'è chi paga per **bonifico** e chi vuole
 * la **fattura emessa fuori**, e c'è il caso di dover rimettere in piedi un
 * cliente dopo un guaio. 🚨 Nel pannello il pulsante si chiama **«Attiva senza
 * pagamento»** ed è rosso: chi lo preme deve sapere che sta accendendo un
 * servizio che nessuno fatturerà.
 *
 * 💡 Se il piano è **a consumo**, `AddebitoDelMese` calcola comunque il dovuto
 * ogni mese e lo lascia in stato `senza_cliente` finché non c'è un cliente
 * Stripe a cui mandarlo: quel conto non si perde, si accumula.
 */
class PianoCucitoAMano
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $registro,
    ) {}

    /**
     * Assegna un piano a un cliente, chiudendo quello che aveva.
     *
     * @param  Carbon|null  $da  quando parte; `null` = adesso
     *
     * @throws InvalidArgumentException con un messaggio da mostrare in faccia
     */
    public function assegna(Tenant $tenant, Plan $piano, ?Carbon $da = null): PlanSubscription
    {
        $this->controllaCheSiSposino($tenant, $piano);

        $da ??= Carbon::now();

        /*
         * 🚨 `runWithoutTenant` e il `tenant_id` rimesso a mano: `PlanSubscription`
         * ha `BelongsToTenant`, e questo codice gira dal pannello `/god`, dove
         * il contesto è vuoto. Senza, la query non troverebbe **niente** — e
         * «niente» qui vuol dire «nessun abbonamento da chiudere», cioè due
         * abbonamenti attivi.
         */
        return $this->context->runWithoutTenant(function () use ($tenant, $piano, $da): PlanSubscription {
            $attivi = PlanSubscription::query()
                ->where('tenant_id', $tenant->getKey())
                ->attivi()
                ->get();

            foreach ($attivi as $riga) {
                if ($riga->stripe_subscription_id !== null) {
                    throw new InvalidArgumentException(
                        'Questo cliente ha un abbonamento pagato con Stripe: disdicilo da Stripe '
                        .'prima di assegnargliene uno su misura, o pagherà tutti e due.'
                    );
                }
            }

            return DB::transaction(function () use ($tenant, $piano, $da, $attivi): PlanSubscription {
                foreach ($attivi as $riga) {
                    /*
                     * 💡 Si **chiude**, non si cancella: la storia degli
                     * abbonamenti è il motivo per cui `PlanSubscription` esiste
                     * invece di una colonna su `tenants`.
                     *
                     * ⚠️ `rinnova = false` insieme alla data: senza,
                     * `abbonamenti:rinnova-i-regali` resusciterebbe stanotte
                     * quello appena chiuso.
                     */
                    $riga->ends_at = $da;
                    $riga->rinnova = false;
                    $riga->save();
                }

                $nuovo = PlanSubscription::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'plan_id' => $piano->getKey(),
                    'starts_at' => $da,

                    /*
                     * 🚨 `ends_at = null` vuol dire **«non scade»**, ed è la
                     * convenzione delle palestre (vedi `scopeAttivi`). Un
                     * accordo su misura dura finché uno dei due lo chiude: una
                     * data di fine inventata qui spegnerebbe il cliente in un
                     * giorno che nessuno gli ha detto.
                     */
                    'ends_at' => null,

                    // ⛔ Niente Stripe: è il segno che non è stato pagato da lì.
                    'stripe_subscription_id' => null,
                    'stripe_customer_id' => null,
                    'rinnova' => false,
                ]);

                $this->registro->log(
                    AuditAction::PianoSuMisuraAssegnato,
                    $tenant,
                    [
                        'piano' => $piano->code,
                        'posti_iscritto' => $piano->max_members,
                        'posti_trainer' => $piano->max_trainers,
                        'canone_cent' => $piano->price_cents,
                        'chiusi' => $attivi->count(),
                    ],
                    tenant: $tenant,
                );

                return $nuovo;
            });
        });
    }

    /**
     * 🚨 Il piano e il cliente devono essere della stessa specie.
     *
     * @throws InvalidArgumentException
     */
    private function controllaCheSiSposino(Tenant $tenant, Plan $piano): void
    {
        $ePalestra = $tenant->kind === TenantKind::Gym;

        if ($ePalestra && $piano->kind !== PlanKind::Gym) {
            throw new InvalidArgumentException(
                'Questo è un piano da '.$piano->kind->value.', e il cliente è una palestra.'
            );
        }

        if (! $ePalestra && $piano->kind === PlanKind::Gym) {
            throw new InvalidArgumentException(
                'Un piano da palestra su uno spazio personale darebbe posti trainer a chi non ha nessuno da invitare.'
            );
        }
    }
}
