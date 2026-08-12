<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Enums\AiFeature;
use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\AiAdvice;
use App\Models\FoodEntry;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Dashboard\DashboardService;
use App\Services\Nutrition\DiaryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $this->assertQuota($request->user());

        $utente = $request->user();

        $stima = $this->ai->for(AiFeature::FoodText)->foodFromText(
            $dati['text'],
            AiCallContext::for($utente, AiFeature::FoodText),
        );

        return $this->rispostaStima($request, $stima, FoodSource::AiText, $dati);
    }

    public function foodFromPhoto(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            'save' => ['nullable', 'boolean'],
        ]);

        $this->assertQuota($request->user());

        $utente = $request->user();
        $file = $request->file('photo');

        $stima = $this->ai->for(AiFeature::FoodPhoto)->foodFromImage(
            $file->getRealPath(),
            (string) $file->getMimeType(),
            AiCallContext::for($utente, AiFeature::FoodPhoto),
        );

        return $this->rispostaStima($request, $stima, FoodSource::AiPhoto, $dati);
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

        $cache = AiAdvice::cached($utente, $oggi, 'daily', $contesto);

        if ($cache !== null) {
            return response()->json(['data' => [
                'body' => $cache->body,
                'cached' => true,
                'generated_at' => $cache->created_at?->toIso8601String(),
            ]]);
        }

        $this->assertQuota($request->user());

        $testo = $this->ai->for(AiFeature::DailyAdvice)->dailyAdvice(
            $contesto,
            AiCallContext::for($utente, AiFeature::DailyAdvice),
        );

        $riga = AiAdvice::create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'date' => $oggi->etichetta,
            'kind' => 'daily',
            'context_hash' => AiAdvice::hashOf($contesto),
            'body' => $testo,
            'model' => $this->ai->modelFor(AiFeature::DailyAdvice),
        ]);

        return response()->json(['data' => [
            'body' => $riga->body,
            'cached' => false,
            'generated_at' => $riga->created_at?->toIso8601String(),
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
            'used_tokens' => $this->quota->usedThisMonth($utente),
            'cap_tokens' => $this->quota->capFor($utente),
            'remaining_tokens' => $this->quota->remaining($utente),
            'used_percent' => $this->quota->usedPercent($utente),
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    private function assertQuota(?User $utente): void
    {
        if ($utente === null) {
            return;
        }

        $this->quota->assertWithinQuota($utente);
    }

    /**
     * @param  array<string, mixed>  $dati
     */
    private function rispostaStima(
        Request $request,
        FoodEstimate $stima,
        FoodSource $fonte,
        array $dati,
    ): JsonResponse {
        $salva = (bool) ($dati['save'] ?? true);

        if (! $salva || $stima->items === []) {
            return response()->json(['data' => [
                'estimate' => $stima->toArray(),
                'entries' => [],
                'saved' => false,
            ]]);
        }

        $utente = $request->user();
        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();

        // Gli orari dei pasti sono quelli di questa persona, non soglie fisse:
        // vedi la nota su `MealType::fromProfile()`.
        $pasto = (isset($dati['meal']) ? MealType::tryFrom((string) $dati['meal']) : null)
            ?? MealType::fromProfile($quando, $utente->profile?->meal_hours);

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

        return response()->json(['data' => [
            'estimate' => $stima->toArray(),
            'entries' => array_map(fn (FoodEntry $v): array => $this->diary->voce($v), $voci),
            'saved' => true,
        ]], 201);
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
             * ⚠️ Cambia durante il giorno, quindi entra nell'hash del contesto
             * e il consiglio si rigenera. È voluto: un consiglio del mattino
             * ripetuto la sera sarebbe fuori tempo. Il costo è contenuto dalla
             * quota di palestra.
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
