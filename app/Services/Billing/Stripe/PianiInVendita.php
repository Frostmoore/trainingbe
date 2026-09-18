<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Throwable;

/**
 * 💳 **I piani di trainer e palestre, venduti davvero** — §9.2, 18/09/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * > *«il discorso di stripe è che va sistemato tutto, in tutti gli scenari,
 * > almeno abbiamo la parte pagamenti coperta»* — il committente, 18/09.
 *
 * ══ 🚨 COSA C'ERA ═════════════════════════════════════════════════════════
 *
 * ⛔ Il listino vende nove prodotti, e Stripe ne incassava **due** — il piano
 * personale e i gettoni. I tre piani trainer, i tre piani palestra e il piano a
 * consumo erano **a listino, sul sito, coi prezzi — e non si potevano pagare**.
 * Un trainer nuovo non aveva modo di ottenere un posto per un allievo; una
 * palestra non aveva modo di abbonarsi. Entravano a mano.
 *
 * ══ ⚠️ PERCHE' UNA CLASSE SUA, E PERCHE' TUTTE E DUE LE META' STANNO QUI ══
 *
 * `Cassa` insegna la regola che conta: *«quello che si scrive nei `metadata`
 * aprendo la sessione è l'unica cosa che si ritrova quando torna il webhook»*.
 * ⛔ Se apertura e incasso vivono in file diversi, prima o poi una scrive `piano`
 * e l'altra legge `codice`: il pagamento riesce, i soldi arrivano, e il cliente
 * non riceve niente. 💡 Quindi qui stanno **tutte e due**: [apri] e [accendi].
 *
 * ══ 🚨 LA REGOLA CHE EVITA DI FAR PAGARE DUE VOLTE ════════════════════════
 *
 * **Chi ha già un piano a canone pagato con Stripe non apre un secondo
 * pagamento: lo cambia** ([cambia]). ⛔ Un secondo checkout creerebbe un secondo
 * abbonamento, e il primo continuerebbe a rinnovarsi da solo: due addebiti al
 * mese per un servizio solo, e nessun errore da nessuna parte.
 *
 * | Da → a | Strada |
 * |---|---|
 * | niente, o un piano dato a mano → qualunque | checkout |
 * | canone Stripe → canone | [cambia]: Stripe aggiorna la riga e fa il conguaglio |
 * | canone Stripe → consumo | checkout *setup*; il canone si chiude **a fine periodo**, il consumo parte da lì |
 * | consumo → canone | checkout; il consumo si chiude **subito**, e il mese lo fattura `AddebitoDelMese` |
 */
class PianiInVendita
{
    /** Nei `metadata`: è un piano. È anche il valore che torna nel webhook. */
    public const TIPO = 'piano';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ContoDegliIscritti $conto,
    ) {}

    // ═══════════════════════════ LA META' CHE APRE ═══════════════════════════

    /**
     * Apre il pagamento di un piano, e restituisce dove mandare la persona.
     *
     * 🚨 **Il prezzo viene dal listino, mai dal client.** Il client dice
     * **quale** piano; quanto costa lo decide il server. ⛔ Altrimenti chiunque
     * potrebbe abbonare la sua palestra a un centesimo cambiando una riga.
     *
     * @throws InvalidArgumentException con un messaggio da mostrare
     */
    public function apri(User $utente, string $codice, ?string $tornaA = null): string
    {
        $piano = Plan::query()->pubblici()->where('code', $codice)->first();

        if ($piano === null) {
            throw new InvalidArgumentException('Questo piano non è a listino.');
        }

        $tenant = $this->tenantCheCompra($utente, $piano);

        if (! $piano->aConsumo() && $this->abbonamentoStripeAttivo($tenant) !== null) {
            /*
             * 🚨 La regola del docblock: con un canone già pagato su Stripe, si
             * **cambia**. Un secondo checkout sarebbe un secondo abbonamento.
             */
            throw new InvalidArgumentException(
                'Hai già un piano attivo: cambialo invece di comprarne un altro, così non paghi due volte.'
            );
        }

        return $piano->aConsumo()
            ? $this->apriIlConsumo($utente, $tenant, $piano, $tornaA)
            : $this->apriIlCanone($utente, $tenant, $piano, $tornaA);
    }

    private function apriIlCanone(User $utente, Tenant $tenant, Plan $piano, ?string $tornaA): string
    {
        if ($piano->eGratuito()) {
            throw new InvalidArgumentException('Questo piano non si paga.');
        }

        $meta = $this->metadata($utente, $tenant, $piano);
        $cliente = $this->clienteDel($tenant);

        $sessione = $this->stripe()->checkout->sessions->create([
            'mode' => 'subscription',

            /*
             * ⚠️ **Spenti, come in `Cassa`**, e per la stessa ragione: il
             * committente è al forfettario, e con i Managed Payments accesi
             * Stripe farebbe da venditore e applicherebbe l'IVA su una fattura
             * che per legge non deve averla. Vedi il commento lungo in `Cassa`.
             */
            'managed_payments' => ['enabled' => false],

            'metadata' => $meta,

            /*
             * 🚨 **Anche sull'abbonamento**, non solo sulla sessione: quelli
             * della sessione valgono per il primo pagamento, e `invoice.paid`
             * del mese dopo non li vede.
             */
            'subscription_data' => ['metadata' => $meta],

            /*
             * 💡 **Il cliente di prima, se c'è.** Una palestra che passa dal
             * consumo al canone deve restare lo **stesso** cliente: le sue
             * fatture in un posto solo, e l'eventuale credito di Stripe resta
             * suo invece di finire su un cliente nuovo che nessuno usa.
             */
            ...($cliente !== null ? ['customer' => $cliente] : ['customer_email' => $utente->email]),

            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => (int) $piano->price_cents,
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => $piano->name],
                ],
            ]],

            'success_url' => $this->indirizzo($tornaA, '/pagamento/ok'),
            'cancel_url' => $this->indirizzo($tornaA, '/pagamento/annullato'),
        ]);

        return (string) $sessione->url;
    }

    /**
     * 🆕 **Il consumo: si salva la carta, non si addebita niente** — §9.3.
     *
     * ⚠️ Il piano a consumo **non è un abbonamento di Stripe**: il prezzo cambia
     * ogni mese con gli iscritti, e la fattura la emette `AddebitoDelMese` a mese
     * chiuso. Qui serve solo **un cliente con una carta**, da addebitare dopo.
     *
     * 🚨 **Il cliente nasce prima della sessione**, e non per scrupolo: in modalità
     * *setup* è il modo di sapere per certo **quale** cliente riceverà la carta —
     * lo stesso che poi riceverà le fatture.
     */
    private function apriIlConsumo(User $utente, Tenant $tenant, Plan $piano, ?string $tornaA): string
    {
        $cliente = $this->clienteDel($tenant) ?? (string) $this->stripe()->customers->create([
            'email' => $utente->email,
            'name' => $tenant->name,
            'metadata' => ['tenant_id' => (string) $tenant->getKey()],
        ])->id;

        $meta = $this->metadata($utente, $tenant, $piano);

        $sessione = $this->stripe()->checkout->sessions->create([
            'mode' => 'setup',

            /*
             * 🚨 **Anche qui, e l'ha detto Stripe** — trovato nella sandbox il
             * 18/09, non nei test.
             *
             * ⛔ Senza questa riga Stripe rifiuta la sessione: *«Invalid mode:
             * setup. Managed Payments, which is enabled by default on your
             * account, only supports mode: subscription or mode: payment»*.
             *
             * ⚠️ **I test col finto non potevano accorgersene**: il finto accetta
             * qualunque cosa. È la stessa trappola di `Cassa` del 27/08, in una
             * strada nuova — e il motivo per cui ogni richiesta nuova va fatta
             * accettare a Stripe **vero**, non solo al finto.
             */
            'managed_payments' => ['enabled' => false],

            'customer' => $cliente,
            'payment_method_types' => ['card'],
            'metadata' => $meta,
            'setup_intent_data' => ['metadata' => $meta],
            'success_url' => $this->indirizzo($tornaA, '/pagamento/ok'),
            'cancel_url' => $this->indirizzo($tornaA, '/pagamento/annullato'),
        ]);

        return (string) $sessione->url;
    }

    // ══════════════════════════ LA META' CHE INCASSA ═════════════════════════

    /**
     * Il webhook: il pagamento (o la carta) è andato a buon fine.
     *
     * ⚠️ **Il tenant si legge dai `metadata`**, non da `$utente->tenant`: fra
     * l'apertura e il ritorno quella persona può aver cambiato spazio, e il
     * piano va a chi l'ha comprato — non a dove si trova adesso chi l'ha pagato.
     *
     * ⛔ **Non lancia mai**: un'eccezione qui farebbe rispondere 500 a Stripe, che
     * ritenterebbe per ore trovando l'evento già registrato. Meglio un errore nel
     * log, che si vede e si rimedia a mano. È la regola di `Cassa::applica()`.
     *
     * @param  array<string, mixed>  $sessione
     */
    public function accendi(array $sessione): void
    {
        $meta = $sessione['metadata'] ?? [];

        $piano = Plan::query()->where('code', $meta['piano'] ?? '')->first();
        $tenant = Tenant::withoutGlobalScopes()->find($meta['tenant_id'] ?? null);
        $utente = User::withoutGlobalScopes()->find($meta['user_id'] ?? null);

        if ($piano === null || $tenant === null || $utente === null) {
            Log::error('Stripe: piano pagato senza piano, tenant o persona.', ['metadata' => $meta]);

            return;
        }

        try {
            $this->context->runWithoutTenant(fn () => DB::transaction(function () use ($sessione, $piano, $tenant, $utente): void {
                ($sessione['mode'] ?? null) === 'setup'
                    ? $this->accendiIlConsumo($sessione, $piano, $tenant)
                    : $this->accendiIlCanone($sessione, $piano, $tenant);

                if ($piano->kind === PlanKind::Trainer) {
                    $this->diventaTrainer($utente, $tenant);
                }
            }));
        } catch (Throwable $e) {
            Log::error('Stripe: il piano pagato non si è acceso.', [
                'piano' => $piano->code,
                'tenant' => $tenant->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $sessione */
    private function accendiIlCanone(array $sessione, Plan $piano, Tenant $tenant): void
    {
        $idAbbonamento = $sessione['subscription'] ?? null;

        /*
         * 💡 **Idempotente sull'id di Stripe**: se lo stesso abbonamento arriva
         * due volte non nasce una seconda riga. La difesa vera è l'unicità di
         * `stripe_events.event_id`; questa è la seconda serratura.
         */
        if ($idAbbonamento !== null
            && PlanSubscription::withoutGlobalScopes()->where('stripe_subscription_id', $idAbbonamento)->exists()) {
            return;
        }

        $this->chiudiIPianiDiPrima($tenant, Carbon::now());

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $piano->getKey(),
            'stripe_subscription_id' => $idAbbonamento,
            'stripe_customer_id' => $sessione['customer'] ?? null,
            'rinnova' => true,
            'starts_at' => Carbon::now(),

            /*
             * ⚠️ **Un giorno di grazia**, come per il piano personale: un
             * rinnovo che arriva con qualche ora di ritardo non deve lasciare
             * nessuno fuori. La data vera la scrive `invoice.paid` al rinnovo.
             */
            'ends_at' => Carbon::now()->addMonth()->addDay(),
        ]);
    }

    /**
     * 🆕 Il consumo: la carta è salvata, il piano parte.
     *
     * @param  array<string, mixed>  $sessione
     */
    private function accendiIlConsumo(array $sessione, Plan $piano, Tenant $tenant): void
    {
        $cliente = (string) ($sessione['customer'] ?? '');

        if ($cliente === '') {
            throw new \RuntimeException('Sessione di setup senza cliente.');
        }

        /*
         * 🚨 **La carta diventa quella di serie per le fatture.** Senza, la
         * fattura a consumo di fine mese troverebbe un cliente **senza nessun
         * modo di pagare**, e Stripe la lascerebbe aperta: la palestra non paga,
         * e nessuno se ne accorge fino al mese dopo.
         */
        $metodo = $this->stripe()->setupIntents->retrieve((string) ($sessione['setup_intent'] ?? ''))->payment_method;

        $this->stripe()->customers->update($cliente, [
            'invoice_settings' => ['default_payment_method' => $metodo],
        ]);

        if (PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('plan_id', $piano->getKey())
            ->where('stripe_customer_id', $cliente)
            ->attivi()
            ->exists()) {
            return;
        }

        /*
         * ⚠️ **Da un canone Stripe si passa a fine periodo**: quel mese è già
         * pagato, e toglierlo subito vorrebbe dire far pagare due volte lo stesso
         * mese — canone e consumo. Il consumo parte quando il canone finisce.
         */
        $canone = $this->abbonamentoStripeAttivo($tenant);
        $parte = Carbon::now();

        if ($canone !== null) {
            $this->stripe()->subscriptions->update((string) $canone->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);

            $canone->forceFill(['rinnova' => false])->save();
            $parte = $canone->ends_at?->copy() ?? Carbon::now();
        } else {
            $this->chiudiIPianiDiPrima($tenant, Carbon::now());
        }

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $piano->getKey(),
            'stripe_customer_id' => $cliente,
            'rinnova' => true,
            'starts_at' => $parte,

            /** 💡 `null` = non scade: si paga a mese chiuso finché non si cambia. */
            'ends_at' => null,
        ]);
    }

    // ══════════════════════════════ CAMBIARE ═════════════════════════════════

    /**
     * 🆕 **Cambiare piano a canone** — §9.5.
     *
     * 💡 **Salire è sempre possibile, e subito**: Stripe aggiorna la riga
     * dell'abbonamento e fa il conguaglio da sé (`proration_behavior`).
     *
     * ⛔ **Scendere si rifiuta se lascia qualcuno senza posto.** Un trainer con
     * dodici allievi che passa al piano da cinque: chi dei dodici resta fuori?
     * Deciderlo qui sarebbe scegliere a caso chi perde il suo trainer. 💡 Prima si
     * liberano i posti, poi si scende — ed è una scelta che fa lui.
     *
     * @throws InvalidArgumentException con un messaggio da mostrare
     */
    public function cambia(User $utente, string $codice): void
    {
        $nuovo = Plan::query()->pubblici()->where('code', $codice)->first();

        if ($nuovo === null || $nuovo->aConsumo() || $nuovo->eGratuito()) {
            throw new InvalidArgumentException('A questo piano non si passa cambiando: si sceglie dal listino.');
        }

        $tenant = $this->tenantCheCompra($utente, $nuovo);
        $attuale = $this->abbonamentoStripeAttivo($tenant);

        if ($attuale === null) {
            throw new InvalidArgumentException('Non hai un piano da cambiare: scegline uno dal listino.');
        }

        if ($attuale->plan_id === $nuovo->getKey()) {
            throw new InvalidArgumentException('Hai già questo piano.');
        }

        $this->pretendiCheCiStianoTutti($tenant, $nuovo);

        $voce = $this->stripe()->subscriptions->retrieve((string) $attuale->stripe_subscription_id)->items->data[0] ?? null;

        if ($voce === null) {
            throw new InvalidArgumentException('Non riesco a leggere il tuo abbonamento. Riprova fra poco.');
        }

        $this->stripe()->subscriptions->update((string) $attuale->stripe_subscription_id, [
            'items' => [[
                'id' => $voce->id,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => (int) $nuovo->price_cents,
                    'recurring' => ['interval' => 'month'],

                    /*
                     * 🚨 **`product` e non `product_data`, e l'ha detto Stripe**
                     * — trovato nella sandbox il 18/09, non nei test.
                     *
                     * ⛔ *«Received unknown parameter:
                     * items[0][price_data][product_data]. Did you mean
                     * product?»* Nel **checkout** il prodotto si descrive al
                     * volo; nell'API degli **abbonamenti** no: vuole l'id di un
                     * prodotto che esiste. Col finto sarebbe passato, e il primo
                     * trainer che cambiava piano avrebbe visto un errore.
                     */
                    'product' => $this->prodottoDel($nuovo),
                ],
            ]],

            /*
             * 💡 **Il conguaglio lo fa Stripe**: chi sale paga la differenza per
             * i giorni che restano, chi scende se la ritrova come credito.
             */
            'proration_behavior' => 'create_prorations',
            'metadata' => $this->metadata($utente, $tenant, $nuovo),
        ]);

        /*
         * ⚠️ **La riga si aggiorna subito**, non al webhook: chi è appena salito
         * deve poter invitare il sesto allievo adesso, non quando arriva un
         * evento che può tardare minuti.
         */
        $attuale->forceFill(['plan_id' => $nuovo->getKey()])->save();

        Log::info('Stripe: piano cambiato.', [
            'tenant' => $tenant->getKey(),
            'da' => $attuale->getOriginal('plan_id'),
            'a' => $nuovo->code,
        ]);
    }

    // ═════════════════════════════ LE REGOLE ═════════════════════════════════

    /**
     * Su quale spazio si compra, e se questa persona può farlo.
     *
     * | Piano | Chi lo compra |
     * |---|---|
     * | trainer | il proprietario di uno spazio **personale** |
     * | palestra | il proprietario di una **palestra** |
     * | personale | ⛔ non da qui: ha la sua strada in `Cassa` |
     *
     * ⚠️ **Il proprietario, sempre** — `Cassa::loPuoGestire()`. ⛔ Un iscritto di
     * palestra che abbona la palestra, o un allievo che compra un piano nello
     * spazio del suo trainer, pagherebbero per conto di qualcun altro.
     */
    private function tenantCheCompra(User $utente, Plan $piano): Tenant
    {
        if (! Cassa::loPuoGestire($utente) || $utente->tenant === null) {
            throw new InvalidArgumentException('Solo chi è titolare dello spazio può sceglierne il piano.');
        }

        $tenant = $utente->tenant;

        return match ($piano->kind) {
            PlanKind::Trainer => $tenant->ePersonale()
                ? $tenant
                : throw new InvalidArgumentException('I piani trainer si comprano dal proprio spazio, non da una palestra.'),

            PlanKind::Gym => ! $tenant->ePersonale()
                ? $tenant
                : throw new InvalidArgumentException('I piani palestra li compra il titolare della palestra.'),

            default => throw new InvalidArgumentException('Questo piano si compra dalla schermata degli acquisti.'),
        };
    }

    /**
     * ⛔ Scendere non lascia nessuno fuori.
     *
     * @throws InvalidArgumentException
     */
    private function pretendiCheCiStianoTutti(Tenant $tenant, Plan $nuovo): void
    {
        if ($nuovo->kind === PlanKind::Trainer && $nuovo->max_members !== null) {
            $proprietario = User::withoutGlobalScopes()->find($tenant->proprietario_id);
            $rimasti = $proprietario === null ? null : app(InvitiDelTrainer::class)->postiRimasti($proprietario);
            $attuale = $this->abbonamentoStripeAttivo($tenant)?->plan;
            $occupati = ($attuale?->max_members ?? 0) - ($rimasti ?? 0);

            if ($occupati > $nuovo->max_members) {
                throw new InvalidArgumentException(
                    "Hai {$occupati} allievi e questo piano ne tiene {$nuovo->max_members}: libera qualche posto prima di scendere."
                );
            }
        }

        if ($nuovo->kind === PlanKind::Gym) {
            $iscritti = $this->conto->quanti($tenant, UserRole::Member);
            $trainer = $this->conto->quanti($tenant, UserRole::Trainer);

            if ($nuovo->max_members !== null && $iscritti > $nuovo->max_members) {
                throw new InvalidArgumentException(
                    "Hai {$iscritti} iscritti e questo piano ne tiene {$nuovo->max_members}: non puoi scendere senza lasciare fuori qualcuno."
                );
            }

            if ($nuovo->max_trainers !== null && $trainer > $nuovo->max_trainers) {
                throw new InvalidArgumentException(
                    "Hai {$trainer} trainer e questo piano ne tiene {$nuovo->max_trainers}."
                );
            }
        }
    }

    /**
     * Chi compra un piano trainer **diventa** trainer nel proprio spazio.
     *
     * 💡 È il caso di chi allena in palestra e vuole un giro suo: a casa è
     * `free_user` (decisione 2), e pagando il piano trainer apre il suo giro.
     * ⚠️ Idempotente: se il ruolo c'è già non succede niente.
     */
    private function diventaTrainer(User $utente, Tenant $tenant): void
    {
        $this->context->runAs($tenant, function () use ($utente): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');

            if (! $fresco->hasRole(UserRole::FreeTrainer->value)) {
                $fresco->assignRole(UserRole::FreeTrainer->value);
            }
        });
    }

    /**
     * Chiude gli abbonamenti ancora attivi dello spazio — **anche su Stripe**.
     *
     * 🚨 **Anche su Stripe, e subito**: un abbonamento chiuso solo da noi
     * continuerebbe a rinnovarsi da solo. È il modo più silenzioso di far pagare
     * due volte qualcuno.
     */
    private function chiudiIPianiDiPrima(Tenant $tenant, Carbon $quando): void
    {
        $attivi = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->attivi()
            ->get();

        foreach ($attivi as $riga) {
            if ($riga->stripe_subscription_id !== null) {
                $this->stripe()->subscriptions->cancel((string) $riga->stripe_subscription_id);
            }

            $riga->forceFill(['ends_at' => $quando, 'rinnova' => false])->save();
        }
    }

    /**
     * 🆕 **Il prodotto Stripe di questo piano**, stabile — trovato o creato.
     *
     * 💡 **Un prodotto per piano, con un id nostro** (`tcai_piano_<codice>`):
     * la seconda volta si ritrova invece di crearne uno nuovo a ogni cambio. ⚠️ E
     * così la fattura del mese dopo porta il nome del piano **nuovo**: riusare il
     * prodotto dell'abbonamento di prima scriverebbe «5 allievi» su chi ne paga 15.
     */
    private function prodottoDel(Plan $piano): string
    {
        $id = 'tcai_piano_'.$piano->code;

        try {
            return (string) $this->stripe()->products->retrieve($id)->id;
        } catch (InvalidRequestException) {
            return (string) $this->stripe()->products->create([
                'id' => $id,
                'name' => $piano->name,
                'metadata' => ['piano' => $piano->code],
            ])->id;
        }
    }

    /** L'abbonamento a canone attivo e pagato su Stripe, se c'è. */
    private function abbonamentoStripeAttivo(Tenant $tenant): ?PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereNotNull('stripe_subscription_id')
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();
    }

    /** Il cliente Stripe di questo spazio, se ne ha uno. */
    private function clienteDel(Tenant $tenant): ?string
    {
        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereNotNull('stripe_customer_id')
            ->orderByDesc('starts_at')
            ->value('stripe_customer_id');
    }

    /**
     * 🚨 **Il contratto fra le due metà.** Quello che non è scritto qui, al
     * ritorno non esiste.
     *
     * @return array<string, string>
     */
    private function metadata(User $utente, Tenant $tenant, Plan $piano): array
    {
        return [
            'tipo' => self::TIPO,
            'piano' => $piano->code,
            'user_id' => (string) $utente->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
        ];
    }

    /**
     * ⚠️ **Solo indirizzi nostri.** Un `tornaA` arbitrario farebbe di questa
     * pagina un rimbalzo verso qualunque sito — il classico reindirizzamento
     * aperto, con il marchio di Stripe davanti.
     */
    private function indirizzo(?string $tornaA, string $ripiego): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($tornaA !== null && str_starts_with($tornaA, $base.'/')) {
            return $tornaA;
        }

        return $base.$ripiego;
    }

    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }
}
