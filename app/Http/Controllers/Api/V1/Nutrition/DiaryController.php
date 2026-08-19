<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\NutritionPlan;
use App\Models\User;
use App\Services\Nutrition\DiaryService;
use App\Services\Nutrition\FoodUnit;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Il diario alimentare — B5.5.
 *
 * Tutte le somme passano da `DiaryService`, mai da qui: e' la regola che impedisce
 * a diario, dashboard e app di rispondere tre numeri diversi.
 */
class DiaryController extends Controller
{
    public function __construct(
        private readonly DiaryService $diary,
    ) {}

    // ───────────────────────── la giornata ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $giorno = $this->giorno($request);

        return response()->json(['data' => $this->diary->forDate($request->user(), $giorno)]);
    }

    public function targets(Request $request): JsonResponse
    {
        $giorno = $this->giorno($request);

        return response()->json([
            'data' => $this->diary->targetsFor($request->user(), $giorno),
        ]);
    }

    // ───────────────────────── voci ─────────────────────────

    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'meal' => ['nullable', Rule::enum(MealType::class)],
            'eaten_at' => ['nullable', 'date'],
            'grams' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'qty' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:16'],
            'kcal' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'protein' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:2000'],
        ]);

        $utente = $request->user();
        $quando = isset($dati['eaten_at']) ? Carbon::parse($dati['eaten_at']) : now();

        $voce = FoodEntry::create(array_merge($dati, [
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'eaten_at' => $quando,
            // Il pasto si deduce dall'ora quando l'app non lo dice: chiedere
            // «che pasto era?» per una banana alle 16 e' un attrito che fa
            // smettere di registrare.
            'meal' => $this->pasto($dati['meal'] ?? null, $quando, $utente),
        ]));

        return response()->json(['data' => $this->diary->voce($voce)], 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $voce = $this->voceDi($request, $entry);

        if ($voce === null) {
            return response()->json(['message' => __('Voce non trovata.')], 404);
        }

        $dati = $request->validate([
            'description' => ['sometimes', 'string', 'max:255'],
            'meal' => ['sometimes', Rule::enum(MealType::class)],
            'eaten_at' => ['sometimes', 'date'],
            'grams' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:20000'],
            'qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:16'],
            'kcal' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:20000'],
            'protein' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'carbs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'fat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
        ]);

        if (isset($dati['meal'])) {
            $dati['meal'] = $this->pasto($dati['meal'], $voce->eaten_at, $request->user());
        }

        $dati = $this->ricalcolaSeCambiaLaQuantita($voce, $dati, $request);

        $voce->fill($dati)->save();

        return response()->json(['data' => $this->diary->voce($voce->refresh())]);
    }

    /**
     * Quando cambia la quantità e i macro non arrivano, li ricalcola.
     *
     * 🚨 **Il ricalcolo lo fa il SERVER, non l'app.**
     *
     * La tabella unità→grammi (`FoodUnit`) e i valori per 100 g stanno qui: se
     * l'app li riscrivesse in Dart per aggiornare i numeri mentre si digita,
     * avremmo due conversioni da tenere allineate — e il giorno che ne cambia
     * una sola, il diario mostra un totale e il database ne contiene un altro,
     * senza che niente lo segnali. È la stessa ragione per cui il fabbisogno non
     * si ricalcola nell'app.
     *
     * **Il contratto è esplicito**: i macro che arrivano vincono sempre. Se non
     * arrivano ed è cambiata la quantità, si riscalano dai valori per 100 g. Se
     * quelli non ci sono — una voce inserita a mano senza riferimento — non si
     * inventa niente e restano com'erano: meglio un numero vecchio e visibile
     * che uno nuovo e sbagliato.
     *
     * ⚠️ Il fattore per unità si prende **dalla voce**, non dalla tabella
     * generica, finché l'unità non cambia: se l'AI ha detto che un cucchiaio di
     * quell'olio pesa 14 g, raddoppiando la quantità devono venire 28 g, non i
     * 30 g della conversione generica.
     *
     * @param  array<string, mixed>  $dati
     * @return array<string, mixed>
     */
    private function ricalcolaSeCambiaLaQuantita(FoodEntry $voce, array $dati, Request $request): array
    {
        $tocca = $request->has('qty') || $request->has('unit') || $request->has('grams');

        if (! $tocca) {
            return $dati;
        }

        $qty = $dati['qty'] ?? $voce->qty;
        $unit = $dati['unit'] ?? $voce->unit;

        // I grammi espliciti vincono su qualunque conversione: l'utente ha
        // pesato la porzione, e nessuna tabella sa più di una bilancia.
        if (! $request->has('grams') || $dati['grams'] === null) {
            $grammiPerUnita = ($voce->grams !== null && $voce->qty !== null && (float) $voce->qty > 0)
                ? (float) $voce->grams / (float) $voce->qty
                : null;

            $stessaUnita = $unit === $voce->unit;

            $dati['grams'] = ($stessaUnita && $grammiPerUnita !== null && $qty !== null)
                ? round((float) $qty * $grammiPerUnita, 2)
                : FoodUnit::toGrams($qty === null ? null : (float) $qty, $unit);
        }

        $grammi = $dati['grams'] ?? $voce->grams;

        if ($grammi === null || $voce->kcal_100 === null) {
            return $dati;
        }

        $fattore = (float) $grammi / 100;

        foreach (['kcal', 'protein', 'carbs', 'fat'] as $macro) {
            if ($request->has($macro)) {
                continue;
            }

            $per100 = $voce->{$macro.'_100'};

            if ($per100 !== null) {
                $dati[$macro] = round((float) $per100 * $fattore, 2);
            }
        }

        return $dati;
    }

    public function destroy(Request $request, int $entry): JsonResponse
    {
        $voce = $this->voceDi($request, $entry);

        if ($voce === null) {
            return response()->json(['message' => __('Voce non trovata.')], 404);
        }

        $voce->delete();

        return response()->json(null, 204);
    }

    // ───────────────────────── bruciate dichiarate ─────────────────────────

    /**
     * L'override manuale delle calorie bruciate del giorno.
     *
     * 🚨 Sostituisce, non si somma: e' una dichiarazione complessiva («oggi ho
     * bruciato 800»), e sommarla alle sessioni raddoppierebbe la giornata di chi
     * corregge il numero dopo essersi allenato.
     */
    public function storeBurn(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'date' => ['nullable', 'date'],
            /*
             * 🚨 **`present` e `nullable`, NON `required`** — difetto misurato
             * il 19/08/2026.
             *
             * Il modulo dell'app dice *«Vuoto = usa la stima degli
             * allenamenti»*, e mandava `kcal: null` per ottenerlo. Con
             * `required` quel `null` veniva rifiutato con un **422**: la
             * funzione promessa nell'interfaccia non esisteva sul server.
             *
             * ⚠️ `present` e non solo `nullable`: il campo deve **esserci**. Una
             * richiesta che lo omette del tutto e' un client che ha sbagliato,
             * non qualcuno che vuole tornare alla stima — e le due cose non
             * devono somigliarsi.
             *
             * 💡 E' la differenza fra «non lo so» e «oggi ho bruciato zero»:
             * `null` toglie la riga, `0` la scrive a zero.
             */
            'kcal' => ['present', 'nullable', 'integer', 'min:0', 'max:20000'],
        ]);

        $utente = $request->user();
        $oggi = $utente->giornoDiOggi();
        $giorno = isset($dati['date']) ? $utente->giorno($dati['date']) : $oggi;

        /*
         * 🚨 **Il «non nel futuro» si controlla qui e non con
         * `before_or_equal:today`** — A3.
         *
         * Quella regola confronta con il giorno **UTC**: alle 00:30 di Roma
         * l'utente ha davanti il 12 agosto, ma per Laravel «today» e' ancora
         * l'11, e la richiesta veniva rifiutata con un 422 incomprensibile —
         * «la data non puo' essere futura» su una data che era **oggi**.
         */
        if ($oggi->primaDi($giorno)) {
            throw ValidationException::withMessages([
                'date' => __('Non si possono registrare calorie di un giorno futuro.'),
            ]);
        }

        if ($dati['kcal'] === null) {
            DailyBurn::dimentica($utente, $giorno);

            return response()->json(['data' => [
                'date' => $giorno->etichetta,
                'kcal' => null,
            ]], 201);
        }

        $riga = DailyBurn::put($utente, $giorno, $dati['kcal']);

        return response()->json(['data' => [
            'date' => $riga->date->toDateString(),
            'kcal' => $riga->kcal,
        ]], 201);
    }

    // ───────────────────────── piano attivo ─────────────────────────

    public function plan(Request $request): JsonResponse
    {
        $piano = NutritionPlan::activeFor($request->user());

        if ($piano === null) {
            return response()->json(['data' => null]);
        }

        $piano->load('meals.items');

        return response()->json(['data' => [
            'id' => $piano->id,
            'name' => $piano->name,
            'notes' => $piano->notes,
            'targets' => [
                'kcal' => $piano->target_kcal,
                'protein_g' => $piano->target_protein_g,
                'carbs_g' => $piano->target_carbs_g,
                'fat_g' => $piano->target_fat_g,
            ],
            'starts_at' => $piano->starts_at?->toDateString(),
            'ends_at' => $piano->ends_at?->toDateString(),
            'meals' => $piano->meals->map(fn ($pasto): array => [
                'id' => $pasto->id,
                'meal' => $pasto->meal->value,
                'label' => $pasto->meal->label(),
                'title' => $pasto->title,
                'notes' => $pasto->notes,
                'totals' => $pasto->totals(),
                'items' => $pasto->items->map(fn ($i): array => [
                    'id' => $i->id,
                    'description' => $i->description,
                    'qty' => $i->qty,
                    'unit' => $i->unit,
                    'grams' => $i->grams,
                    'kcal' => $i->kcal,
                    'protein' => $i->protein,
                    'carbs' => $i->carbs,
                    'fat' => $i->fat,
                    /*
                     * G4 — le alternative sono **righe**, non piu' JSON.
                     *
                     * 💡 La forma verso l'app **non cambia**: resta una lista di
                     * oggetti con gli stessi campi. Cambiare anche il contratto
                     * mentre si cambia lo schema vorrebbe dire rompere l'app
                     * installata per una ragione che all'app non interessa.
                     */
                    'alternatives' => $i->alternative->map(fn ($a): array => [
                        'description' => $a->description,
                        'qty' => $a->qty,
                        'unit' => $a->unit,
                        'grams' => $a->grams,
                        'kcal' => $a->kcal,
                        'protein' => $a->protein,
                        'carbs' => $a->carbs,
                        'fat' => $a->fat,
                    ])->values()->all(),
                ])->all(),
            ])->all(),
        ]]);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Il giorno chiesto, nel fuso di chi lo chiede — A3.
     *
     * 🚨 **`?date=2026-08-12` e' gia' un'etichetta locale**, e va presa cosi'
     * com'e': l'app manda il giorno che ha mostrato all'utente. Quando non
     * arriva niente, «oggi» e' quello della persona — con `Carbon::today()` era
     * quello di Greenwich, e dopo le 22:00 di Roma il diario si apriva su ieri.
     */
    private function giorno(Request $request): GiornoLocale
    {
        $d = $request->query('date');
        $utente = $request->user();

        return is_string($d) && $d !== ''
            ? $utente->giorno($d)
            : $utente->giornoDiOggi();
    }

    private function voceDi(Request $request, int $id): ?FoodEntry
    {
        return FoodEntry::query()->forUser($request->user())->find($id);
    }

    /**
     * Il pasto dichiarato dall'app, oppure quello dedotto dall'ora.
     *
     * 🚨 La deduzione usa **gli orari di questa persona** (`fromProfile`), non le
     * soglie di serie: chi cena alle 18 vuole che un cibo delle 19 finisca nella
     * cena, non nella merenda. Prima della fase C il profilo veniva ignorato.
     *
     * 🚨 **E l'ora dev'essere quella dell'orologio di chi mangia** — A3. Qui
     * arrivava un istante in UTC: a Roma d'estate le 20:00 diventavano le 18:00,
     * e la cena finiva sistematicamente nella merenda. Era il difetto piu'
     * subdolo del gruppo, perche' non sbaglia **il giorno** — sbaglia solo la
     * riga in cui la voce compare, e sembra una scelta discutibile del prodotto
     * invece che un errore.
     */
    private function pasto(?string $valore, ?Carbon $quando, User $utente): MealType
    {
        $tipo = $valore !== null ? MealType::tryFrom($valore) : null;

        return $tipo ?? MealType::fromProfile(
            ($quando ?? Carbon::now())->copy()->setTimezone($utente->fusoOrario()),
            $utente->profile?->meal_hours,
        );
    }
}
