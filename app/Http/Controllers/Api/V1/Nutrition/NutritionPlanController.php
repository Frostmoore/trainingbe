<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\PlanStatus;
use App\Enums\TipoPianoAlimentare;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NutritionPlanRequest;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanDay;
use App\Models\NutritionPlanMeal;
use App\Models\User;
use App\Services\Nutrition\TotaliDelPiano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * I piani alimentari scritti da un trainer — G5.3.
 *
 * 🚨 **Prima di G5 non esisteva nessuna API di scrittura dei piani
 * alimentari.** C'erano solo `GET /nutrition-plan` (il piano assegnato
 * all'iscritto) e `POST /nutrition-plan/meals/{meal}/eaten`. Un trainer non
 * poteva comporre un piano dall'app perche' non c'era dove mandarlo.
 *
 * ── ⚠️ La collisione di nomi da non fare ─────────────────────────────────
 *
 * | Rotta | Chi | Cosa |
 * |---|---|---|
 * | `/nutrition-plan` (**singolare**) | l'iscritto | il piano che segue |
 * | `/nutrition-plans` (**plurale**) | il trainer | i piani che scrive |
 *
 * 🚨 Singolare e plurale a un carattere di distanza sono un modo garantito di
 * sbagliare rotta e non capire perche'. Se in `G9.5` il singolare sopravvive va
 * rinominato in qualcosa di inequivocabile (`/me/nutrition-plan`).
 *
 * ── Il controllo di ruolo sta QUI, non in un middleware ──────────────────
 *
 * 💡 E' la stessa scelta gia' fatta per `/trainer/*`: un middleware sarebbe una
 * seconda sede della stessa regola, e due sedi divergono.
 */
class NutritionPlanController extends Controller
{
    public function __construct(private readonly TotaliDelPiano $totali) {}

    /**
     * Chi puo' scrivere piani alimentari.
     *
     * ⚠️ **Anche il trainer indipendente** (`FreeTrainer`): e' il pubblico per
     * cui esiste meta' di questo piano. Dimenticarlo qui gli aprirebbe l'app e
     * gli chiuderebbe l'unica funzione per cui la usa.
     */
    /**
     * 🚨 **Il vincolo di N19, imposto QUI e non solo nascosto nell'app.**
     *
     * Un trainer puo' comporre **Consigli Alimentari**: un elenco di alimenti.
     * Non un piano con quantita', pasti e giorni — quello e' un atto riservato
     * (§4.11 del piano), e chi lo scrive senza titolo commette esercizio
     * abusivo della professione.
     *
     * ⚠️ Nascondere i campi nell'interfaccia non basta: l'API e' pubblica e
     * autenticata, e chiunque puo' mandarci un JSON con dentro `days`. Un
     * vincolo che vive solo nel client **non e' un vincolo**.
     *
     * 💡 **403 e non 422**: la richiesta e' ben formata, e il problema non e'
     * come e' scritta — e' chi la sta scrivendo. Un errore di validazione
     * manderebbe a cercare un campo sbagliato che non c'e'.
     */
    private function tipoNonConsentito(
        NutritionPlanRequest $request,
        User $utente,
    ): ?JsonResponse {
        $tipo = $request->tipoRichiesto();

        if (! $tipo->scrivibileDa($utente)) {
            return response()->json([
                'message' => __('Non puoi comporre un piano alimentare con quantita\' e orari: e\' un atto riservato a medici, biologi nutrizionisti e dietisti. Puoi mandare dei consigli alimentari.'),
                'code' => 'piano_riservato',
            ], 403);
        }

        if (! $tipo->ammetteQuantita() && $request->haQuantita()) {
            return response()->json([
                'message' => __('I consigli alimentari sono un elenco di alimenti: senza quantita\', senza orari e senza giorni.'),
                'code' => 'consigli_senza_quantita',
            ], 422);
        }

        return null;
    }

    private function puoScrivere(?User $utente): bool
    {
        /*
         * 💡 **Due cancelli, e servono entrambi.**
         *
         * Questo dice **chi puo' entrare** in questa rotta; `tipoNonConsentito`
         * dice **cosa puo' scriverci**. ⚠️ Fonderli sembrerebbe piu' pulito e
         * sarebbe sbagliato: il primo protegge la rotta da chi non c'entra
         * niente, il secondo e' il vincolo di N19 — e i due messaggi che
         * producono devono restare diversi, perche' dicono due cose diverse a
         * chi li legge.
         */
        return $utente !== null
            && ($utente->isTrainer()
                || $utente->isFreeTrainer()
                || $utente->isGymAdmin()
                || $utente->isNutrizionista()
                || $utente->isSuperAdmin());
    }

    private function negato(): JsonResponse
    {
        return response()->json([
            'message' => __('Solo chi allena può scrivere piani alimentari.'),
            'code' => 'not_a_trainer',
        ], 403);
    }

    /** I piani che questa persona ha scritto. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->puoScrivere($request->user())) {
            return $this->negato();
        }

        $piani = NutritionPlan::query()
            ->where('created_by', $request->user()->getKey())
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $piani->map(fn (NutritionPlan $p): array => $this->riassunto($p, $request->user()))->all(),
        ]);
    }

    /**
     * I **modelli**: quelli senza allievo, pronti da mandare in chat.
     *
     * 🚨 Gemello di `GET /workout-plans/templates`. `G0.2` ha misurato che di
     * schede modello in staging non ce n'e' **nemmeno una**: la funzione «manda
     * una scheda» esiste da S7 e non ha mai avuto niente da mandare. Questa
     * rotta nasce con lo stesso rischio, e si chiude solo quando qualcuno ha un
     * posto dove **scrivere** i modelli — cioe' G6 e G7.
     */
    public function templates(Request $request): JsonResponse
    {
        if (! $this->puoScrivere($request->user())) {
            return $this->negato();
        }

        $piani = NutritionPlan::query()
            ->templates()
            ->where('created_by', $request->user()->getKey())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $piani->map(fn (NutritionPlan $p): array => $this->dettaglio($p, $request->user()))->all(),
        ]);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        $piano = $this->suo($request->user(), $plan);

        if ($piano === null) {
            return response()->json(['message' => __('Piano non trovato.')], 404);
        }

        return response()->json(['data' => $this->dettaglio($piano, $request->user())]);
    }

    public function store(NutritionPlanRequest $request): JsonResponse
    {
        if (! $this->puoScrivere($request->user())) {
            return $this->negato();
        }

        $utente = $request->user();

        if (($no = $this->tipoNonConsentito($request, $utente)) !== null) {
            return $no;
        }

        $piano = DB::transaction(function () use ($request, $utente): NutritionPlan {
            $dati = $request->validated();

            $piano = NutritionPlan::create([
                'tenant_id' => $utente->tenant_id,
                // 🚨 D4 — **anonimo**: `member_id` resta `null`. Il legame con
                // una persona nasce solo quando il piano parte via chat, e
                // quel legame il server non lo vede.
                'member_id' => null,
                'created_by' => $utente->getKey(),
                'name' => $dati['name'],
                // 🚨 Esplicito: il default del modello e' gia' `consigli`,
                // ma qui si vede cosa si crea senza doverlo andare a cercare.
                'tipo' => $request->tipoRichiesto(),
                'notes' => $dati['notes'] ?? null,
                'rif_allievo' => $dati['rif_allievo'] ?? null,
                'target_kcal' => $dati['target_kcal'] ?? null,
                'target_protein_g' => $dati['target_protein_g'] ?? null,
                'target_carbs_g' => $dati['target_carbs_g'] ?? null,
                'target_fat_g' => $dati['target_fat_g'] ?? null,
                'status' => PlanStatus::Draft,
            ]);

            $giorni = $request->giorni();

            if ($giorni !== null) {
                $this->sincronizzaGiorni($piano, $giorni);
            }

            return $piano;
        });

        return response()->json(['data' => $this->dettaglio($piano->fresh(), $utente)], 201);
    }

    public function update(NutritionPlanRequest $request, int $plan): JsonResponse
    {
        if (($no = $this->tipoNonConsentito($request, $request->user())) !== null) {
            return $no;
        }

        $piano = $this->suo($request->user(), $plan);

        if ($piano === null) {
            return response()->json(['message' => __('Piano non trovato.')], 404);
        }

        DB::transaction(function () use ($request, $piano): void {
            $dati = $request->validated();

            $aggiorna = [
                'name' => $dati['name'],
                'notes' => $dati['notes'] ?? null,
                'target_kcal' => $dati['target_kcal'] ?? null,
                'target_protein_g' => $dati['target_protein_g'] ?? null,
                'target_carbs_g' => $dati['target_carbs_g'] ?? null,
                'target_fat_g' => $dati['target_fat_g'] ?? null,
            ];

            // ⚠️ Assente vuol dire «non l'ho mandato», non «cancellalo».
            if ($request->has('rif_allievo')) {
                $aggiorna['rif_allievo'] = $dati['rif_allievo'] ?? null;
            }

            $piano->update($aggiorna);

            $giorni = $request->giorni();

            if ($giorni !== null) {
                $this->sincronizzaGiorni($piano, $giorni);
            }
        });

        return response()->json(['data' => $this->dettaglio($piano->fresh(), $request->user())]);
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        $piano = $this->suo($request->user(), $plan);

        if ($piano === null) {
            return response()->json(['message' => __('Piano non trovato.')], 404);
        }

        $piano->delete();

        return response()->json(null, 204);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Il piano, **solo se l'ha scritto questa persona**.
     *
     * 🚨 `created_by` e non il tenant: due trainer della stessa palestra non si
     * modificano i piani a vicenda. E si risponde **404 e non 403**, perche' un
     * `403` confermerebbe che quel piano esiste — e insieme al `rif_allievo`
     * direbbe qualcosa sul lavoro di un collega.
     */
    private function suo(?User $utente, int $plan): ?NutritionPlan
    {
        if (! $this->puoScrivere($utente)) {
            return null;
        }

        return NutritionPlan::query()
            ->where('created_by', $utente->getKey())
            ->find($plan);
    }

    /**
     * Riscrive l'albero: giorni, pasti, alimenti, alternative — G5.3 (D2).
     *
     * 🚨 **Le alternative si scrivono dopo i principali**, a ogni livello:
     * `alternativa_di_id` deve puntare a righe che esistono gia'.
     *
     * @param  list<array<string, mixed>>  $giorni
     */
    private function sincronizzaGiorni(NutritionPlan $piano, array $giorni): void
    {
        // ⚠️ `daysConAlternative()`: cancellare solo i principali lascerebbe in
        // tabella giorni alternativi orfani, che senza il loro originale
        // tornerebbero a comparire come giorni veri.
        $piano->daysConAlternative()->delete();

        $posizione = 0;

        foreach ($giorni as $datiGiorno) {
            $giorno = $piano->days()->create([
                'position' => $posizione++,
                'name' => $datiGiorno['name'] ?? null,
                'notes' => $datiGiorno['notes'] ?? null,
            ]);

            $this->scriviIPasti($piano, $giorno, $datiGiorno['meals'] ?? []);

            $p = 0;

            foreach ($datiGiorno['alternatives'] ?? [] as $alt) {
                $giornoAlt = $piano->daysConAlternative()->create([
                    'alternativa_di_id' => $giorno->getKey(),
                    'position' => $p++,
                    'name' => $alt['name'] ?? null,
                    'notes' => $alt['notes'] ?? null,
                ]);

                $this->scriviIPasti($piano, $giornoAlt, $alt['meals'] ?? []);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $pasti */
    private function scriviIPasti(NutritionPlan $piano, NutritionPlanDay $giorno, array $pasti): void
    {
        $posizione = 0;

        foreach ($pasti as $datiPasto) {
            $pasto = $this->creaPasto($piano, $giorno, $datiPasto, $posizione++, null);

            $this->scriviGliAlimenti($pasto, $datiPasto['items'] ?? []);

            $p = 0;

            foreach ($datiPasto['alternatives'] ?? [] as $alt) {
                $pastoAlt = $this->creaPasto($piano, $giorno, $alt, $p++, $pasto->getKey());

                $this->scriviGliAlimenti($pastoAlt, $alt['items'] ?? []);
            }
        }
    }

    /** @param  array<string, mixed>  $dati */
    private function creaPasto(
        NutritionPlan $piano,
        NutritionPlanDay $giorno,
        array $dati,
        int $posizione,
        ?int $alternativaDi,
    ): NutritionPlanMeal {
        return $piano->mealsConAlternative()->create([
            'nutrition_plan_day_id' => $giorno->getKey(),
            'alternativa_di_id' => $alternativaDi,
            'meal' => $dati['meal'],
            'position' => $posizione,
            'title' => $dati['title'] ?? null,
            'notes' => $dati['notes'] ?? null,
        ]);
    }

    /** @param  list<array<string, mixed>>  $alimenti */
    private function scriviGliAlimenti(NutritionPlanMeal $pasto, array $alimenti): void
    {
        $posizione = 0;

        foreach ($alimenti as $dati) {
            $principale = $pasto->itemsConAlternative()->create(
                $this->campiAlimento($dati, $posizione++, null),
            );

            $p = 0;

            foreach ($dati['alternatives'] ?? [] as $alt) {
                $pasto->itemsConAlternative()->create(
                    $this->campiAlimento($alt, $p++, $principale->getKey()),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $dati
     * @return array<string, mixed>
     */
    private function campiAlimento(array $dati, int $posizione, ?int $alternativaDi): array
    {
        return [
            'alternativa_di_id' => $alternativaDi,
            'position' => $posizione,
            'description' => $dati['description'],
            'qty' => $dati['qty'] ?? null,
            'unit' => $dati['unit'] ?? null,
            'grams' => $dati['grams'] ?? null,
            'kcal' => $dati['kcal'] ?? null,
            'protein' => $dati['protein'] ?? null,
            'carbs' => $dati['carbs'] ?? null,
            'fat' => $dati['fat'] ?? null,
            'origine_valori' => $dati['origine_valori'] ?? 'manual',
        ];
    }

    /** @return array<string, mixed> */
    private function riassunto(NutritionPlan $p, User $chiGuarda): array
    {
        return [
            'id' => $p->getKey(),
            'origine_id' => $p->origine_id,
            'name' => $p->name,
            ...$this->rifAllievo($p, $chiGuarda),
            'status' => $p->status->value,
            'days_count' => $p->days()->count(),
            'totali' => $this->totali->perPiano($p),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /**
     * R4 — il «Rif. Allievo» esce **solo** verso chi l'ha scritto.
     *
     * 🚨 La chiave sparisce del tutto per gli altri, invece di arrivare vuota:
     * una chiave sempre presente e a volte piena direbbe comunque **che esiste
     * un riferimento**, e in un elenco di piani anonimi anche questo e'
     * un'informazione.
     *
     * @return array<string, mixed>
     */
    private function rifAllievo(NutritionPlan $p, User $chiGuarda): array
    {
        return $chiGuarda->getKey() === $p->created_by
            ? ['rif_allievo' => $p->rif_allievo]
            : [];
    }

    /** @return array<string, mixed> */
    private function dettaglio(NutritionPlan $p, User $chiGuarda): array
    {
        $giorni = [];

        foreach ($p->days()->with(['mealsConAlternative.itemsConAlternative'])->get() as $giorno) {
            $giorni[] = $this->giorno($giorno);
        }

        return [
            'id' => $p->getKey(),
            'origine_id' => $p->origine_id,
            'name' => $p->name,
            'notes' => $p->notes,
            ...$this->rifAllievo($p, $chiGuarda),
            'status' => $p->status->value,
            'target_kcal' => $p->target_kcal,
            'target_protein_g' => $p->target_protein_g,
            'target_carbs_g' => $p->target_carbs_g,
            'target_fat_g' => $p->target_fat_g,
            'totali' => $this->totali->perPiano($p),
            'days' => $giorni,
        ];
    }

    /** @return array<string, mixed> */
    private function giorno(NutritionPlanDay $g): array
    {
        return [
            'id' => $g->getKey(),
            'name' => $g->name,
            'notes' => $g->notes,
            'position' => $g->position,
            'totali' => $this->totali->perGiorno($g),
            'meals' => $g->meals->map(fn (NutritionPlanMeal $m): array => $this->pasto($m))->values()->all(),
            'alternatives' => $g->alternative->map(
                fn (NutritionPlanDay $a): array => $this->giorno($a),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pasto(NutritionPlanMeal $m): array
    {
        return [
            'id' => $m->getKey(),
            'meal' => $m->meal,
            'title' => $m->title,
            'notes' => $m->notes,
            'position' => $m->position,
            'totali' => $this->totali->perPasto($m),
            'items' => $m->items->map(fn ($i): array => [
                'id' => $i->getKey(),
                'description' => $i->description,
                'qty' => $i->qty,
                'unit' => $i->unit,
                'grams' => $i->grams,
                'kcal' => $i->kcal,
                'protein' => $i->protein,
                'carbs' => $i->carbs,
                'fat' => $i->fat,
                'origine_valori' => $i->origine_valori,
                'totali' => $this->totali->perAlimento($i),
                'alternatives' => $i->alternative->map(fn ($a): array => [
                    'id' => $a->getKey(),
                    'description' => $a->description,
                    'qty' => $a->qty,
                    'unit' => $a->unit,
                    'grams' => $a->grams,
                    'kcal' => $a->kcal,
                    'protein' => $a->protein,
                    'carbs' => $a->carbs,
                    'fat' => $a->fat,
                    'origine_valori' => $a->origine_valori,
                    'totali' => $this->totali->perAlimento($a),
                ])->values()->all(),
            ])->values()->all(),
            'alternatives' => $m->alternative->map(
                fn (NutritionPlanMeal $a): array => $this->pasto($a),
            )->values()->all(),
        ];
    }
}
