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
        $oggi = Carbon::today();

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
            'date' => $oggi->toDateString(),
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
    private function contestoConsiglio(Request $request): array
    {
        $utente = $request->user();
        $adesso = Carbon::now();
        $oggi = $adesso->copy()->startOfDay();

        $giornata = $this->diary->forDate($utente, $oggi);
        $riepilogo = $this->dashboard->forToday($utente, $adesso);

        return [
            'date' => $oggi->toDateString(),

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
            'time' => $adesso->format('H:i'),
            'day_progress_pct' => $riepilogo['day_progress_pct'],

            'totals' => $giornata['totals'],
            'targets' => $giornata['targets'],
            'burned' => $giornata['burned'],
            'meals_logged' => count(array_filter(
                $giornata['meals'],
                static fn (array $m): bool => $m['entries'] !== [],
            )),
            'goal' => $utente->profile?->goalForFormula(),

            // ── D4: il resto della persona ────────────────────────────────
            //
            // Sonno, recupero e allenamento cambiano il consiglio: dopo una
            // notte da quattro ore con l'HRV sotto la media, «spingi» è un
            // cattivo consiglio anche se le calorie tornano.
            'sleep' => $riepilogo['sleep'],
            'vitals' => array_filter(
                $riepilogo['vitals'],
                static fn (mixed $v, string $k): bool => $k !== 'has_any' && $v !== null,
                ARRAY_FILTER_USE_BOTH,
            ),
            'training' => [
                'last_30_days' => $riepilogo['training']['last_30_days'],
                'days_since_last' => $riepilogo['training']['days_since_last'],
            ],
            'body' => $riepilogo['body'],
        ];
    }
}
