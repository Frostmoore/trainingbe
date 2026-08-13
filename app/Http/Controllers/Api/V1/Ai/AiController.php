<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Enums\AiFeature;
use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\AiAdvice;
use App\Models\AiCreditMovement;
use App\Models\FoodEntry;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\FoodItem;
use App\Services\Ai\Exceptions\AiQuotaExceededException;
use App\Services\Ai\Guardie\MealValidator;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Dashboard\DashboardService;
use App\Services\Nutrition\DiaryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gli endpoint AI dell'app — B6.6.
 *
 * 🚨 **La quota si controlla PRIMA di chiamare il modello**, in ogni metodo.
 * Controllarla dopo vorrebbe dire aver gia' pagato i token che si sta rifiutando
 * di concedere: il tetto servirebbe a dire «hai sforato», non a impedirlo.
 *
 * Le eccezioni del layer AI hanno il proprio `render()` e arrivano al client
 * gia' tradotte: qui non si cattura niente, perche' un `try/catch` generico
 * finirebbe per trasformare un 429 di quota in un 500 generico.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly MemberAiQuota $quota,
        private readonly TenantContext $tenants,
        private readonly DiaryService $diary,
        private readonly DashboardService $dashboard,
        private readonly MealValidator $validatore,
        private readonly PortafoglioGettoni $portafoglio,
    ) {}

    // ───────────────────────── cibo ─────────────────────────

    public function foodFromText(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:1000'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            // Se `false`, si restituisce la stima senza scrivere niente: serve
            // all'app per far confermare una stima poco sicura prima di
            // registrarla.
            'save' => ['nullable', 'boolean'],
        ]);

        $conGettoni = $this->assertQuota($request->user(), AiFeature::FoodText);

        $utente = $request->user();

        /*
         * 🚨 **Il retry riscrive il MESSAGGIO UTENTE, mai il prompt di sistema.**
         *
         * Il prompt di sistema e' il prefisso identico di ogni chiamata di ogni
         * utente, ed e' cio' che lo rende cachabile a un decimo del costo.
         * Infilarci l'elenco degli errori di *questa* richiesta lo invaliderebbe
         * per tutti, **senza dare nessun errore**: si vedrebbe solo in fattura.
         */
        [$stima, $avvisi] = $this->stimaValidata(
            fn (string $appendice): FoodEstimate => $this->ai->for(AiFeature::FoodText)->foodFromText(
                $dati['text'].$appendice,
                AiCallContext::for($utente, AiFeature::FoodText),
            ),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::FoodText, $conGettoni);

        return $this->rispostaStima($request, $stima, FoodSource::AiText, $dati, $avvisi);
    }

    public function foodFromPhoto(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            'save' => ['nullable', 'boolean'],
        ]);

        $conGettoni = $this->assertQuota($request->user(), AiFeature::FoodPhoto);

        $utente = $request->user();
        $file = $request->file('photo');

        /*
         * 🚨 **Stesso trattamento del testo**: stesso prompt di sistema, stesso
         * schema, stesso validatore e **stesso retry**. Il 13/08/2026 il retry
         * dalla foto era un debito dichiarato — `foodFromImage()` non aveva un
         * posto in cui mettere l'appendice — ed e' stato chiuso aggiungendo un
         * parametro `$extra` al contratto dei fornitori.
         *
         * ⚠️ Perche' contava: un errore grave e' un'unita' vietata o un enum
         * sbagliato, e non c'e' nessuna ragione per cui il modello debba
         * sbagliarli meno guardando una foto. Chi fotografa il pranzo aveva
         * meno garanzie di chi lo scrive, senza che niente lo dicesse.
         */
        $percorso = $file->getRealPath();
        $mime = (string) $file->getMimeType();

        [$stima, $avvisi] = $this->stimaValidata(
            fn (string $appendice): FoodEstimate => $this->ai->for(AiFeature::FoodPhoto)->foodFromImage(
                $percorso,
                $mime,
                AiCallContext::for($utente, AiFeature::FoodPhoto),
                $appendice,
            ),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::FoodPhoto, $conGettoni);

        return $this->rispostaStima($request, $stima, FoodSource::AiPhoto, $dati, $avvisi);
    }

    /**
     * Chiama il modello, controlla, e **una volta sola** gli chiede di rifare.
     *
     * ── 🚨 Perche' un solo tentativo ──────────────────────────────────────────
     *
     * Un errore grave e' quasi sempre di formato — un'unita' vietata, un enum
     * sconosciuto — e il modello lo corregge alla prima richiesta. Se non lo
     * corregge alla seconda non lo correggera' alla terza: quello che
     * cambierebbe, ripetendo, e' solo il conto di fine mese.
     *
     * ⚠️ **Se anche il secondo tentativo fallisce si restituisce comunque la
     * stima**, con gli errori negli avvisi. E' l'app che decide: il foglio di
     * conferma li mostra, e la persona corregge o annulla. Rifiutare tutto
     * vorrebbe dire buttare una chiamata gia' pagata e lasciare chi scrive senza
     * niente.
     *
     * @param  callable(string): FoodEstimate  $chiamata
     * @return array{0: FoodEstimate, 1: list<string>}
     */
    private function stimaValidata(callable $chiamata): array
    {
        $esito = $this->validatore->valida($chiamata(''));

        if ($esito['gravi'] === []) {
            return [$esito['stima'], $esito['avvisi']];
        }

        $appendice = "\n\n[SISTEMA] Il tuo output precedente violava lo schema: "
            .implode(' ', $esito['gravi'])
            .' Correggi e restituisci solo il JSON.';

        $secondo = $this->validatore->valida($chiamata($appendice));

        return [$secondo['stima'], array_merge($secondo['avvisi'], $secondo['gravi'])];
    }

    /**
     * Scrive nel diario una stima che la persona ha **confermato** — A4.8.
     *
     * ── 🚨 Perche' esiste un endpoint invece di riusare `/food-entries` ──────
     *
     * Perche' `source` e `ai_raw` devono sopravvivere alla conferma. `FoodSource`
     * lo dice da sempre: *«quando un modello AI comincia a sbagliare le stime,
     * bisogna poter ritrovare TUTTE le voci che ha prodotto»*. Facendo scrivere
     * l'app con `POST /food-entries` ogni voce nascerebbe `manual`, e quella
     * ricerca non sarebbe piu' possibile — cioe' il giorno che un modello
     * peggiora non si saprebbe piu' quali voci rifare.
     *
     * E perche' un pasto e' **una cosa sola**: cinque `POST` separati possono
     * fallire al terzo e lasciare in diario mezza cena, che nei totali e' un
     * numero sbagliato senza nessun segno che lo sia. Qui si scrive in
     * transazione: o tutto o niente.
     *
     * 🚨 **Non chiama il modello e NON consuma quota.** La chiamata e' gia'
     * stata pagata da `foodFromText`/`foodFromPhoto` con `save: false`:
     * rifiutare di salvare cio' che si e' gia' speso sarebbe far pagare due
     * volte lo stesso pasto. Per la stessa ragione qui non c'e' `assertQuota()`.
     *
     * ⚠️ Sta comunque dietro `ai.consent`: la revoca del consenso deve fermare
     * **tutto il flusso**, non solo il pezzo che parla con Anthropic. Una voce
     * che entra in diario dopo la revoca farebbe sembrare che la revoca non
     * abbia funzionato — ed e' esattamente il difetto #3 del 12/08.
     */
    public function confirm(Request $request): JsonResponse
    {
        $dati = $request->validate([
            // 🚨 Solo le due fonti AI: questo endpoint esiste per conservare
            // l'origine, e accettare `manual` o `plan` vorrebbe dire lasciar
            // marchiare come «dal piano» una voce che il piano non contiene.
            'source' => ['required', Rule::in([FoodSource::AiText->value, FoodSource::AiPhoto->value])],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.unit' => ['nullable', 'string', 'max:16'],
            'items.*.grams' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.kcal' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.protein' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.fat' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            // ⚠️ Grammi di alcol etilico: non finisce in nessuna colonna, ma se
            // non passasse di qui la conferma perderebbe l'unico dato che rende
            // coerente una bevanda alcolica.
            'items.*.alcohol' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            // I campi che l'app rimanda indietro cosi' come li ha ricevuti: non
            // finiscono in colonne, ma restano in `ai_raw` e servono al validatore.
            'items.*.ml' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.basis' => ['nullable', Rule::in(FoodItem::BASI)],
            'items.*.state' => ['nullable', Rule::in(FoodItem::STATI)],
            'items.*.declared' => ['nullable', 'boolean'],
            'items.*.brand' => ['nullable', 'string', 'max:120'],
            'items.*.abv_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        /*
         * ⚠️ Si ricostruisce un `FoodEstimate` invece di scrivere dai dati
         * grezzi: cosi' la conferma passa dalle stesse normalizzazioni della
         * scrittura diretta — compresi i totali, che `normalizeTotals()`
         * ricalcola quando mancano. Due strade che scrivono lo stesso pasto in
         * due modi diversi e' come nascono i totali che non tornano.
         */
        $esito = $this->validatore->valida(FoodEstimate::fromArray(['items' => $dati['items']]));
        $stima = $esito['stima'];

        $voci = $this->scriviVoci(
            $request->user(),
            $stima,
            FoodSource::from($dati['source']),
            $dati,
        );

        return response()->json(['data' => [
            'estimate' => $stima->toArray(),
            'entries' => array_map(fn (FoodEntry $v): array => $this->diary->voce($v), $voci),
            'saved' => true,
        ]], 201);
    }

    // ───────────────────────── consiglio ─────────────────────────

    /**
     * Il consiglio del giorno.
     *
     * 🚨 **La cache e' su un hash del contesto, e la data ne fa parte.** Da qui
     * discendono due cose senza nessun cron: il consiglio si rigenera a
     * mezzanotte, e si rigenera quando l'utente mangia o si allena — cioe'
     * quando ha senso rifarlo. Un job notturno costerebbe una chiamata per ogni
     * utente ogni notte, comprese quelle di chi non apre l'app da un mese.
     */
    public function advice(Request $request): JsonResponse
    {
        if (! config('ai.advice.enabled')) {
            return response()->json(['data' => null]);
        }

        $utente = $request->user();

        /*
         * 🚨 **Mezzanotte di chi legge, non di Greenwich** — A3. Con
         * `Carbon::today()` il consiglio si rigenerava alle 02:00 di Roma
         * d'estate: chi apriva l'app all'una di notte trovava ancora quello del
         * giorno prima, che parlava di una giornata gia' chiusa.
         */
        $oggi = $utente->giornoDiOggi();

        $contesto = $this->contestoConsiglio($request);

        $cache = AiAdvice::cached($utente, $oggi, 'daily', self::chiaveDiCache($contesto));

        if ($cache !== null) {
            return response()->json(['data' => [
                'body' => $cache->body,
                'cached' => true,
                'generated_at' => $cache->created_at?->toIso8601String(),
            ]]);
        }

        $conGettoni = $this->assertQuota($request->user(), AiFeature::DailyAdvice);

        $testo = $this->ai->for(AiFeature::DailyAdvice)->dailyAdvice(
            $contesto,
            AiCallContext::for($utente, AiFeature::DailyAdvice),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::DailyAdvice, $conGettoni);

        $riga = AiAdvice::create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'date' => $oggi->etichetta,
            'kind' => 'daily',
            'context_hash' => AiAdvice::hashOf(self::chiaveDiCache($contesto)),
            'body' => $testo,
            'model' => $this->ai->modelFor(AiFeature::DailyAdvice),
        ]);

        return response()->json(['data' => [
            'body' => $riga->body,
            'cached' => false,
            'generated_at' => $riga->created_at?->toIso8601String(),
        ]]);
    }

    /**
     * La stima di un alimento **mentre il trainer compone un piano** — G5.11, D13.
     *
     * ── 🚨 Perche' sta qui e non in `NutritionPlanController` ─────────────
     *
     * Perche' il cancello e' questo: quota inclusa, poi gettoni, poi `402`.
     * Riscriverlo nel controller dei piani vorrebbe dire **due sedi della
     * stessa regola commerciale** — e due sedi divergono. Quella che
     * divergerebbe per prima sarebbe la copia, cioe' quella meno provata.
     *
     * ── 🚨 La paga il TRAINER, mai l'allievo ─────────────────────────────
     *
     * `$request->user()` e' chi sta componendo. Quando l'allievo ricevera' il
     * piano, il costo e' gia' stato sostenuto da chi l'ha scritto.
     *
     * ── ⚠️ E i valori restano modificabili ────────────────────────────────
     *
     * Questa rotta **propone**, non decide: il trainer corregge quello che
     * vuole, e la sua correzione vince sempre. Il campo `origine_valori`
     * ricorda quale delle due l'ha scritto — serve a lui per vedere cosa ha
     * gia' controllato, non a noi per discutere.
     */
    public function planFood(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $utente = $request->user();

        if (! ($utente->isTrainer() || $utente->isFreeTrainer() || $utente->isGymAdmin() || $utente->isSuperAdmin())) {
            return response()->json([
                'message' => __('Solo chi allena può usare la stima nei piani.'),
                'code' => 'not_a_trainer',
            ], 403);
        }

        $conGettoni = $this->assertQuota($utente, AiFeature::PlanFood);

        [$stima, $avvisi] = $this->stimaValidata(
            fn (string $appendice): FoodEstimate => $this->ai->for(AiFeature::PlanFood)->foodFromText(
                $dati['text'].$appendice,
                AiCallContext::for($utente, AiFeature::PlanFood),
            ),
        );

        $this->consumaGettoniSeServe($utente, AiFeature::PlanFood, $conGettoni);

        return response()->json(['data' => [
            /*
             * 💡 Si restituiscono gli alimenti nella forma che
             * `NutritionPlanRequest` accetta, cosi' l'app puo' incollarli nel
             * piano senza tradurre niente. Una forma diversa qui vorrebbe dire
             * una conversione nell'app, cioe' un punto in piu' in cui i campi
             * possono divergere.
             */
            'items' => array_map(static fn (FoodItem $i): array => [
                'description' => $i->name,
                'qty' => $i->qty,
                'unit' => $i->unit,
                'grams' => $i->grams,
                'kcal' => $i->kcal,
                'protein' => $i->protein,
                'carbs' => $i->carbs,
                'fat' => $i->fat,
                'origine_valori' => 'ai',
            ], $stima->items),
            'warnings' => $avvisi,
        ]]);
    }

    // ───────────────────────── quota ─────────────────────────

    /**
     * Quanto resta: serve all'app per non proporre funzioni che daranno 429.
     *
     * 🚨 **È il consumo di chi sta chiedendo, non della palestra** (C20). Con
     * il conteggio per palestra, questo endpoint mostrava a ciascuno una barra
     * che si riempiva per colpa di altri — e non c'era niente che potesse
     * farci.
     */
    public function usage(Request $request): JsonResponse
    {
        $utente = $request->user();

        return response()->json(['data' => [
            /*
             * ⚠️ **Le chiavi `*_tokens` restano, e adesso portano chiamate.**
             *
             * Rinominarle romperebbe l'app gia' installata sui telefoni, che le
             * legge per disegnare la barra: quella barra sparirebbe finche' ogni
             * utente non aggiorna. 🚨 Il numero cambia scala — 400 invece di
             * 1.200.000 — ma la barra e' una percentuale, e la percentuale resta
             * giusta.
             *
             * 💡 I nomi nuovi affiancano i vecchi. `G2.5` toglie i vecchi
             * **dopo** che l'app aggiornata e' in giro, non prima.
             */
            'used_tokens' => $this->quota->usedThisMonth($utente),
            'cap_tokens' => $this->quota->capFor($utente),
            'remaining_tokens' => $this->quota->remaining($utente),
            'used_percent' => $this->quota->usedPercent($utente),

            // 🆕 G2 — i nomi veri, e il secondo contatore (D7).
            'used_calls' => $this->quota->usedThisMonth($utente),
            'cap_calls' => $this->quota->capFor($utente),
            'remaining_calls' => $this->quota->remaining($utente),
            'used_photo_calls' => $this->quota->usedThisMonth($utente, conFoto: true),
            'cap_photo_calls' => $this->quota->capFor($utente, conFoto: true),
            'remaining_photo_calls' => $this->quota->remaining($utente, conFoto: true),

            // 🆕 D16 — quanto resta nel portafoglio di chi paga per questa persona.
            'ai_credits' => $this->portafoglio->saldo($utente),
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Il cancello prima di ogni chiamata all'AI — G2, D8 + D16.
     *
     * ── 🚨 L'ordine, che e' tutto ─────────────────────────────────────────
     *
     *     1. la quota inclusa del mese  (MemberAiQuota)
     *     2. se e' finita, i gettoni     (PortafoglioGettoni)
     *     3. se non bastano, 402
     *
     * ⚠️ **Un gettone speso mentre la quota e' ancora piena e' un gettone
     * rubato**, e nessuno se ne accorge: il servizio funziona, la chiamata
     * riesce, il saldo cala. Si vedrebbe solo dalla fattura di qualcun altro.
     * Per questo la quota si guarda **per prima**, sempre.
     *
     * 💡 E se i gettoni ci sono, `AiQuotaExceededException` **non viene mai
     * lanciata**: chi ha ricaricato non deve nemmeno sapere che la quota
     * inclusa era finita.
     *
     * ── 🚨 Perche' RESTITUISCE la decisione invece di ricontrollarla dopo ──
     *
     * La prima versione di questo metodo non tornava niente, e un secondo metodo
     * richiamava `hasQuotaLeft()` **dopo** la chiamata per decidere se scalare un
     * gettone. Era sbagliato, e in un modo che si vede solo sul bordo:
     *
     *     tetto 400, gia' usate 399  →  prima della chiamata la quota BASTA
     *     la chiamata scrive la sua riga in ai_usage_logs  →  usate 400
     *     dopo la chiamata la quota NON basta piu'  →  scala un gettone
     *
     * ⚠️ **Quella chiamata era coperta dalla quota inclusa**, e si sarebbe fatta
     * pagare lo stesso. Una volta al mese per ogni cliente, in silenzio.
     *
     * 💡 La decisione si prende **una volta sola, prima**, e viaggia fino al
     * consumo. Ricontrollare uno stato che nel frattempo e' cambiato per colpa
     * nostra e' il modo classico di contare due volte la stessa cosa.
     *
     * @return bool se questa chiamata andra' pagata con i gettoni
     *
     * @throws AiQuotaExceededException
     * @throws GettoniEsauritiException
     */
    private function assertQuota(?User $utente, AiFeature $funzione): bool
    {
        if ($utente === null) {
            return false;
        }

        if ($this->quota->hasQuotaLeft($utente, $funzione)) {
            return false;
        }

        /*
         * 🚨 **Si guarda soltanto**, non si scala. Il consumo avviene dopo che
         * la chiamata e' andata a buon fine (`consumaGettoniSeServe()`): scalare
         * qui vorrebbe dire far pagare anche le chiamate che il fornitore ha
         * rifiutato — cioe' far pagare i nostri guasti al cliente.
         */
        if ($this->portafoglio->bastanoPer($utente, $funzione)) {
            return true;
        }

        /*
         * ⚠️ **Chi non ha mai comprato gettoni riceve il messaggio di quota**,
         * non quello dei gettoni: dirgli «ricarica i gettoni» a chi non sa
         * nemmeno che esistano e' un errore che non spiega niente.
         */
        if ($this->portafoglio->saldo($utente) === 0
            && ! AiCreditMovement::withoutGlobalScopes()
                ->where('tenant_id', $utente->tenant_id)
                ->exists()) {
            $this->quota->assertWithinQuota($utente, $funzione);
        }

        throw new GettoniEsauritiException(
            saldo: $this->portafoglio->saldo($utente),
            servivano: $funzione->costoInGettoni(),
        );
    }

    /**
     * Scala i gettoni **dopo** che la chiamata e' riuscita.
     *
     * 🚨 **`$conGettoni` arriva da `assertQuota()`, non si ricalcola.** Vedi la
     * nota li' sopra: ricalcolarlo qui farebbe pagare la chiamata che ha
     * esaurito la quota, che invece era coperta.
     *
     * ⚠️ **Dopo e non prima**: scalare prima vorrebbe dire far pagare anche le
     * chiamate che il fornitore ha rifiutato — cioe' far pagare i nostri guasti
     * al cliente.
     */
    private function consumaGettoniSeServe(?User $utente, AiFeature $funzione, bool $conGettoni): void
    {
        if ($utente === null || ! $conGettoni) {
            return;
        }

        try {
            $this->portafoglio->consuma($utente, $funzione);
        } catch (GettoniEsauritiException) {
            /*
             * ⚠️ **Non si rilancia, e la scelta e' deliberata.** Arrivare qui
             * vuol dire che i gettoni sono finiti **fra** il controllo e la
             * risposta — cioe' una corsa fra due chiamate della stessa persona.
             * La chiamata al fornitore e' gia' stata pagata da noi: negare la
             * risposta ora vorrebbe dire buttarla e far arrabbiare il cliente
             * per un centesimo. Si passa, e il saldo resta dov'e'.
             */
        }
    }

    /**
     * @param  array<string, mixed>  $dati
     */
    private function rispostaStima(
        Request $request,
        FoodEstimate $stima,
        FoodSource $fonte,
        array $dati,
        array $avvisi = [],
    ): JsonResponse {
        $salva = (bool) ($dati['save'] ?? true);

        if (! $salva || $stima->items === []) {
            return response()->json(['data' => [
                'estimate' => $stima->toArray(),
                // ⚠️ Gli avvisi del validatore viaggiano insieme alla stima: sono
                // cio' che il foglio di conferma mostra quando il backend ha
                // corretto qualcosa o ha trovato un numero implausibile.
                'warnings' => $avvisi,
                'entries' => [],
                'saved' => false,
            ]]);
        }

        $voci = $this->scriviVoci($request->user(), $stima, $fonte, $dati);

        return response()->json(['data' => [
            'estimate' => $stima->toArray(),
            'warnings' => $avvisi,
            'entries' => array_map(fn (FoodEntry $v): array => $this->diary->voce($v), $voci),
            'saved' => true,
        ]], 201);
    }

    /**
     * Scrive le voci di una stima. **L'unico punto che le crea**, usato sia da
     * `save: true` sia dalla conferma.
     *
     * 🚨 Sta in un metodo solo perche' le due strade devono produrre voci
     * **identiche**. Duplicare il `create()` significa che un campo aggiunto di
     * la' non arriva di qua, e la differenza si vede settimane dopo come «certe
     * voci AI non si ricalcolano» — che e' letteralmente il difetto #9 del
     * 12/08, nato cosi'.
     *
     * ⚠️ **In transazione**: un pasto e' una cosa sola. Se la terza voce di
     * cinque fallisce, mezza cena in diario e' un totale sbagliato che non
     * dichiara di esserlo.
     *
     * @param  array<string, mixed>  $dati
     * @return list<FoodEntry>
     */
    private function scriviVoci(
        ?User $utente,
        FoodEstimate $stima,
        FoodSource $fonte,
        array $dati,
    ): array {
        if ($utente === null) {
            return [];
        }

        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();

        // Gli orari dei pasti sono quelli di questa persona, non soglie fisse:
        // vedi la nota su `MealType::fromProfile()`.
        $pasto = (isset($dati['meal']) ? MealType::tryFrom((string) $dati['meal']) : null)
            ?? MealType::fromProfile($quando, $utente->profile?->meal_hours);

        return DB::transaction(static function () use ($utente, $stima, $fonte, $quando, $pasto): array {
            $voci = [];

            foreach ($stima->items as $item) {
                $voci[] = FoodEntry::create([
                    'tenant_id' => $utente->tenant_id,
                    'user_id' => $utente->getKey(),
                    'eaten_at' => $quando,
                    'meal' => $pasto,
                    'description' => $item->name,
                    // 🚨 I grammi arrivano dal modello e vincono sulla tabella di
                    // FoodUnit: il modello sa che alimento e', la tabella no.
                    'grams' => $item->grams,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'kcal' => $item->kcal,
                    'protein' => $item->protein,
                    'carbs' => $item->carbs,
                    'fat' => $item->fat,
                    'source' => $fonte,
                    'ai_raw' => $item->toArray(),
                ]);
            }

            return $voci;
        });
    }

    /**
     * Il contesto del consiglio.
     *
     * 🚨 Contiene la **data** (fa scattare la rigenerazione a mezzanotte) e i
     * numeri della giornata (la fanno scattare quando cambiano). Non contiene il
     * nome della persona: non serve al consiglio, e non ha motivo di uscire.
     *
     * @return array<string, mixed>
     */
    /**
     * Il fabbisogno che l'app ha calcolato sul telefono — S8.2.
     *
     * 🚨 **Nasce qui e muore nel prompt.** Non si scrive da nessuna parte:
     * entra nel contesto, viene mandato al modello, e finisce. L'unica traccia
     * che resta e' l'**hash** del contesto — che e' un digest, non un dato.
     *
     * ⚠️ **Si valida comunque.** Il server non ha modo di verificare che quel
     * numero sia vero — il peso da cui nasce non ce l'ha — ma un `target_kcal`
     * di 40.000 nel prompt non produrrebbe un errore: produrrebbe un
     * **consiglio alimentare assurdo**, detto con la stessa sicurezza di uno
     * giusto. I limiti sono gli stessi del pavimento e del tetto che
     * `CalorieCalculator` applica da sempre.
     *
     * @return array<string, mixed>|null
     */
    private function targetDallApp(Request $request): ?array
    {
        $dati = $request->validate([
            'target_kcal' => ['nullable', 'numeric', 'min:1000', 'max:8000'],
            'target_protein_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'target_carbs_g' => ['nullable', 'numeric', 'min:0', 'max:1200'],
            'target_fat_g' => ['nullable', 'numeric', 'min:0', 'max:400'],
        ]);

        if (($dati['target_kcal'] ?? null) === null) {
            return null;
        }

        return [
            'kcal' => (float) $dati['target_kcal'],
            'protein_g' => isset($dati['target_protein_g']) ? (float) $dati['target_protein_g'] : null,
            'carbs_g' => isset($dati['target_carbs_g']) ? (float) $dati['target_carbs_g'] : null,
            'fat_g' => isset($dati['target_fat_g']) ? (float) $dati['target_fat_g'] : null,

            // 🚨 Dice al modello **da dove viene il numero**, e non e' un
            // dettaglio: «calcolato sui tuoi dati» e «prescritto dal tuo
            // trainer» meritano un tono diverso, e il secondo non si discute.
            'source' => 'app',
        ];
    }

    /**
     * Cosa il modello deve **sapere** ma che NON deve far rigenerare il consiglio.
     *
     * ── 🚨 Il difetto, riferito provando l'app il 12/08/2026 ─────────────────
     *
     * *«Sulla pagina Oggi non mi mostra sempre il consiglio del giorno. Io direi
     * che una volta salvato lo dovrebbe mostrare sempre finché non cambia
     * qualcosa (allenamento, calorie inserite a mano, sonno o pasti).»*
     *
     * `time` cambia **ogni minuto** e `day_progress_pct` quasi altrettanto:
     * finché entravano nell'hash, **ogni apertura della schermata era una cache
     * miss**, cioè una chiamata AI nuova. Il consiglio non era «a volte
     * assente»: era ogni volta diverso, e quando la quota finiva o la chiamata
     * tardava spariva del tutto.
     *
     * 💡 **Restano nel prompt.** L'ora serve al modello — 3.000 kcal alle dieci
     * del mattino e 3.000 a fine giornata sono due situazioni opposte, e senza
     * l'ora il consiglio è *sbagliato*, non generico. Ma sapere che ore sono e
     * **rifare il consiglio perché è passato un minuto** sono due cose diverse.
     *
     * ⚠️ Quindi il consiglio si rigenera quando cambia qualcosa di **vero**:
     * pasti, allenamenti, calorie bruciate dichiarate, obiettivo. Sono tutti
     * dentro `totals`, `burned` e `targets`, che restano nell'hash.
     *
     * 🚨 **Il sonno NON può essere un innesco, ed è una conseguenza della
     * decisione D9**: dopo S1 il server non riceve più i dati del sensore, e
     * quello che non ha non può nemmeno accorgersi che è cambiato. Rigenerare
     * per un sonno che il modello non vedrà comunque sarebbe una chiamata a
     * vuoto.
     *
     * @var list<string>
     */
    private const VOLATILI = ['time', 'day_progress_pct', 'now'];

    /**
     * Il contesto ridotto a **ciò che deve invalidare la cache**.
     *
     * @param  array<string, mixed>  $contesto
     * @return array<string, mixed>
     */
    private static function chiaveDiCache(array $contesto): array
    {
        return Arr::except($contesto, self::VOLATILI);
    }

    private function contestoConsiglio(Request $request): array
    {
        $utente = $request->user();
        $adesso = Carbon::now();
        $oggi = $utente->giornoDiOggi($adesso);

        $giornata = $this->diary->forDate($utente, $oggi);
        $riepilogo = $this->dashboard->forToday($utente, $adesso);

        return [
            'date' => $oggi->etichetta,

            /*
             * 🚨 **L'ORA È PARTE DEL CONTESTO, non un dettaglio.**
             *
             * 3.000 kcal alle dieci del mattino e 3.000 a fine giornata sono
             * due situazioni opposte: la prima è una giornata che sta per
             * andare fuori controllo e su cui si può ancora intervenire, la
             * seconda è una giornata chiusa su cui l'unico consiglio sensato
             * riguarda domani. Senza l'ora il modello dà lo stesso consiglio in
             * entrambi i casi, e in uno dei due è **sbagliato** — non generico:
             * sbagliato.
             *
             * `day_progress_pct` è la quota di giornata sveglia già passata
             * (dalle 6 alle 23), che è il riferimento giusto per confrontare le
             * calorie assunte con il target.
             *
             * ⚠️ **Ma NON entra nell'hash della cache** — vedi `VOLATILI`.
             * Finché ci entrava, ogni apertura della schermata era una chiamata
             * AI nuova, perché `time` cambia ogni minuto. Sapere che ore sono e
             * **rifare il consiglio perché è passato un minuto** sono due cose
             * diverse.
             */
            /*
             * ⚠️ **L'ora LOCALE** — A3. In UTC il modello riceveva «18:00» per
             * una cena delle 20 a Roma, e consigliava di stare leggeri a chi
             * aveva gia' finito di mangiare. Il consiglio non era generico: era
             * sbagliato, e sembrava soltanto poco azzeccato.
             */
            'time' => $adesso->copy()->setTimezone($utente->fusoOrario())->format('H:i'),
            'day_progress_pct' => $riepilogo['day_progress_pct'],

            'totals' => $giornata['totals'],

            /*
             * 🚨 **Il target puo' arrivare DALL'APP, e di solito e' l'unico
             * modo** — S8.2.
             *
             * Da S5 il peso non sta piu' sul server, quindi
             * `Profile::computedTargets()` non calcola piu' niente e
             * `$giornata['targets']` e' **`null` per chiunque non abbia un
             * piano alimentare attivo**. Senza questo, il consiglio del giorno
             * era muto per tutti: il modello si ritrovava le calorie assunte
             * senza nessun numero con cui confrontarle.
             *
             * 🚨 **Il server lo INOLTRA, non lo conserva.** Finisce nel prompt e
             * nell'hash del contesto, e da li' sparisce: non c'e' nessuna
             * colonna, nessuna tabella, nessun log che lo trattenga. La
             * differenza fra «passare per» e «tenere» e' tutta la fase S5.
             *
             * ⚠️ **Il piano del trainer vince comunque.** Se `targets` c'e' gia'
             * — cioe' se un piano alimentare e' attivo — quello che manda l'app
             * si ignora: e' la stessa precedenza dell'interfaccia (D8), e
             * invertirla qui darebbe un consiglio costruito su un numero
             * diverso da quello che la persona ha davanti agli occhi.
             */
            'targets' => $giornata['targets'] ?? $this->targetDallApp($request),
            'burned' => $giornata['burned'],
            'meals_logged' => count(array_filter(
                $giornata['meals'],
                static fn (array $m): bool => $m['entries'] !== [],
            )),
            'goal' => $utente->profile?->goalForFormula(),

            /*
             * ── Il resto della persona ────────────────────────────────────
             *
             * 🚨 **Qui c'erano `sleep` e `vitals`, e sono stati tolti in S1.5.**
             *
             * Non per risparmiare token: perche' quei dati **non escono piu' dal
             * telefono di chi li produce** (decisione D9). Mandarli ad Anthropic
             * o a OpenAI era un trasferimento di dati sanitari verso gli Stati
             * Uniti, e questo piano esiste per farlo sparire alla radice invece
             * di gestirlo con contratti e clausole.
             *
             * ⚠️ Il consiglio ragiona ora **solo su cibo e allenamento**, che
             * non sono dati del corpo. E' un consiglio meno informato, ed e' una
             * perdita accettata consapevolmente: vedi §C11 di
             * `todo-2026-08-11.md`.
             *
             * 🚨 Chi volesse rimetterli deve prima leggere §C12, dove c'e' la
             * ragione legale per cui non ci sono.
             */
            'training' => [
                'last_30_days' => $riepilogo['training']['last_30_days'],
                'days_since_last' => $riepilogo['training']['days_since_last'],
            ],
            'body' => $riepilogo['body'],
        ];
    }
}
