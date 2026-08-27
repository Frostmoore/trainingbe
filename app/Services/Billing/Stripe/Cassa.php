<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Models\AiCreditMovement;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\Listino;
use App\Services\Billing\PortafoglioGettoni;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * La cassa: apre una sessione di pagamento e la incassa — 3b-H, 26/08/2026.
 *
 * ── 🚨 PERCHE' TUTTO QUI DENTRO E NON NEL CONTROLLER ──────────────────────
 *
 * Perche' le due meta' devono conoscersi: **quello che si scrive nei
 * `metadata` aprendo la sessione e' l'unica cosa che si ritrova quando torna
 * il webhook**. Se le due meta' vivono in file diversi, prima o poi una scrive
 * `gettoni` e l'altra legge `crediti`, il pagamento riesce, i soldi arrivano e
 * il cliente non riceve niente — senza nessun errore da nessuna parte.
 *
 * ── ⚠️ E l'idempotenza NON e' qui ─────────────────────────────────────────
 *
 * Stripe rimanda lo stesso evento quando la risposta tarda. 💡 La difesa e'
 * l'unicita' di `stripe_events.event_id`: [applica] viene chiamata **dopo** che
 * l'inserimento e' riuscito, quindi al secondo giro non ci si arriva nemmeno.
 * 🚨 Chi sposta questa chiamata prima di quell'insert accredita due volte.
 */
class Cassa
{
    /** I due tipi di acquisto. Sono anche i valori che finiscono nei metadata. */
    public const ABBONAMENTO = 'abbonamento';

    public const GETTONI = 'gettoni';

    public function __construct(
        private readonly Listino $listino,
        private readonly PortafoglioGettoni $portafoglio,
    ) {}

    /**
     * Apre una sessione di pagamento e restituisce dove mandare la persona.
     *
     * @param  int|null  $gettoni  il taglio scelto, solo per [GETTONI]
     */
    public function apri(User $utente, string $tipo, ?int $gettoni = null): string
    {
        $tenant = $utente->tenant;

        if ($tenant === null) {
            throw new InvalidArgumentException('Questa persona non ha un tenant: non si sa a chi accreditare.');
        }

        $comune = [
            'mode' => $tipo === self::ABBONAMENTO ? 'subscription' : 'payment',

            /*
             * ══ 🚨 I MANAGED PAYMENTS SI SPENGONO — 27/08/2026 ═════════════
             *
             * ⛔ **Senza questa riga il pagamento non si apre proprio.** Stripe:
             *
             *     Invalid line_items[0]: the product tax code is missing.
             *     Product tax code is required for Managed Payments, which is
             *     enabled by default on your account.
             *
             * I *Managed Payments* sono la modalita' in cui **Stripe fa da
             * venditore** e calcola lui l'imposta: in quel caso ogni prodotto
             * creato al volo deve dichiarare un codice fiscale di prodotto.
             *
             * ── 🚨 E QUI NON E' NEMMENO UNA SCELTA: E' IL REGIME FISCALE ────
             *
             * 📌 Il committente, 27/08/2026: *«io sono al forfettario, non la
             * pago l'IVA»*.
             *
             * ⛔ Con i Managed Payments accesi **Stripe fa da venditore e
             * applica l'imposta**: su una fattura che per legge non deve averla.
             * Non e' una preferenza commerciale, e' un numero sbagliato su un
             * documento fiscale.
             *
             * ⚠️ E si vedrebbe **tardi**: il prezzo nell'app resterebbe 7,99, il
             * cliente ne pagherebbe di piu', e la differenza salterebbe fuori
             * dalla ricevuta o dalla contabilita' — non da un errore.
             *
             * ⏳ **Il giorno che il regime cambia** (uscita dal forfettario, o
             * vendite UE da gestire con l'OSS) questa riga si toglie e si
             * aggiunge `tax_code` a **ogni** `product_data`, con `tax_behavior`
             * a `inclusive` se il prezzo a schermo non deve muoversi. Fino ad
             * allora, spenti.
             */
            'managed_payments' => ['enabled' => false],

            /*
             * 🚨 **I metadata sono il contratto fra le due meta'.** Quello che
             * non e' scritto qui, al ritorno non esiste: la sessione di Stripe
             * non sa niente della nostra applicazione.
             */
            'metadata' => [
                'tipo' => $tipo,
                'user_id' => (string) $utente->getKey(),
                'tenant_id' => (string) $tenant->getKey(),
                'gettoni' => (string) ($gettoni ?? 0),
            ],

            /*
             * ⚠️ **L'email si passa a Stripe**, cosi' la ricevuta arriva senza
             * chiederla di nuovo — e senza che qualcuno la digiti storta.
             */
            'customer_email' => $utente->email,

            'success_url' => rtrim((string) config('app.url'), '/').'/pagamento/ok',
            'cancel_url' => rtrim((string) config('app.url'), '/').'/pagamento/annullato',
        ];

        $sessione = $tipo === self::ABBONAMENTO
            ? $this->stripe()->checkout->sessions->create($comune + [
                /*
                 * ══ 🚨 I METADATA ANCHE SULL'ABBONAMENTO — 3b-H.9 ══════════
                 *
                 * ⛔ Quelli della **sessione** valgono solo per il primo
                 * pagamento: `invoice.paid` del mese prossimo non li vede.
                 * 💡 Messi qui finiscono sull'oggetto `subscription`, che
                 * sopravvive alla sessione — e sono la rete di sicurezza se
                 * un giorno `stripe_subscription_id` non fosse stato salvato.
                 */
                'subscription_data' => ['metadata' => $comune['metadata']],

                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $this->listino->singolo(),
                        'recurring' => ['interval' => 'month'],
                        'product_data' => [
                            'name' => 'Assistente AI',
                            'description' => $this->listino->gettoniMensili()
                                .' richieste al mese, rinnovate ogni mese.',
                        ],
                    ],
                ]],
            ])
            : $this->stripe()->checkout->sessions->create($comune + [
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $this->prezzoDelPacchetto($gettoni),
                        'product_data' => ['name' => "{$gettoni} gettoni AI"],
                    ],
                ]],
            ]);

        return (string) $sessione->url;
    }

    /**
     * Apre il **portale di fatturazione** di Stripe — 3b-H.9, 27/08/2026.
     *
     * ── 🚨 PERCHE' IL PORTALE E NON UN PULSANTE NOSTRO ────────────────────
     *
     * Perche' «disdici» non e' un bottone: e' disdici, cambia carta, scarica le
     * ricevute, vedi quando scade. ⛔ Rifarlo da noi vorrebbe dire riscrivere
     * mezza contabilita' di Stripe e sbagliarne un pezzo — e il pezzo che si
     * sbaglia in questi casi e' sempre quello che il cliente usa quando e' gia'
     * arrabbiato.
     *
     * ⚠️ **Serve `stripe_customer_id`.** Chi ha un abbonamento che non viene da
     * Stripe (le palestre) non ha niente da gestire qui, ed e' giusto che il
     * pulsante non compaia invece di aprire una pagina vuota.
     */
    public function portale(User $utente, string $tornaA): string
    {
        $cliente = $this->clienteDi($utente);

        if ($cliente === null) {
            throw new InvalidArgumentException('Non c\'e\' nessun abbonamento di Stripe da gestire.');
        }

        $sessione = $this->stripe()->billingPortal->sessions->create([
            'customer' => $cliente,
            'return_url' => $tornaA,
        ]);

        return (string) $sessione->url;
    }

    /** Il cliente Stripe di questa persona, se ne ha uno. */
    public function clienteDi(User $utente): ?string
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stripe_customer_id')
            ->orderByDesc('starts_at')
            ->value('stripe_customer_id');
    }

    /**
     * Il prezzo di un taglio, **preso dal listino e non dal client**.
     *
     * 🚨 Se il taglio arrivasse col suo prezzo, chiunque potrebbe comprare 2.000
     * gettoni per un centesimo cambiando una riga della richiesta. ⛔ Il client
     * dice **quale** pacchetto vuole; quanto costa lo decide il server.
     */
    private function prezzoDelPacchetto(?int $gettoni): int
    {
        foreach ($this->listino->pacchettiGettoni() as $p) {
            if ($p['gettoni'] === $gettoni) {
                return $p['prezzo'];
            }
        }

        throw new InvalidArgumentException('Taglio di gettoni non a listino.');
    }

    /**
     * Incassa un evento di Stripe, se e' uno che ci riguarda.
     *
     * ⚠️ **Non lancia mai.** Un'eccezione qui farebbe rispondere `500` a Stripe,
     * che ritenterebbe lo stesso evento per ore — e ogni ritentativo troverebbe
     * l'`event_id` gia' inserito e non riproverebbe **l'accredito**. 💡 Meglio
     * un errore nel log, che si vede e si rimedia a mano.
     *
     * @param  array<string, mixed>  $evento
     */
    public function applica(array $evento): void
    {
        try {
            $oggetto = $evento['data']['object'] ?? [];

            /*
             * ══ 🚨 QUATTRO EVENTI, E TRE SONO IL CICLO DI VITA — 3b-H.9 ═════
             *
             * ⛔ Fino al 27/08 si ascoltava **solo il primo pagamento**: chi si
             * abbonava aveva un mese e poi si spegneva, **pagando**. Il difetto
             * peggiore possibile qui, perche' si manifesta trenta giorni dopo
             * e su un cliente che ha l'addebito in regola.
             *
             * | Evento | Cosa vuol dire |
             * |---|---|
             * | `checkout.session.completed` | il primo pagamento e' andato |
             * | `invoice.paid` | **il rinnovo**: un mese in piu' |
             * | `customer.subscription.updated` | ha disdetto, o ha ripreso |
             * | `customer.subscription.deleted` | e' finito davvero |
             *
             * ⚠️ `invoice.payment_failed` **non c'e', ed e' voluto**: la carta
             * rifiutata non toglie niente: Stripe riprova per giorni, e
             * l'abbonamento scade da solo se non ci riesce. 💡 Spegnerlo al
             * primo tentativo fallito vorrebbe dire punire chi ha cambiato
             * carta di venerdi'.
             */
            match ($evento['type'] ?? null) {
                'checkout.session.completed' => $this->primoPagamento($oggetto),
                'invoice.paid' => $this->rinnovo($oggetto),
                'customer.subscription.updated' => $this->abbonamentoCambiato($oggetto),
                'customer.subscription.deleted' => $this->abbonamentoFinito($oggetto),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Stripe: incasso fallito.', [
                'errore' => $e->getMessage(),
                'evento' => $evento['id'] ?? null,
            ]);
        }
    }

    /**
     * Il primo pagamento: gettoni o abbonamento.
     *
     * @param  array<string, mixed>  $sessione
     */
    private function primoPagamento(array $sessione): void
    {
        $meta = $sessione['metadata'] ?? [];
        $utente = User::withoutGlobalScopes()->find($meta['user_id'] ?? null);

        if ($utente === null || $utente->tenant === null) {
            Log::error('Stripe: pagamento senza utente o tenant.', ['metadata' => $meta]);

            return;
        }

        match ($meta['tipo'] ?? null) {
            self::GETTONI => $this->accreditaGettoni($utente, (int) ($meta['gettoni'] ?? 0), $sessione),
            self::ABBONAMENTO => $this->accendiLAbbonamento($utente, $sessione),
            default => Log::warning('Stripe: pagamento di tipo ignoto.', ['tipo' => $meta['tipo'] ?? null]),
        };
    }

    /**
     * 🔁 **Il rinnovo**: un mese in piu' a chi ha pagato.
     *
     * ⚠️ La prima fattura di un abbonamento arriva **anche** al primo
     * pagamento, subito dopo `checkout.session.completed`. 💡 Non e' un
     * problema: prolungare due volte lo stesso periodo e' innocuo, perche' la
     * scadenza si **calcola** dal periodo della fattura invece di sommarci un
     * mese. 🚨 Con `addMonth()` invece il primo mese sarebbe diventato due.
     *
     * @param  array<string, mixed>  $fattura
     */
    private function rinnovo(array $fattura): void
    {
        $idAbbonamento = $this->idAbbonamento($fattura);

        if ($idAbbonamento === null) {
            // 💡 Le fatture che non riguardano un abbonamento (i pacchetti di
            // gettoni) passano di qui e non devono fare niente.
            return;
        }

        $riga = $this->abbonamentoDi($idAbbonamento);

        if ($riga === null) {
            Log::error('Stripe: rinnovo di un abbonamento che non conosciamo.', [
                'subscription' => $idAbbonamento,
            ]);

            return;
        }

        $fine = $this->fineDelPeriodo($fattura);

        $riga->update([
            'ends_at' => $fine ?? now()->addMonth()->addDay(),
        ]);

        Log::info('Stripe: abbonamento rinnovato.', [
            'subscription' => $idAbbonamento,
            'fino_al' => (string) $riga->ends_at,
        ]);
    }

    /**
     * ✂️ Ha disdetto (o ci ha ripensato).
     *
     * 🚨 **Disdire NON spegne niente subito**: chi ha pagato il mese lo usa
     * fino in fondo. Cambia solo `rinnova`, che serve all'app per dire «finisce
     * il …» invece di «si rinnova il …».
     *
     * @param  array<string, mixed>  $abbonamento
     */
    private function abbonamentoCambiato(array $abbonamento): void
    {
        $riga = $this->abbonamentoDi((string) ($abbonamento['id'] ?? ''));

        if ($riga === null) {
            return;
        }

        $riga->update([
            'rinnova' => ! ($abbonamento['cancel_at_period_end'] ?? false),
            'ends_at' => $this->quando($abbonamento['current_period_end'] ?? null)
                ?? $riga->ends_at,
        ]);
    }

    /**
     * 🛑 E' finito davvero.
     *
     * ⚠️ Stripe manda questo evento **alla fine del periodo pagato**, non
     * quando si preme «disdici». Quindi qui si chiude adesso, e non si toglie
     * niente a nessuno.
     *
     * @param  array<string, mixed>  $abbonamento
     */
    private function abbonamentoFinito(array $abbonamento): void
    {
        $riga = $this->abbonamentoDi((string) ($abbonamento['id'] ?? ''));

        $riga?->update(['ends_at' => now(), 'rinnova' => false]);
    }

    private function abbonamentoDi(string $idStripe): ?PlanSubscription
    {
        if ($idStripe === '') {
            return null;
        }

        return PlanSubscription::withoutGlobalScopes()
            ->where('stripe_subscription_id', $idStripe)
            ->first();
    }

    /**
     * L'id dell'abbonamento dentro una fattura.
     *
     * ⚠️ Stripe ha cambiato posto a questo campo: nelle versioni recenti sta in
     * `parent.subscription_details.subscription`, in quelle vecchie era
     * `subscription` in cima. 💡 Si guardano tutte e due invece di legarsi a
     * una versione dell'API: costa tre righe e non si rompe a un aggiornamento.
     *
     * @param  array<string, mixed>  $fattura
     */
    private function idAbbonamento(array $fattura): ?string
    {
        $id = $fattura['subscription']
            ?? $fattura['parent']['subscription_details']['subscription']
            ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * La fine del periodo coperto dalla fattura.
     *
     * 💡 **Piu' un giorno**, come all'accensione: il rinnovo puo' arrivare
     * qualche ora dopo la scadenza, e un abbonato che resta senza AI per mezza
     * giornata **mentre sta pagando** e' il modo piu' rapido per farsi disdire.
     *
     * @param  array<string, mixed>  $fattura
     */
    private function fineDelPeriodo(array $fattura): ?\Illuminate\Support\Carbon
    {
        $righe = $fattura['lines']['data'] ?? [];
        $fine = $righe[0]['period']['end'] ?? null;

        return $this->quando($fine)?->addDay();
    }

    private function quando(mixed $istante): ?\Illuminate\Support\Carbon
    {
        return is_int($istante) || (is_string($istante) && ctype_digit($istante))
            ? \Illuminate\Support\Carbon::createFromTimestamp((int) $istante)
            : null;
    }

    /** @param  array<string, mixed>  $sessione */
    private function accreditaGettoni(User $utente, int $gettoni, array $sessione): void
    {
        if ($gettoni <= 0) {
            Log::error('Stripe: acquisto di gettoni senza taglio.', ['sessione' => $sessione['id'] ?? null]);

            return;
        }

        $this->portafoglio->accredita(
            $utente->tenant,
            $gettoni,
            // ⚠️ Nessun operatore: non l'ha accreditato una persona, l'ha
            // pagato il cliente. Metterci lui falserebbe il registro.
            nota: 'Stripe '.($sessione['id'] ?? '?'),
            causale: AiCreditMovement::ACQUISTO,
        );
    }

    /**
     * Accende l'abbonamento del singolo.
     *
     * ── ⚠️ IL RINNOVO NON PASSA DI QUI ────────────────────────────────────
     *
     * Questo e' il **primo** pagamento. I mesi successivi Stripe li annuncia con
     * `invoice.paid`, che oggi non e' gestito: ⏳ e' scritto in 3b-H come debito
     * aperto, perche' fino a che non c'e' un abbonato vero non fa danno — e
     * fingere di gestirlo sarebbe peggio che dichiararlo.
     *
     * @param  array<string, mixed>  $sessione
     */
    private function accendiLAbbonamento(User $utente, array $sessione): void
    {
        $piano = Plan::where('code', Plan::PLUS)->first();

        if ($piano === null) {
            Log::error('Stripe: piano `plus` inesistente, abbonamento non acceso.');

            return;
        }

        PlanSubscription::updateOrCreate(
            ['tenant_id' => $utente->tenant->getKey(), 'plan_id' => $piano->getKey()],
            [
                /*
                 * 🚨 **Senza questi due id i rinnovi non trovano nessuno.**
                 * `invoice.paid` arriva un mese dopo e parla solo di un
                 * `subscription` di Stripe: e' l'unico filo che lega quella
                 * fattura a questo tenant.
                 */
                'stripe_subscription_id' => $sessione['subscription'] ?? null,
                'stripe_customer_id' => $sessione['customer'] ?? null,
                'rinnova' => true,

                'starts_at' => now(),
                /*
                 * 💡 Un mese e un giorno, non un mese esatto: il rinnovo di
                 * Stripe puo' arrivare qualche ora dopo la scadenza, e un
                 * abbonato che resta senza AI per mezza giornata **mentre sta
                 * pagando** e' il modo piu' rapido per farsi disdire.
                 */
                'ends_at' => now()->addMonth()->addDay(),
            ],
        );

        Log::info('Stripe: abbonamento acceso.', [
            'utente' => $utente->getKey(),
            'sessione' => $sessione['id'] ?? null,
        ]);
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
