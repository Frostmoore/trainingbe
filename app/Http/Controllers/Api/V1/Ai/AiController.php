<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Enums\AiFeature;
use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\AiAdvice;
use App\Models\FoodEntry;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Quota\TenantAiQuota;
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
        private readonly TenantAiQuota $quota,
        private readonly TenantContext $tenants,
        private readonly DiaryService $diary,
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

        $this->assertQuota();

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

        $this->assertQuota();

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

        $this->assertQuota();

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

    /** Quanto resta: serve all'app per non proporre funzioni che daranno 429. */
    public function usage(Request $request): JsonResponse
    {
        $palestra = $this->tenants->get();

        if ($palestra === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'used_tokens' => $this->quota->usedThisMonth($palestra),
            'cap_tokens' => $this->quota->capFor($palestra),
            'remaining_tokens' => $this->quota->remaining($palestra),
            'used_percent' => $this->quota->usedPercent($palestra),
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    private function assertQuota(): void
    {
        $palestra = $this->tenants->get();

        if ($palestra !== null) {
            $this->quota->assertWithinQuota($palestra);
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

        $pasto = (isset($dati['meal']) ? MealType::tryFrom((string) $dati['meal']) : null)
            ?? MealType::fromHour((int) $quando->format('G'));

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
        $oggi = Carbon::today();

        $giornata = $this->diary->forDate($utente, $oggi);

        return [
            'date' => $oggi->toDateString(),
            'totals' => $giornata['totals'],
            'targets' => $giornata['targets'],
            'burned' => $giornata['burned'],
            'meals_logged' => count(array_filter(
                $giornata['meals'],
                static fn (array $m): bool => $m['entries'] !== [],
            )),
            'goal' => $utente->profile?->goalForFormula(),
        ];
    }
}
