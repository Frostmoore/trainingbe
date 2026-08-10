<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Enums\MuscleGroup;
use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La libreria esercizi vista dall'app — B4.5.
 *
 * Restituisce i globali **piu'** quelli della palestra: e' `TenantOrGlobalScope`
 * a farlo, senza che questo controller debba saperne niente. L'iscritto la usa
 * per cercare un esercizio quando registra una serie fuori scheda.
 */
class ExerciseController extends Controller
{
    public function __construct(private readonly ExerciseMatcher $matcher) {}

    public function index(Request $request): JsonResponse
    {
        $query = Exercise::query()->ordered();

        if (($termine = trim((string) $request->query('q'))) !== '') {
            $query->search($termine);
        }

        if (($gruppo = $request->query('muscle_group')) !== null) {
            $query->where('muscle_group', $gruppo);
        }

        $esercizi = $query->limit(min(200, (int) $request->integer('limit', 100)))->get();

        return response()->json([
            'data' => $esercizi->map(fn (Exercise $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'muscle_group' => $e->muscle_group?->value,
                'equipment' => $e->equipment,
                // Serve all'app per distinguere «esercizio della piattaforma» da
                // «aggiunto dalla mia palestra», che e' l'unica differenza
                // percepibile fra i due.
                'is_global' => $e->isGlobal(),
            ])->all(),
        ]);
    }

    /**
     * Un esercizio che la libreria non ha — C2.3.
     *
     * 🚨 **Nasce della PALESTRA, non della persona** (decisione D3). Dentro una
     * palestra il vocabolario deve essere comune: se ogni iscritto si creasse i
     * propri, lo storico di due persone che fanno la stessa cosa non sarebbe
     * confrontabile e il trainer si troverebbe venti nomi diversi per la panca
     * piana. `created_by` resta come traccia di chi l'ha introdotto.
     *
     * ⚠️ **Risponde 200 se esiste già, 201 solo se l'ha creato davvero.** Non è
     * un dettaglio di stile: `ExerciseMatcher` riconosce che «panca piana
     * bilanciere» è la «panca piana» che la palestra ha già, e restituisce
     * quella. Creare un doppione a ogni richiesta è esattamente la degenerazione
     * che `slug_normalized` esiste per impedire.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'muscle_group' => ['nullable', Rule::enum(MuscleGroup::class)],
            'equipment' => ['nullable', 'string', 'max:64'],
        ]);

        $utente = $request->user();

        $esistenti = Exercise::query()->count();

        $esercizio = $this->matcher->match($dati['name'], $utente->tenant_id, $utente);

        $creato = Exercise::query()->count() > $esistenti;

        // I dettagli si scrivono solo su ciò che è appena nato: sovrascrivere il
        // gruppo muscolare di un esercizio della piattaforma perché un iscritto
        // ha mandato un valore diverso lo cambierebbe per tutta la palestra.
        if ($creato && ($dati['muscle_group'] ?? $dati['equipment'] ?? null) !== null) {
            $esercizio->forceFill(array_filter([
                'muscle_group' => $dati['muscle_group'] ?? null,
                'equipment' => $dati['equipment'] ?? null,
            ]))->save();
        }

        return response()->json([
            'data' => [
                'id' => $esercizio->id,
                'name' => $esercizio->name,
                'muscle_group' => $esercizio->muscle_group?->value,
                'equipment' => $esercizio->equipment,
                'is_global' => $esercizio->isGlobal(),
                // Dice all'app se ha creato qualcosa o se ha ritrovato ciò che
                // c'era: senza, non può spiegare perché il nome che ha scritto
                // è tornato indietro diverso.
                'created' => $creato,
            ],
        ], $creato ? 201 : 200);
    }
}
