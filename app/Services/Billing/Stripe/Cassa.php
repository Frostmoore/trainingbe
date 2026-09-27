<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Enums\EventoDiPromozione;
use App\Enums\SuCosaVale;
use App\Mail\PagamentoFallitoMail;
use App\Models\AddebitoMensile;
use App\Models\AiCreditMovement;
use App\Models\PacchettoGettoni;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LaSospensioneAutomatica;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\Listino;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Promozioni\GliEventi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Throwable;

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

        // 🆕 🎁 Le promozioni — 22/09/2026, fase F2.
        private readonly ScontiSuStripe $sconti,

        // 🆕 💰 Il registro degli incassi — 22/09/2026, fase F3.
        private readonly RegistroDeiPagamenti $registro,
    ) {}

    /**
     * Apre una sessione di pagamento e restituisce dove mandare la persona.
     *
     * @param  int|null  $gettoni  il taglio scelto, solo per [GETTONI]
     */
    /**
     * @param  User|null  $perConto  🆕 **R1**, fase F5.8 — chi riceve i gettoni,
     *                               quando a pagare è la palestra. ⚠️ Paga `$utente`, incassa `$perConto`.
     */
    public function apri(
        User $utente,
        string $tipo,
        ?int $gettoni = null,
        ?User $perConto = null,
    ): string {
        $tenant = $utente->tenant;

        if ($tenant === null) {
            throw new InvalidArgumentException('Questa persona non ha un tenant: non si sa a chi accreditare.');
        }

        /*
         * ══ 🎁 LO SCONTO, PRIMA DI APRIRE — 22/09/2026, fase F2 ════════════
         *
         * 🚨 Si calcola **sull'importo vero** del listino, perché una
         * percentuale su 3,00 € e su 75,00 € sono due cifre diverse. ⛔ E il
         * conto lo fanno le nostre regole, non Stripe: la cumulabilità, la
         * migliore fra le esclusive e il «mai sotto zero» stanno in
         * `LePromozioni`, in un posto solo.
         */
        $suCosa = $tipo === self::ABBONAMENTO ? SuCosaVale::Canone : SuCosaVale::Gettoni;

        $importo = $tipo === self::ABBONAMENTO
            ? $this->listino->singolo()
            : $this->prezzoDelPacchetto($gettoni);

        // 🆕 P1 — il pacchetto o il piano preciso: un premio può valere su uno solo.
        $sconto = $this->sconti->perIlCheckout(
            $utente,
            $suCosa,
            $importo,
            pacchettoId: $tipo === self::ABBONAMENTO ? null : PacchettoGettoni::query()->inVendita()->where('gettoni', $gettoni)->value('id'),
            planId: $tipo === self::ABBONAMENTO ? Plan::query()->where('code', Plan::PLUS)->value('id') : null,
            // 🆕 P2 — per il bonus in gettoni, che dipende da quanti se ne comprano.
            gettoni: $tipo === self::ABBONAMENTO ? null : $gettoni,
        );

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

                /*
                 * ══ 🎁 CHI LI RICEVE, SE NON È CHI PAGA — R1, fase F5.8 ════
                 *
                 * 📌 *«voglio che la palestra possa acquistare dei token da
                 * dare all'allievo … Quelli acquistati in questo modo devono
                 * andare direttamente all'allievo»*.
                 *
                 * 🚨 **Si verifica QUI che sia davvero un suo iscritto**, e si
                 * riverifica al ritorno. ⛔ Un id scritto a mano in questo campo
                 * accrediterebbe i gettoni a chiunque sulla piattaforma, pagati
                 * da una palestra che voleva regalarli a un suo allievo.
                 */
                'beneficiario_id' => (string) ($this->beneficiarioValido($utente, $perConto)?->getKey() ?? ''),

                /*
                 * 🆕 🎁 **Le promozioni usate, scritte qui** — fase F2.
                 *
                 * 🚨 È l'unico modo che ha il webhook di sapere quali segnare
                 * come spese: quello che non sta nei metadata, al ritorno non
                 * esiste.
                 */
                'promozioni' => $this->sconti->perIMetadata($sconto['promozioni']),

                // 🆕 🪙 P2 — quelle che danno gettoni in più: li accredita il webhook.
                'bonus' => $this->sconti->perIMetadata($sconto['bonus']),

                // 🆕 🧪🔒 P3 — prova gratuita e prezzo bloccato, letti da `accendiLAbbonamento()`.
                'prova_giorni' => (string) ($sconto['prova_giorni'] ?? ''),
                'prezzo_bloccato' => $sconto['prezzo_bloccato'] ? '1' : '',
            ],

            /*
             * 💡 `discounts` c'è solo se c'è davvero uno sconto: passarlo
             * vuoto farebbe rifiutare la sessione da Stripe.
             */
            ...($sconto['discounts'] ?? [] ? ['discounts' => $sconto['discounts']] : []),

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
                'subscription_data' => [
                    'metadata' => $comune['metadata'],

                    // 🆕 🧪 P3 — la prova gratuita: carta subito, addebito dopo N giorni.
                    ...($sconto['prova_giorni'] !== null ? ['trial_period_days' => $sconto['prova_giorni']] : []),
                ],

                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $this->listino->singolo(),
                        // 🆕 F9.2 — la temporalità del piano Plus, se è stata cambiata.
                        'recurring' => ['interval' => Plan::query()->where('code', Plan::PLUS)->first()?->intervalloStripe() ?? 'month'],
                        'product_data' => [
                            'name' => 'Assistente AI',
                            'description' => $this->listino->chiamateMensili()
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
                        // 🆕 P2 — il bonus si vede già sulla pagina di pagamento.
                        'product_data' => ['name' => "{$gettoni} gettoni AI"
                            .($sconto['gettoni_bonus'] > 0 ? " (+{$sconto['gettoni_bonus']} in regalo)" : '')],
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

    /**
     * 🔁 Accende o spegne il rinnovo automatico — 08/09/2026.
     *
     * 📌 Il committente: *«l'abbonamento ovviamente si deve rinnovare
     * automaticamente SE E SOLO SE l'utente flagga il toggle "Rinnova in
     * automatico"»*.
     *
     * ══ 🚨 SI SCRIVE SU STRIPE, E LA RIGA NOSTRA LA AGGIORNA IL WEBHOOK ═══
     *
     * ⛔ **La verita' non e' nel nostro database.** Chi rinnova e' Stripe: se
     * scrivessimo `rinnova` da soli senza dirlo a lui, l'app mostrerebbe
     * «non si rinnova» e il mese dopo la carta verrebbe addebitata lo stesso.
     * 🚨 E' il difetto peggiore possibile qui — un'app che dice una cosa e un
     * conto corrente che ne fa un'altra.
     *
     * 💡 Quindi si chiama Stripe, e la nostra riga si aggiorna **due volte**:
     * subito, perche' l'app deve rispondere adesso, e poi di nuovo quando
     * arriva `customer.subscription.updated`. ⚠️ I due valori coincidono: il
     * secondo non e' una correzione, e' una conferma.
     *
     * ⛔ **Non disdice niente.** `cancel_at_period_end` lascia l'abbonamento
     * attivo fino alla fine del periodo gia' pagato: chi spegne il rinnovo il
     * giorno dopo aver pagato tiene il mese che ha comprato.
     */
    /**
     * Quanti giorni si resta abbonati DOPO la fine del periodo pagato.
     *
     * == PERCHE' ESISTE, E PERCHE' NON E' ZERO ==
     *
     * Il committente: "se un utente e' abbonato e ha il rinnovo automatico
     * attivo, non deve avere momenti in cui il suo abbonamento gli risulta
     * rimosso, altrimenti gli appaiono di nuovo tutte le modali".
     *
     * Senza questi giorni `ends_at` cade ESATTAMENTE alla fine del periodo, e
     * fra quell'istante e l'arrivo del webhook la persona risulta non
     * abbonata: card sfumate, un allenamento al giorno, e la modale "il tuo
     * abbonamento e' scaduto". Poi al rinnovo, "hai sbloccato l'AI".
     *
     * Nel caso buono e' questione di secondi. Ma se la carta chiede il 3DS o
     * rifiuta, Stripe RITENTA PER GIORNI: per lui l'abbonamento e' ancora
     * attivo (`past_due`), per noi no. Sette giorni coprono la finestra di
     * ritentativi che Stripe usa di serie.
     *
     * ATTENZIONE: la grazia vale SOLO a rinnovo acceso. Chi ha disdetto non
     * ha niente da aspettare, e regalargli una settimana vorrebbe dire dirgli
     * una data e rispettarne un'altra.
     */
    private const GIORNI_DI_GRAZIA = 7;

    public function rinnovoAutomatico(User $utente, bool $rinnova): void
    {
        $riga = $this->abbonamentoDaCambiare($utente);

        if ($riga === null) {
            throw new InvalidArgumentException('Non c\'e\' nessun abbonamento da cambiare.');
        }

        /*
         * ══ 🎁 IL REGALO NON PASSA DA STRIPE — 08/09/2026 ═════════════════
         *
         * 📌 Il committente: *«deve funzionare anche con gli abbonamenti che
         * regalo»*.
         *
         * ⛔ **Prima questo metodo lanciava** per chi non aveva un id di Stripe,
         * e l'app nascondeva il toggle: chi aveva un abbonamento regalato non
         * poteva ne' spegnerlo ne' vedere che esistesse la scelta.
         *
         * 💡 Qui non c'e' nessuno da avvisare: a rinnovare un regalo e'
         * `abbonamenti:rinnova-i-regali`, che guarda **questa** colonna. La
         * spunta governa qualcosa davvero.
         */
        /*
         * ══ 🆕 📊 IL PIANO A CONSUMO — 25/09/2026 ══════════════════════════
         *
         * 📌 Il committente: una palestra deve poter **disdire da sola** dal
         * pannello («Il tuo piano»), senza il portale di Stripe.
         *
         * ⛔ **Il consumo non ha un abbonamento su Stripe**: lo fattura
         * `fattura:il-consumo` a mese chiuso, per **ogni mese in cui il piano è
         * stato vivo** (`AddebitoDelMese::palestreAConsumo()`). Finiva nel ramo
         * del regalo qui sotto, che fissa la fine a «un mese dall'inizio» —
         * cioè, per un consumo aperto a marzo, **adesso**, a metà di un mese che
         * si paga lo stesso.
         *
         * 💡 Quindi: disdire = finisce **l'ultimo giorno di questo mese** (quel
         * mese si paga comunque, tanto vale usarlo); ripensarci = torna senza
         * scadenza, com'era.
         */
        if ($riga->stripe_subscription_id === null && $riga->plan?->aConsumo()) {
            $riga->update([
                'rinnova' => $rinnova,
                'ends_at' => $rinnova ? null : Carbon::now()->endOfMonth(),
            ]);

            Log::info('Consumo: disdetta cambiata.', [
                'abbonamento' => $riga->getKey(),
                'rinnova' => $rinnova,
                'fino_al' => (string) $riga->ends_at,
            ]);

            return;
        }

        if ($riga->stripe_subscription_id === null) {
            $riga->update([
                'rinnova' => $rinnova,

                /*
                 * ══ 🚨 SPEGNERE DEVE VOLER DIRE QUALCOSA ══════════════════
                 *
                 * ⛔ **Un regalo nasce senza scadenza** (`ends_at = null`, la
                 * convenzione «non finisce mai»). Su una riga cosi', `rinnova
                 * = false` non cambierebbe **niente**: non c'e' nessuna data a
                 * cui smettere, e l'abbonamento resterebbe attivo per sempre
                 * con l'app che dice «non si rinnova».
                 *
                 * 💡 Quindi spegnendo si fissa la fine: **un mese dal
                 * regalo**. ⚠️ Se quel mese e' gia' passato — un regalo di
                 * marzo — finisce **adesso**, ed e' la risposta giusta: il
                 * periodo era finito da un pezzo, lo teneva in piedi solo il
                 * rinnovo che si e' appena spento.
                 */
                'ends_at' => $rinnova
                    ? $riga->ends_at
                    : ($riga->ends_at ?? $this->fineDelRegalo($riga)),
            ]);

            Log::info('Regalo: rinnovo automatico cambiato.', [
                'abbonamento' => $riga->getKey(),
                'rinnova' => $rinnova,
                'fino_al' => (string) $riga->ends_at,
            ]);

            return;
        }

        $this->stripe()->subscriptions->update(
            (string) $riga->stripe_subscription_id,
            ['cancel_at_period_end' => ! $rinnova],
        );

        /*
         * 🆕 25/09/2026 — **la data giusta subito**, non al webhook.
         *
         * ⚠️ `ends_at` porta la grazia solo a rinnovo acceso (`conLaGrazia()`):
         * senza questa correzione, chi disdice leggeva «finisce il …» con **sette
         * giorni in più** finché non arrivava `customer.subscription.updated`. E
         * chi ci ripensava li perdeva. Il webhook poi scrive lo stesso valore.
         */
        $fine = $riga->ends_at;

        if ($fine !== null && (bool) $riga->rinnova !== $rinnova) {
            $fine = $rinnova
                ? $fine->copy()->addDays(self::GIORNI_DI_GRAZIA)
                : $fine->copy()->subDays(self::GIORNI_DI_GRAZIA);
        }

        $riga->update(['rinnova' => $rinnova, 'ends_at' => $fine]);

        Log::info('Stripe: rinnovo automatico cambiato.', [
            'abbonamento' => $riga->stripe_subscription_id,
            'rinnova' => $rinnova,
        ]);
    }

    /**
     * Quando finisce un regalo a cui non era stata data una scadenza.
     *
     * 💡 Un mese dal giorno in cui e' stato fatto — e non da adesso: chi lo
     * spegne oggi non si sta comprando un altro mese.
     */
    private function fineDelRegalo(PlanSubscription $riga): Carbon
    {
        $fine = $riga->starts_at?->copy()->addMonth()->addDay() ?? now();

        return $fine->isPast() ? now() : $fine;
    }

    /**
     * 🛑 Disdice **subito** un abbonamento pagato — 09/09/2026.
     *
     * 📌 Il committente: *«dal pannello god devo poter anche togliere un
     * abbonamento pagato con stripe quando voglio»*.
     *
     * ══ 🚨 SI DISDICE SU STRIPE, NON SI CANCELLA DA NOI ═══════════════════
     *
     * ⛔ **Cancellare la nostra riga e basta sarebbe il difetto piu' costoso di
     * tutto il progetto**: la persona perderebbe l'accesso e **la carta
     * continuerebbe a essere addebitata ogni mese**, perche' a fatturare e'
     * Stripe e Stripe non saprebbe niente. 🚨 Nessun errore da nessuna parte, e
     * ce ne accorgeremmo da un reclamo — o da una segnalazione alla banca.
     *
     * ⚠️ **`cancel()` e non `cancel_at_period_end`.** Qui il committente ha
     * chiesto «quando voglio», cioe' **adesso**: chi lo usa sta togliendo
     * l'accesso, non programmando una scadenza. 💡 Per la scadenza a fine mese
     * c'e' gia' [rinnovoAutomatico].
     *
     * ⛔ **Non rimborsa niente.** Stripe non restituisce il rateo di sua
     * iniziativa, e farlo da qui vorrebbe dire muovere soldi da un pannello di
     * amministrazione: il rimborso si fa da Stripe, a mano, guardando il caso.
     * 🚨 La finestra di conferma lo dice, perche' chi preme deve saperlo prima.
     *
     * 💡 La nostra riga si chiude **subito** e non si aspetta il webhook: il
     * pannello deve dire la verita' nell'istante in cui si ricarica. Quando
     * `customer.subscription.deleted` arrivera', trovera' la stessa cosa.
     */
    public function disdiciSubito(User $utente): void
    {
        $riga = $this->abbonamentoPagatoDi($utente);

        if ($riga === null) {
            throw new InvalidArgumentException('Non c\'e\' nessun abbonamento pagato da disdire.');
        }

        $this->stripe()->subscriptions->cancel((string) $riga->stripe_subscription_id);

        $riga->update(['ends_at' => now(), 'rinnova' => false]);

        Log::warning('Stripe: abbonamento pagato disdetto dal pannello.', [
            'abbonamento' => $riga->stripe_subscription_id,
            'tenant' => $riga->tenant_id,
        ]);
    }

    /** L'abbonamento **pagato** e attivo di questa persona, se ce n'e' uno. */
    public function abbonamentoPagatoDi(User $utente): ?PlanSubscription
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stripe_subscription_id')
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * L'abbonamento su cui si puo' cambiare il rinnovo.
     *
     * ══ ⛔ LE PALESTRE RESTANO FUORI, E NON E' UN DETTAGLIO ════════════════
     *
     * 🚨 L'abbonamento di una palestra e quello regalato si somigliano
     * moltissimo: **nessuno dei due ha un id di Stripe**, e tutti e due possono
     * avere `ends_at = null`. ⚠️ Guardando solo Stripe si finirebbe per far
     * spegnere a un iscritto il rinnovo **della sua palestra** — che paga
     * qualcun altro, per decine di persone.
     *
     * 💡 La differenza sta nel **tipo del tenant**: `personal` e' suo,
     * `gym` non lo e'.
     */
    private function abbonamentoDaCambiare(User $utente): ?PlanSubscription
    {
        if (! self::loPuoGestire($utente)) {
            return null;
        }

        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $utente->tenant?->getKey())
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Questa persona puo' toccare l'abbonamento del suo tenant?
     *
     * == IL BUCO CHE QUESTA FUNZIONE CHIUDE -- 10/09/2026 ==
     *
     * L'abbonamento sta sul TENANT, non sulla persona. Prima il controllo sul
     * tipo di tenant scattava solo quando NON c'era Stripe di mezzo:
     *
     *     if ($riga->stripe_subscription_id === null && $tenant non personale)
     *
     * Il giorno in cui una palestra paga con Stripe, quella condizione e'
     * falsa per ognuno dei suoi iscritti: ognuno di loro avrebbe potuto
     * spegnere il rinnovo DELLA PALESTRA, per tutti. Latente oggi solo perche'
     * nessuna palestra paga ancora con Stripe -- cioe' esplode il giorno in cui
     * si decide quanto costa una palestra (3b-Z), non prima.
     *
     * La regola giusta e' una sola: comanda CHI PAGA.
     *
     * == E DAL 12/09/2026 LA DOMANDA E' UNA SOLA -- 3b-AH, Passo 0 ==
     *
     * Prima erano due casi:
     *
     *     tenant personale -> chiunque ci stia dentro
     *     palestra         -> solo il gym_admin
     *
     * Funzionavano perche' in un tenant personale ci stava UNA PERSONA SOLA:
     * "personale" voleva dire "tuo". Da AH.1 in poi non e' piu' vero -- nel
     * tenant di un trainer ci stanno anche i suoi allievi -- e la prima riga
     * sarebbe diventata "ogni allievo puo' disdire l'abbonamento del suo
     * trainer". Lo stesso buco delle palestre, riaperto il giorno dopo averlo
     * chiuso, e in un tenant che il tipo continuava a chiamare "personale".
     *
     * Adesso si chiede quello che si vuole sapere davvero: SEI TU IL
     * PROPRIETARIO? Un caso solo, e la risposta sta scritta in una colonna
     * invece che dedotta da due cose diverse.
     *
     * ATTENZIONE alla direzione dell'errore: se `tenants.proprietario_id`
     * fosse vuoto su un tenant che paga, chi paga NON vedrebbe i comandi. Si
     * scopre subito, e si perde una persona. E' il lato caro e visibile
     * dell'avvertenza di AH -- accettabile solo perche' esiste il comando
     * `abbonamenti:tenant-senza-proprietario`, che lo dice prima che lo dica
     * un cliente.
     */
    public static function loPuoGestire(?User $utente): bool
    {
        $tenant = $utente?->tenant;

        if ($tenant === null) {
            return false;
        }

        return $tenant->eDi($utente);
    }

    /** Il cliente Stripe di questa persona, se ne ha uno. */
    public function clienteDi(User $utente): ?string
    {
        /*
         * Stessa guardia del rinnovo, e per un motivo piu' grave: il portale di
         * Stripe non serve solo a disdire, mostra anche LE FATTURE. Un iscritto
         * che lo apre vede i pagamenti della palestra, e puo' cambiarle la
         * carta.
         */
        if (! self::loPuoGestire($utente)) {
            return null;
        }

        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return $this->clienteEsistente(PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stripe_customer_id')
            ->orderByDesc('starts_at')
            ->value('stripe_customer_id'));
    }

    /**
     * 🆕 **Il cliente Stripe, solo se su Stripe esiste davvero** — 23/09/2026, F8.6.
     *
     * 🚨 **Il difetto dietro «passa a questo» e «Carte, ricevute e disdetta»
     * che davano errore.** Il cliente si legge dall'ultimo abbonamento, anche
     * scaduto; ma un id salvato può non esistere più su Stripe — un
     * abbonamento assegnato a mano con un id di prova, un cliente cancellato
     * dalla dashboard, un id della modalità live letto con le chiavi di test.
     * Stripe rispondeva *«No such customer»* e il pagamento non si apriva.
     * 🔬 Riprodotto in locale col cliente `cus_finto_demo` della palestra
     * dimostrativa, e con la sandbox vera.
     *
     * 💡 Se non esiste si torna `null`, e chi chiama fa quello che fa per un
     * cliente nuovo (Stripe ne crea uno dall'email). ⚠️ Solo «non esiste» si
     * perdona: un errore di rete **resta un errore**, e la pagina dice di
     * riprovare — creare un cliente nuovo perché la rete ha avuto un singhiozzo
     * vorrebbe dire due clienti per la stessa palestra.
     */
    public function clienteEsistente(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        try {
            $cliente = $this->stripe()->customers->retrieve($id);
        } catch (InvalidRequestException $e) {
            Log::warning('Stripe: il cliente salvato non esiste, se ne usa uno nuovo.', [
                'cliente' => $id,
                'errore' => $e->getMessage(),
            ]);

            return null;
        }

        return ($cliente->deleted ?? false) === true ? null : $id;
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
     * 🆕 19/09/2026: dice **com'è andata** — `true` se è stato elaborato (anche
     * un evento che non ci riguarda: non c'era niente da fare, ed è fatto),
     * `false` se è finito nel `catch`. Il webhook ci scrive `processed_at`.
     *
     * @param  array<string, mixed>  $evento
     */
    public function applica(array $evento): bool
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
             * ⚠️ `invoice.payment_failed` **non spegne niente, ed e' voluto**:
             * la carta rifiutata non toglie niente, Stripe riprova per giorni, e
             * l'abbonamento scade da solo se non ci riesce. 💡 Spegnerlo al
             * primo tentativo fallito vorrebbe dire punire chi ha cambiato carta
             * di venerdi'.
             *
             * 🆕 **Ma da §9.4 (18/09/2026) si SEGNA.** Prima questo evento era
             * ignorato del tutto, e la ragione qui sopra — giusta — copriva un
             * buco: chi paga non aveva **nessun modo di saperlo** finché il
             * servizio non si spegneva. 🚨 E per il piano a consumo, che non e'
             * un abbonamento, era l'**unico** segnale che una palestra non aveva
             * pagato. Adesso si scrive quando e' fallito, e si toglie al primo
             * pagamento riuscito: si avvisa, non si punisce.
             */
            match ($evento['type'] ?? null) {
                'checkout.session.completed' => $this->primoPagamento($oggetto),
                'invoice.paid' => $this->rinnovo($oggetto),
                'invoice.payment_failed' => $this->pagamentoFallito($oggetto),
                'customer.subscription.updated' => $this->abbonamentoCambiato($oggetto),
                'customer.subscription.deleted' => $this->abbonamentoFinito($oggetto),
                default => null,
            };

            return true;
        } catch (Throwable $e) {
            Log::error('Stripe: incasso fallito.', [
                'errore' => $e->getMessage(),
                'evento' => $evento['id'] ?? null,
            ]);

            return false;
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
            self::GETTONI => $this->accreditaGettoni(
                $utente,
                (int) ($meta['gettoni'] ?? 0),
                $sessione,
                // 🎁 R1 — a chi vanno davvero: vedi `accreditaGettoni()`.
                $this->beneficiarioDi($utente, $meta),
            ),
            self::ABBONAMENTO => $this->accendiLAbbonamento($utente, $sessione),

            /*
             * 🆕 💳 **I piani di trainer e palestre** — §9.2, 18/09/2026.
             *
             * ⚠️ Stanno in `PianiInVendita`, che tiene **tutte e due** le metà —
             * chi apre e chi incassa — per la regola scritta in cima a questa
             * classe: i `metadata` scritti all'apertura sono l'unica cosa che si
             * ritrova qui, e due file che li scrivono e li leggono divergono.
             */
            PianiInVendita::TIPO => app(PianiInVendita::class)->accendi($sessione),

            // 🆕 💳 La carta nuova, dal pannello (dopo F8): vedi `CambioDellaCarta`.
            CambioDellaCarta::TIPO => app(CambioDellaCarta::class)->applica($sessione),

            default => Log::warning('Stripe: pagamento di tipo ignoto.', ['tipo' => $meta['tipo'] ?? null]),
        };

        /*
         * 🆕 🔓 **Ha pagato: se era sospeso dalla macchina, torna su** — 27/09/2026.
         *
         * ⚠️ Sta **dopo** il `match` e non dentro uno dei suoi rami, perché la
         * domanda è la stessa per tutti: *«questo spazio adesso ha un abbonamento
         * vivo?»*. 💡 `seHaRipagato()` la fa da sé e non fa niente se la risposta
         * è no — quindi un pacchetto di gettoni non riattiva niente, ed è giusto:
         * ⛔ i gettoni non sono l'abbonamento.
         */
        app(LaSospensioneAutomatica::class)->seHaRipagato($utente->tenant_id);

        /*
         * 🆕 🐛 **Le promozioni usate si segnano QUI** — P1.6 di `plan_promozioni.md`.
         *
         * ⛔ Fino al 23/09 i codici finivano nei `metadata` e al ritorno non li
         * rileggeva nessuno: `usata_volte` restava a zero e `massimo_usi` non
         * fermava niente. 💡 Si segnano a pagamento riuscito — non all'apertura
         * della sessione, che si può abbandonare — e l'id della sessione rende
         * la cosa idempotente se Stripe rimanda l'evento.
         */
        $segnate = app(LePromozioni::class)->segnaUsate(
            (string) ($meta['promozioni'] ?? ''),
            $utente,
            (string) ($sessione['id'] ?? ''),
            (int) ($sessione['total_details']['amount_discount'] ?? 0),
        );

        /*
         * 🆕 🪙 **Il bonus in gettoni** — P2. Dopo l'accredito dei gettoni
         * comprati, e a **chi li riceve** (l'allievo, se ha pagato la palestra).
         */
        if (($meta['tipo'] ?? null) === self::GETTONI && ($meta['bonus'] ?? '') !== '') {
            $gettoni = (int) ($meta['gettoni'] ?? 0);

            app(ConsegnaDeiPremi::class)->bonusDellAcquisto(
                $segnate,
                array_values(array_filter(array_map('trim', explode(',', (string) $meta['bonus'])))),
                $this->beneficiarioDi($utente, $meta) ?? $utente,
                $gettoni,
                PacchettoGettoni::query()->where('gettoni', $gettoni)->value('id'),
            );
        }

        $this->eventiDelPagamento($utente, $sessione);
    }

    /**
     * 🆕 ⚡ P6 — gettoni «a ogni rinnovo» ed evento `rinnovo` (col numero: il primo
     * rinnovo è il **secondo** pagamento).
     */
    private function eventiDelRinnovo(PlanSubscription $riga, string $idFattura): void
    {
        $titolare = $riga->tenant_id !== null
            ? User::withoutGlobalScopes()->whereKey(
                Tenant::query()->withoutGlobalScopes()->whereKey($riga->tenant_id)->value('proprietario_id'),
            )->first()
            : null;

        if ($titolare === null) {
            return;
        }

        $pagamenti = PagamentoIncassato::withoutGlobalScopes()
            ->where('tenant_id', $riga->tenant_id)
            ->where('tipo', PagamentoIncassato::CANONE)
            ->count();

        app(ConsegnaDeiPremi::class)->gettoniDelPagamento($titolare, $riga->plan_id, $idFattura);
        app(GliEventi::class)->succede(EventoDiPromozione::Rinnovo, $titolare, $idFattura, ['numero' => max(1, $pagamenti - 1)]);
    }

    /**
     * 🆕 ⚡ **Gli eventi delle promozioni al primo pagamento** — P6 di `plan_promozioni.md`.
     *
     * | Pagamento | Evento |
     * |---|---|
     * | abbonamento (Plus, o un piano trainer/palestra) | `primo_abbonamento` + i gettoni «a ogni rinnovo» del primo periodo |
     * | gettoni | `acquisto_gettoni` |
     * | qualunque, se la persona è arrivata con un amico | `amico_arrivato` con `quando = primo_acquisto` (una volta sola: il riferimento è la persona) |
     *
     * ⚠️ Il primo periodo dei gettoni «a ogni rinnovo» si dà **qui**, con l'id
     * della sessione; `rinnovo()` salta la fattura `subscription_create`, così
     * lo stesso periodo non si premia due volte.
     *
     * @param  array<string, mixed>  $sessione
     */
    private function eventiDelPagamento(User $utente, array $sessione): void
    {
        $meta = $sessione['metadata'] ?? [];
        $tipo = $meta['tipo'] ?? null;
        $riferimento = (string) ($sessione['id'] ?? '');

        if ($riferimento === '' || ! in_array($tipo, [self::ABBONAMENTO, self::GETTONI, PianiInVendita::TIPO], true)) {
            return;
        }

        $eventi = app(GliEventi::class);
        $abbonamento = $tipo === self::ABBONAMENTO
            || ($tipo === PianiInVendita::TIPO && ($sessione['mode'] ?? null) === 'subscription');

        if ($abbonamento) {
            $planId = $tipo === self::ABBONAMENTO
                ? Plan::query()->where('code', Plan::PLUS)->value('id')
                : Plan::query()->where('code', (string) ($meta['piano'] ?? ''))->value('id');

            $eventi->succede(EventoDiPromozione::PrimoAbbonamento, $utente, $riferimento);
            app(ConsegnaDeiPremi::class)->gettoniDelPagamento($utente, $planId, $riferimento);
        }

        if ($tipo === self::GETTONI) {
            $eventi->succede(EventoDiPromozione::AcquistoGettoni, $utente, $riferimento);
        }

        $invitante = $utente->invitato_da_id !== null ? User::withoutGlobalScopes()->find($utente->invitato_da_id) : null;

        if ($invitante !== null) {
            $eventi->succede(EventoDiPromozione::AmicoArrivato, $utente, (string) $utente->getKey(), [
                'quando' => 'primo_acquisto',
                'invitante' => $invitante,
            ]);
        }
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
        /*
         * 🆕 💶 **La fattura del piano a consumo è pagata QUI** — §9.4, 18/09.
         *
         * ⛔ Prima `AddebitoDelMese` la segnava «incassata» appena **emessa**:
         * con la carta rifiutata, il pannello della palestra diceva «Pagato» su
         * una fattura mai pagata. Adesso l'emissione la segna `emesso`, e solo
         * questo evento la fa diventare `incassato`.
         */
        if ($this->eUnAddebitoAConsumo($fattura)) {
            $this->addebitoPagato($fattura);

            return;
        }

        $idAbbonamento = $this->idAbbonamento($fattura);

        if ($idAbbonamento === null) {
            // 💡 Le fatture che non riguardano un abbonamento (i pacchetti di
            // gettoni) passano di qui e non devono fare niente.
            return;
        }

        $riga = $this->abbonamentoDi($idAbbonamento);

        /*
         * 🆕 💰 **Il rinnovo è un incasso, e prima non lo scriveva nessuno** —
         * fase F3.
         *
         * 🚨 Era il buco più grosso del conto: un cliente a canone da un anno
         * risultava non aver mai pagato niente, perché l'unico posto in cui
         * quel pagamento esisteva era Stripe.
         */
        if ($riga !== null) {
            $this->registro->segna(
                tenant: $riga->tenant_id,
                tipo: PagamentoIncassato::CANONE,
                importoCent: (int) ($fattura['amount_paid'] ?? 0),
                stripeId: $fattura['id'] ?? null,
                cosa: $riga->plan?->code,
            );
        }

        if ($riga === null) {
            Log::error('Stripe: rinnovo di un abbonamento che non conosciamo.', [
                'subscription' => $idAbbonamento,
            ]);

            return;
        }

        $fine = $this->fineDelPeriodo($fattura);

        $riga->update([
            // La grazia si legge dalla riga: se ha disdetto, `rinnova` e' gia'
            // `false` e la data resta quella vera.
            'ends_at' => $this->conLaGrazia(
                $fine ?? now()->addMonth(),
                (bool) $riga->rinnova,
            ),
        ]);

        /*
         * 🆕 **Pagato: il segno del rifiuto se ne va** — §9.4. ⚠️ `forceFill`
         * perché la colonna non è assegnabile in massa: la scrive solo Stripe,
         * attraverso questa classe.
         */
        $riga->forceFill(['pagamento_fallito_il' => null])->save();

        /*
         * 🆕 🔓 **E se era sospeso dalla macchina, torna su SUBITO** — 27/09/2026.
         *
         * 🚨 Dal 27/09 chi non paga viene sospeso da un giro notturno
         * (`LaSospensioneAutomatica`). ⛔ Senza questa riga, chi ripaga
         * resterebbe sospeso **fino alla notte successiva**: una macchina che
         * prende i soldi e non ridà il servizio, che è peggio del buco che
         * quella classe è venuta a chiudere.
         *
         * ⚠️ **Riattiva solo ciò che ha sospeso lei**: una sospensione decisa da
         * una persona in `/god` — per un abuso, per esempio — non si annulla
         * perché arriva un bonifico. Il controllo sta dentro
         * `seHaRipagato()`, non qui: è pubblico e lo chiama anche il giro
         * notturno, e un controllo che sta solo nel chiamante è un controllo che
         * il secondo chiamante non ha.
         */
        app(LaSospensioneAutomatica::class)->seHaRipagato($riga->tenant_id);

        /*
         * 🆕 ⚡ P6 — **il rinnovo per le promozioni**: gettoni «a ogni rinnovo»
         * ed evento `rinnovo` col suo numero, al **titolare** dello spazio.
         * ⚠️ Solo i rinnovi veri (`subscription_cycle`): la prima fattura
         * (`subscription_create`) la premia già `eventiDelPagamento()`.
         */
        if (($fattura['billing_reason'] ?? 'subscription_cycle') === 'subscription_cycle' && ($fattura['id'] ?? null) !== null) {
            $this->eventiDelRinnovo($riga, (string) $fattura['id']);
        }

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

        $rinnova = ! ($abbonamento['cancel_at_period_end'] ?? false);
        $fine = $this->quando($abbonamento['current_period_end'] ?? null);

        $riga->update([
            'rinnova' => $rinnova,

            /*
             * La grazia si calcola sul valore NUOVO di `rinnova`, non su quello
             * in archivio: questo evento e' proprio quello che arriva quando si
             * disdice, e leggendo il vecchio si darebbe una settimana in piu'
             * a chi ha appena detto che non vuole rinnovare.
             */
            'ends_at' => $fine !== null
                ? $this->conLaGrazia($fine, $rinnova)
                : $riga->ends_at,
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
    /**
     * 🆕 💳 **Un pagamento non è andato** — §9.4, 18/09/2026.
     *
     * ⚠️ **Si segna e basta**: non si spegne niente. Stripe riprova per giorni, e
     * la fine vera arriva con `customer.subscription.deleted` se non ci riesce.
     *
     * 💡 Due casi, e il secondo era senza nessun altro segnale:
     *
     * | Fattura | Cosa si segna |
     * |---|---|
     * | di un abbonamento | `plan_subscriptions.pagamento_fallito_il` |
     * | del piano a consumo | `addebiti_mensili.stato = fallito`, con l'errore |
     *
     * @param  array<string, mixed>  $fattura
     */
    private function pagamentoFallito(array $fattura): void
    {
        if ($this->eUnAddebitoAConsumo($fattura)) {
            $addebito = $this->addebitoDellaFattura($fattura);

            $addebito?->forceFill([
                'stato' => AddebitoMensile::FALLITO,
                'errore' => 'Il pagamento non è andato a buon fine. Stripe riproverà nei prossimi giorni.',

                /*
                 * 🆕 ⏱️ **Da quando non è pagata** — 27/09/2026.
                 *
                 * 📌 Il committente: *«quando fallisce il pagamento si sospende,
                 * stessa cosa di tutti gli abbonamenti»*. 🔑 Il consumo è un
                 * abbonamento a prezzo variabile, quindi segue la stessa regola —
                 * ma il segnale «Stripe ha smesso di riprovare» qui **non arriva**
                 * come evento, perché la fattura del mese non è un ciclo di
                 * abbonamento. 💡 Quindi si conta il tempo.
                 *
                 * ⚠️ **`??` e non un'assegnazione secca**: la data è quella della
                 * **prima** insolvenza. ⛔ Riscriverla a ogni ritentativo di Stripe
                 * rimanderebbe la sospensione in avanti a ogni tentativo, e non
                 * arriverebbe mai.
                 */
                'fallito_il' => $addebito->fallito_il ?? now(),
            ])->save();

            Log::warning('Stripe: addebito a consumo non pagato.', ['fattura' => $fattura['id'] ?? null]);

            $spazio = $addebito?->tenant_id === null
                ? null
                : Tenant::withoutGlobalScopes()->find($addebito->tenant_id);

            $this->avvisaDelPagamentoFallito($spazio, 'la fattura del mese');

            return;
        }

        $idAbbonamento = $this->idAbbonamento($fattura);
        $riga = $idAbbonamento === null ? null : $this->abbonamentoDi($idAbbonamento);

        if ($riga === null) {
            return;
        }

        /*
         * 💡 **Si tiene la PRIMA data di rifiuto**, non l'ultima: Stripe riprova
         * più volte, e la domanda che conta è *«da quanto non paga?»*.
         */
        if ($riga->pagamento_fallito_il === null) {
            $riga->forceFill(['pagamento_fallito_il' => now()])->save();

            /*
             * 🚨 **Solo la prima volta.** Stripe riprova per giorni e a ogni
             * tentativo manda un altro webhook: avvisare ogni volta vorrebbe
             * dire quattro mail identiche a chi ha gia' capito. La colonna
             * `pagamento_fallito_il` e' la memoria di «gliel'abbiamo detto».
             */
            $this->avvisaDelPagamentoFallito(
                Tenant::withoutGlobalScopes()->find($riga->tenant_id),
                'l\'abbonamento',
            );
        }

        Log::warning('Stripe: pagamento di un abbonamento non riuscito.', ['subscription' => $idAbbonamento]);
    }

    /**
     * 🆕 📧 **Avvisa chi paga che il pagamento non e' riuscito** — 22/09/2026.
     *
     * ⛔ Prima finiva solo nel log: chi paga non lo sapeva, non cambiava la
     * carta, e l'abbonamento scadeva per una cosa che si sistema in due minuti.
     *
     * ⚠️ Si scrive a `Tenant::aChiScrivere()` — il proprietario, o il gym_admin
     * piu' vecchio — e **mai** a un iscritto a caso: una mail sui soldi mandata
     * a chi non paga e' un incidente, non un ripiego.
     *
     * 🚨 Dentro un `try`: qui siamo dentro un webhook di Stripe, e un'eccezione
     * farebbe rispondere 500. Stripe riproverebbe il webhook, e il webhook
     * rifarebbe il lavoro gia' fatto.
     */
    private function avvisaDelPagamentoFallito(?Tenant $spazio, string $quale): void
    {
        if ($spazio === null) {
            return;
        }

        $chi = $spazio->aChiScrivere();

        if ($chi === null) {
            Log::warning('Pagamento fallito: nessuno a cui scrivere.', ['tenant' => $spazio->getKey()]);

            return;
        }

        try {
            Mail::to($chi->email)->send(new PagamentoFallitoMail($spazio, $quale));
        } catch (Throwable $e) {
            Log::error('Pagamento fallito: avviso non partito.', [
                'tenant' => $spazio->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🆕 **È una fattura del piano a consumo?** La riconoscono i `metadata` che
     * `AddebitoDelMese` scrive quando la emette.
     *
     * @param  array<string, mixed>  $fattura
     */
    private function eUnAddebitoAConsumo(array $fattura): bool
    {
        return ($fattura['metadata']['tipo'] ?? null) === 'addebito_a_consumo';
    }

    /** @param  array<string, mixed>  $fattura */
    private function addebitoDellaFattura(array $fattura): ?AddebitoMensile
    {
        $id = $fattura['metadata']['addebito_id'] ?? null;

        return $id === null ? null : AddebitoMensile::withoutGlobalScopes()->find($id);
    }

    /**
     * 🆕 La fattura a consumo è pagata: adesso sì, `incassato`.
     *
     * @param  array<string, mixed>  $fattura
     */
    private function addebitoPagato(array $fattura): void
    {
        $addebito = $this->addebitoDellaFattura($fattura);

        $addebito?->forceFill([
            'stato' => AddebitoMensile::INCASSATO,
            'incassato_il' => now(),
            'errore' => null,

            /*
             * 🆕 🔓 **Pagata: l'insolvenza se ne va** — 27/09/2026.
             *
             * 🚨 Senza questa riga la sospensione automatica non si annullerebbe
             * mai per un cliente a consumo: pagherebbe e resterebbe sospeso.
             * ⛔ Una macchina che prende i soldi e non ridà il servizio è il
             * risultato peggiore possibile.
             */
            'fallito_il' => null,
        ])->save();

        /*
         * 🆕 💰 E nel registro degli incassi — fase F3.
         *
         * 💡 Qui l'importo si legge dalla **fattura di Stripe** e non
         * dall'addebito: se il conguaglio o uno sconto l'hanno cambiata, quello
         * che è entrato in cassa è il primo, non il secondo.
         */
        if ($addebito !== null) {
            $this->registro->segna(
                tenant: $addebito->tenant_id,
                tipo: PagamentoIncassato::CONSUMO,
                importoCent: (int) ($fattura['amount_paid'] ?? $addebito->importo_cent),
                stripeId: $fattura['id'] ?? null,
                cosa: 'consumo '.$addebito->periodo,
            );
        }
    }

    private function abbonamentoFinito(array $abbonamento): void
    {
        $riga = $this->abbonamentoDi((string) ($abbonamento['id'] ?? ''));

        $riga?->update(['ends_at' => now(), 'rinnova' => false]);
    }

    /**
     * La fine del periodo, piu' la grazia se c'e' un rinnovo da aspettare.
     *
     * Un giorno in piu' era gia' scritto in due punti come valore di ripiego
     * ("il rinnovo puo' arrivare con qualche ora di ritardo"): quell'intuizione
     * era giusta e bastava per il caso buono. Non basta per una carta che
     * chiede conferma, ed e' il caso in cui la persona non ha fatto niente di
     * sbagliato.
     */
    private function conLaGrazia(Carbon $fine, bool $rinnova): Carbon
    {
        return $rinnova
            ? $fine->copy()->addDays(self::GIORNI_DI_GRAZIA)
            : $fine->copy();
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
    private function fineDelPeriodo(array $fattura): ?Carbon
    {
        $righe = $fattura['lines']['data'] ?? [];
        $fine = $righe[0]['period']['end'] ?? null;

        return $this->quando($fine)?->addDay();
    }

    private function quando(mixed $istante): ?Carbon
    {
        return is_int($istante) || (is_string($istante) && ctype_digit($istante))
            ? Carbon::createFromTimestamp((int) $istante)
            : null;
    }

    /** @param  array<string, mixed>  $sessione */
    /**
     * @param  User|null  $perConto  🆕 R1 — l'iscritto a cui vanno i gettoni
     *                               quando a pagarli è la palestra.
     */
    private function accreditaGettoni(User $utente, int $gettoni, array $sessione, ?User $perConto = null): void
    {
        if ($gettoni <= 0) {
            Log::error('Stripe: acquisto di gettoni senza taglio.', ['sessione' => $sessione['id'] ?? null]);

            return;
        }

        /*
         * 🆕 🚨 **A LUI, non al suo tenant** — 16/09/2026.
         *
         * ⛔ Qui c'era `$utente->tenant`, e per un iscritto voleva dire che i
         * gettoni che aveva appena pagato finivano nel portafoglio **della
         * palestra**, dove li spendeva chiunque altro.
         *
         * 📌 Il committente: *«ovvio che i gettoni sono soltanto di chi li
         * compra»*. 💡 Questa riga e' il difetto, e questa riga e' la correzione:
         * chi ha comprato lo sappiamo gia', e' il parametro di questo metodo.
         */
        /*
         * ══ 🎁 IL REGALO DELLA PALESTRA — R1, fase F5.8 ════════════════════
         *
         * 🚨 **Il portafoglio resta personale.** Chi paga è `$utente` (la
         * palestra, col suo cliente Stripe); chi riceve è `$perConto`, se c'è.
         * ⛔ Da quel momento i gettoni **sono suoi**: la palestra non può
         * riprenderseli, e se lui se ne va se li porta via.
         *
         * 💡 `pagato_da_tenant_id` resta scritto nel movimento: serve a
         * rispondere a *«e questi da dove escono?»* guardando un saldo cresciuto
         * senza nessun pagamento della persona. ✅ **A5**: agli allievi non si
         * scrive niente — arrivano in silenzio, come un accredito qualunque.
         */
        $destinatario = $perConto ?? $utente;

        $this->portafoglio->accredita(
            $destinatario,
            $gettoni,
            // ⚠️ Nessun operatore: non l'ha accreditato una persona, l'ha
            // pagato il cliente. Metterci lui falserebbe il registro.
            nota: 'Stripe '.($sessione['id'] ?? '?'),
            causale: $perConto === null
                ? AiCreditMovement::ACQUISTO
                : AiCreditMovement::REGALO_PALESTRA,
            pagatoDa: $perConto === null ? null : $utente->tenant_id,
        );

        /*
         * 🆕 💰 **E i soldi finiscono nel registro degli incassi** — fase F3.
         *
         * 🚨 Il movimento qui sopra conta **gettoni**; questo conta **euro**.
         * ⛔ Senza, alla domanda «quanto mi ha dato questa persona» si poteva
         * solo rispondere «guarda su Stripe», che per un cruscotto vuol dire
         * non lo sappiamo.
         *
         * 💡 `amount_total` è quello che il cliente ha **davvero pagato**:
         * comprende lo sconto di un'eventuale promozione, che è esattamente
         * quello che deve entrare in cassa.
         */
        /*
         * ⚠️ **Nel registro degli incassi ci va CHI HA PAGATO**, non chi ha
         * ricevuto: è una riga di cassa, e risponde a «quanto ci ha dato
         * questa palestra». ⛔ Scriverci l'iscritto direbbe che ha speso soldi
         * che non ha speso.
         */
        $this->registro->segna(
            tenant: $utente->tenant_id,
            tipo: PagamentoIncassato::GETTONI,
            importoCent: (int) ($sessione['amount_total'] ?? 0),
            stripeId: $sessione['id'] ?? null,
            utente: $utente,
            cosa: $perConto === null
                ? $gettoni.' gettoni'
                : $gettoni.' gettoni per '.$perConto->name,
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
        /*
         * 🆕 💰 Il primo pagamento dell'abbonamento personale — fase F3.
         *
         * ⚠️ I **rinnovi** non passano di qui: arrivano con `invoice.paid`, e
         * si segnano là (vedi `rinnovo()`).
         */
        $this->registro->segna(
            tenant: $utente->tenant_id,
            tipo: PagamentoIncassato::CANONE,
            importoCent: (int) ($sessione['amount_total'] ?? 0),
            stripeId: $sessione['id'] ?? null,
            utente: $utente,
            cosa: Plan::PLUS,
        );

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

                // 🆕 🔒 P3 — il premio «prezzo bloccato».
                'prezzo_bloccato' => ($sessione['metadata']['prezzo_bloccato'] ?? '') === '1',

                'starts_at' => now(),
                /*
                 * 💡 Un mese e un giorno, non un mese esatto: il rinnovo di
                 * Stripe puo' arrivare qualche ora dopo la scadenza, e un
                 * abbonato che resta senza AI per mezza giornata **mentre sta
                 * pagando** e' il modo piu' rapido per farsi disdire.
                 *
                 * 🆕 🧪 P3 — con la prova, fino alla fine della prova; `invoice.paid` allunga dopo.
                 */
                'ends_at' => ((($giorni = (int) ($sessione['metadata']['prova_giorni'] ?? 0)) > 0
                    ? now()->addDays($giorni)
                    : Plan::query()->where('code', Plan::PLUS)->first()?->finePeriodo(now())) ?? now()->addMonthNoOverflow())->addDay(),
            ],
        );

        Log::info('Stripe: abbonamento acceso.', [
            'utente' => $utente->getKey(),
            'sessione' => $sessione['id'] ?? null,
        ]);
    }

    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }

    /**
     * 🎁 **Questa persona può ricevere gettoni pagati da questo cliente?** — R1.
     *
     * 🚨 Sì **solo** se è un iscritto della sua stessa palestra. ⛔ Senza
     * questo controllo, un id scritto a mano farebbe accreditare i gettoni a
     * chiunque sulla piattaforma — pagati da una palestra che credeva di
     * regalarli a un suo allievo.
     *
     * ⚠️ E una palestra non regala a se stessa: `$perConto === $utente`
     * tornerebbe un movimento «regalo» su un acquisto normale, cioè una riga
     * di registro che racconta una cosa che non è successa.
     */
    private function beneficiarioValido(User $chiPaga, ?User $perConto): ?User
    {
        if ($perConto === null || $perConto->is($chiPaga)) {
            return null;
        }

        if ($chiPaga->tenant_id === null || $perConto->tenant_id !== $chiPaga->tenant_id) {
            return null;
        }

        return $perConto;
    }

    /**
     * Il beneficiario che torna da Stripe, **riverificato**.
     *
     * 🚨 **I metadata tornano indietro da fuori.** Li abbiamo scritti noi, e
     * Stripe non li cambia — ma un webhook è una richiesta HTTP che arriva
     * dalla rete, e fidarsi di un id dentro un corpo JSON per accreditare
     * valore è la definizione del problema. 💡 Costa una query.
     *
     * @param  array<string, mixed>  $meta
     */
    private function beneficiarioDi(User $chiPaga, array $meta): ?User
    {
        $id = (int) ($meta['beneficiario_id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $perConto = User::withoutGlobalScopes()->find($id);

        return $perConto === null ? null : $this->beneficiarioValido($chiPaga, $perConto);
    }
}
