<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\Listino;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Il listino per l'app, e l'apertura del pagamento — 3b-H, 26/08/2026.
 *
 * ── 🚨 PERCHE' IL LISTINO LO MANDA IL SERVER ──────────────────────────────
 *
 * Perche' fino a oggi i prezzi e i tagli erano **scritti a mano dentro la
 * schermata dell'app** («400 richieste al mese»), mentre il server ne diceva
 * altri due (300 nel listino, 450 nel piano). ⛔ Tre numeri per la stessa cosa,
 * e quello che il cliente legge era l'unico che nessuno poteva correggere senza
 * pubblicare una versione sugli store.
 *
 * 💡 Adesso l'app **chiede**: cambiare un prezzo e' una riga di configurazione.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly Listino $listino,
        private readonly PianoAttivo $piano,
        private readonly PortafoglioGettoni $portafoglio,
        private readonly Cassa $cassa,
    ) {}

    /** Cosa si puo' comprare, e cosa si ha gia'. */
    public function listino(Request $request): JsonResponse
    {
        $utente = $request->user();

        return response()->json(['data' => [
            /*
             * 🚨 **`abbonato` decide cosa la schermata vende per primo.** Chi
             * non lo e' vede l'abbonamento; chi lo e' vede i gettoni — offrire
             * l'abbonamento a chi ce l'ha gia' e' il modo piu' rapido per far
             * credere che non sia attivo.
             */
            'abbonato' => $this->piano->eAbbonato($utente),
            'livello' => $this->piano->livello($utente),

            /*
             * ══ 🆕 CHI PAGA PER TE — 3b-AH.4 (12/09/2026) ═══════════════════
             *
             * 📌 Il committente: *«la modale per lui deve dire che non è più
             * abbonato **perché non è più abbonato il suo trainer o la sua
             * palestra**»*.
             *
             * ⛔ **Oggi l'app dice a tutti la stessa cosa**: *«il rinnovo
             * automatico era spento, quindi non abbiamo addebitato niente»*.
             * 🚨 A un iscritto di palestra è falso due volte — non ha spento
             * niente e non ha mai pagato niente. Gli fa cercare
             * un'impostazione che non ha, e gli nasconde l'unica cosa utile:
             * parlarne con la palestra.
             *
             * ⚠️ **Lo dice il server, non lo deduce l'app.** Il tipo di tenant
             * l'app ce l'ha, e potrebbe dedurlo — ma sarebbe la stessa
             * informazione dedotta due volte, e le due deduzioni divergono al
             * primo caso nuovo. Da AH.1 il tenant di un trainer è `personal`
             * come quello di un utente singolo: dedurlo dal tipo direbbe
             * «trainer» a chi paga per sé.
             */
            'chi_pagava' => $this->piano->chiPaga($utente),

            'abbonamento' => [
                'prezzo_cent' => $this->listino->singolo(),
                'chiamate_mensili' => $this->listino->chiamateMensili(),
            ],

            'pacchetti' => array_map(
                static fn (array $p): array => [
                    'gettoni' => $p['gettoni'],
                    'prezzo_cent' => $p['prezzo'],
                    'nota' => $p['nota'],
                ],
                $this->listino->pacchettiGettoni(),
            ),

            'gettoni_disponibili' => $this->portafoglio->saldo($utente),

            /*
             * ══ 🔁 QUANDO SCADE, E SE SI RINNOVA — 3b-H.9 ═════════════════
             *
             * 🚨 **Le due cose insieme, o la frase mente.** «Fino al 27
             * settembre» detto a chi si rinnova suona come una scadenza e fa
             * disdire per sbaglio; «si rinnova» detto a chi ha gia' disdetto e'
             * peggio ancora.
             *
             * ⚠️ `null` quando l'abbonamento non viene da Stripe — le palestre —
             * o quando non ce n'e' nessuno. 💡 L'app in quel caso non scrive
             * niente invece di inventare una data.
             */
            'abbonamento_attivo' => $this->abbonamentoAttivo($utente),

            /*
             * 🆕 💳 **I piani da trainer o da palestra** — §9.6, 18/09/2026.
             *
             * ⛔ Prima l'app non li conosceva: il listino mandava solo il piano
             * personale e i gettoni, e un trainer non aveva da nessuna parte un
             * pulsante per pagare il suo piano. `piani`, `piano_attuale`,
             * `puo_cambiare` e `pagamento_fallito_il` vengono da
             * `PianiInVendita::listinoPer()`, accanto alle regole di vendita.
             */
            ...app(PianiInVendita::class)->listinoPer($utente),
        ]]);
    }

    /**
     * 🔁 Accende o spegne il rinnovo automatico — 08/09/2026.
     *
     * 📌 *«si deve rinnovare automaticamente SE E SOLO SE l'utente flagga il
     * toggle "Rinnova in automatico"»*.
     *
     * 🚨 **Il `422` e' la risposta giusta per chi non ha Stripe**, e non un
     * `500`: un abbonamento di palestra non si gestisce da qui, e non e' un
     * guasto — e' una richiesta che non ha senso per quella persona.
     */
    public function rinnovo(Request $request): JsonResponse
    {
        $dati = $request->validate(['rinnova' => ['required', 'boolean']]);

        try {
            $this->cassa->rinnovoAutomatico($request->user(), (bool) $dati['rinnova']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'senza_abbonamento_stripe',
            ], 422);
        }

        return response()->json(['data' => ['rinnova' => (bool) $dati['rinnova']]]);
    }

    /**
     * @return array{fino_al: string|null, rinnova: bool, gestibile: bool}|null
     */
    private function abbonamentoAttivo(User $utente): ?array
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();

        if ($riga === null) {
            return null;
        }

        return [
            'fino_al' => $riga->ends_at?->toDateString(),
            'rinnova' => (bool) $riga->rinnova,

            /*
             * Il portale di Stripe esiste solo per chi ha pagato con Stripe --
             * E solo per chi quell'abbonamento lo paga.
             *
             * Prima qui c'era il solo controllo su `stripe_customer_id`, e il
             * giorno in cui una palestra paga con Stripe ogni suo iscritto si
             * sarebbe visto il pulsante: il portale non serve solo a disdire,
             * mostra anche LE FATTURE della palestra e permette di cambiarle
             * la carta.
             */
            'gestibile' => $riga->stripe_customer_id !== null
                && Cassa::loPuoGestire($utente),

            /*
             * ══ 🎁 IL RINNOVO SI CAMBIA ANCHE SUI REGALI — 08/09/2026 ═════
             *
             * 📌 *«deve funzionare anche con gli abbonamenti che regalo»*.
             *
             * ⛔ **`gestibile` non poteva rispondere a questa domanda**, e
             * riusarlo sarebbe stato il difetto: dice «esiste il portale di
             * Stripe», che e' un'altra cosa. Un regalo il portale non ce l'ha,
             * ma il rinnovo si', perche' a rinnovarlo e'
             * `abbonamenti:rinnova-i-regali`.
             *
             * 🚨 **Le palestre restano fuori da tutti e due**: il loro
             * abbonamento non e' di chi lo usa, e un iscritto non deve poterlo
             * spegnere per tutti.
             */
            /*
             * Comanda chi paga: il proprietario di un tenant personale (utente
             * singolo o trainer indipendente) e il `gym_admin` di una palestra.
             *
             * L'iscritto di una palestra non paga niente, e senza questa
             * guardia poteva spegnere il rinnovo PER TUTTI.
             */
            'rinnovabile' => Cassa::loPuoGestire($utente),
        ];
    }

    /**
     * Apre il portale di Stripe: disdetta, carta, ricevute.
     *
     * ⛔ **Non e' il posto dove si disdice**: e' il posto dove si va a disdire.
     * La differenza conta, perche' tutto quello che succede la' dentro torna
     * indietro dai webhook — e non da questa risposta.
     */
    public function portale(Request $request): JsonResponse
    {
        try {
            $url = $this->cassa->portale(
                $request->user(),
                rtrim((string) config('app.url'), '/').'/pagamento/ok',
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Non sono riuscito ad aprire la gestione. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * 🆕 💳 **Il piano di un trainer o di una palestra** — §9.2, 18/09/2026.
     *
     * ⚠️ Le regole — chi può comprare cosa, il prezzo dal listino, un secondo
     * canone rifiutato — stanno tutte in `PianiInVendita`. Qui si traducono i
     * suoi rifiuti in risposte: il messaggio è già scritto per chi lo leggerà.
     */
    private function apriIlPiano(Request $request, string $codice): JsonResponse
    {
        try {
            $url = app(PianiInVendita::class)->apri($request->user(), $codice);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Il pagamento non si è aperto. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * 🆕 **Cambiare piano** — §9.5, 18/09/2026.
     *
     * 💡 Salire subito con il conguaglio di Stripe; scendere solo se nessuno resta
     * senza posto. Le regole stanno in `PianiInVendita::cambia()`.
     */
    public function cambiaPiano(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'piano' => ['required', 'string', 'max:40'],
        ]);

        try {
            app(PianiInVendita::class)->cambia($request->user(), (string) $dati['piano']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Non sono riuscito a cambiare il piano. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['piano' => $dati['piano']]]);
    }

    /**
     * Apre una sessione di pagamento e dice dove andare.
     *
     * ⚠️ **Il client sceglie COSA, non QUANTO.** Il taglio arriva da qui, il
     * prezzo lo mette il server: vedi `Cassa::prezzoDelPacchetto`.
     */
    public function checkout(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'tipo' => ['required', Rule::in([Cassa::ABBONAMENTO, Cassa::GETTONI, PianiInVendita::TIPO])],

            /*
             * 🆕 💳 **Il codice del piano, per trainer e palestre** — §9.2.
             *
             * ⚠️ Il client dice **quale** piano, mai quanto costa: il prezzo lo
             * legge `PianiInVendita` dal listino.
             */
            'piano' => ['required_if:tipo,'.PianiInVendita::TIPO, 'string', 'max:40'],

            /*
             * 💡 `required_if` e non `nullable`: chiedere dei gettoni senza dire
             * quanti e' una richiesta incompleta, e farla passare vorrebbe dire
             * scoprirlo dentro la cassa — cioe' dopo aver gia' parlato con
             * Stripe.
             */
            'gettoni' => ['required_if:tipo,'.Cassa::GETTONI, 'integer'],
        ]);

        $tipo = $dati['tipo'];

        if ($tipo === PianiInVendita::TIPO) {
            return $this->apriIlPiano($request, (string) $dati['piano']);
        }

        // ⛔ Non si vende due volte la stessa cosa.
        /*
         * == UN ISCRITTO NON COMPRA PER LA SUA PALESTRA -- 10/09/2026 ==
         *
         * L'abbonamento si scrive sul TENANT (`accendiLAbbonamento()`), quindi
         * un iscritto di una palestra SENZA abbonamento che comprasse il piano
         * individuale abbonerebbe l'intera palestra -- pagando da solo, per
         * tutti.
         *
         * Non lo fermava niente: il controllo qui sotto guarda se e' gia'
         * abbonato, e in una palestra che non paga non lo e'.
         *
         * Chi vuole l'abbonamento suo lo chiede alla palestra, che e' il modo
         * in cui il prodotto e' venduto: la palestra compra il pacchetto
         * allievi.
         */
        if (
            $tipo === Cassa::ABBONAMENTO
            && ! Cassa::loPuoGestire($request->user())
        ) {
            return response()->json([
                'message' => __('L\'abbonamento della tua palestra lo gestisce la palestra.'),
                'code' => 'non_lo_gestisci_tu',
            ], 403);
        }

        if ($tipo === Cassa::ABBONAMENTO && $this->piano->eAbbonato($request->user())) {
            return response()->json([
                'message' => 'Hai gia\' un abbonamento attivo.',
            ], 409);
        }

        try {
            $url = $this->cassa->apri(
                $request->user(),
                $tipo,
                isset($dati['gettoni']) ? (int) $dati['gettoni'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            /*
             * ⚠️ Il messaggio di Stripe non si rimanda al client: puo'
             * contenere id interni e nomi di chiavi. 💡 Nel log ci va per
             * intero, perche' e' l'unico posto dove serve.
             */
            report($e);

            return response()->json([
                'message' => 'Il pagamento non si e\' aperto. Riprova fra poco.',
            ], 502);
        }

        return response()->json(['data' => ['url' => $url]]);
    }
}
