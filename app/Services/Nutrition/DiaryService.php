<?php

declare(strict_types=1);

namespace App\Services\Nutrition;

use App\Enums\MealType;
use App\Models\FoodEntry;
use App\Models\NutritionPlan;
use App\Models\User;
use App\Services\Training\WorkoutCalorieService;
use App\Support\Tempo\GiornoLocale;

/**
 * La giornata alimentare di una persona, composta una volta sola.
 *
 * 🚨 **Esiste per impedire che il diario, la dashboard e l'API rispondano tre
 * numeri diversi.** Nell'app storica succedeva esattamente questo, perche' ogni
 * schermata sommava per conto suo. Qui la somma si fa in un posto, e chi la
 * vuole la chiede.
 *
 * **Il target del giorno = target base + calorie bruciate.** E' una regola di
 * prodotto confermata: chi si allena puo' mangiare di piu' quel giorno, e
 * mostrargli il target fisso lo porterebbe a credere di aver sforato quando non
 * e' vero.
 */
class DiaryService
{
    public function __construct(
        private readonly WorkoutCalorieService $calorie,
    ) {}

    /**
     * Tutto quello che serve per disegnare la giornata.
     *
     * @return array<string, mixed>
     */
    public function forDate(User $user, GiornoLocale $giorno): array
    {
        $voci = FoodEntry::query()
            ->forUser($user)
            ->onDate($giorno)
            ->orderBy('eaten_at')
            ->get();

        /*
         * ⛔ **Le calorie bruciate non si calcolano piu' qui** — FASE 11.6,
         * 21/08/2026.
         *
         * 🚨 Nascevano da `workout_sessions` e `daily_burns`, che dopo il
         * trasloco stanno sul telefono. ⚠️ Lasciare il campo avrebbe voluto dire
         * mandare **zero a tutti senza un errore**: l'obiettivo calorico
         * avrebbe smesso di comprendere l'allenamento, e chi si allena avrebbe
         * mangiato meno di quanto poteva credendo di essere in regola.
         *
         * 💡 Il campo esce dalla risposta invece di valere zero: cosi' l'app
         * che lo cercasse trova `null` — «non lo so» — e non uno zero che
         * afferma qualcosa di falso.
         */
        $target = $this->targetsFor($user, $giorno);

        $totali = FoodEntry::totals($voci);

        return [
            /*
             * ⚠️ **L'etichetta, non `inizio()->toDateString()`** — A3. Il
             * secondo darebbe «le 22:00 di ieri», cioe' il giorno prima: e' la
             * trappola trovata provando a fare questa migrazione a meta'.
             */
            'date' => $giorno->etichetta,
            'meals' => $this->perPasto($voci),
            'totals' => $totali,
            'targets' => $target,
            'remaining' => $target === null ? null : [
                'kcal' => round(($target['kcal'] ?? 0) - $totali['kcal'], 2),
                'protein' => round(($target['protein_g'] ?? 0) - $totali['protein'], 2),
                'carbs' => round(($target['carbs_g'] ?? 0) - $totali['carbs'], 2),
                'fat' => round(($target['fat_g'] ?? 0) - $totali['fat'], 2),
            ],
        ];
    }

    /**
     * I target di oggi.
     *
     * Ordine: il piano alimentare attivo vince sul calcolo automatico — e'
     * stato scritto da un professionista che conosce la persona, mentre la
     * formula conosce solo quattro numeri. Se non c'e' piano, si calcola dal
     * profilo. Se manca anche quello, `null`: meglio niente che un target
     * inventato su cui costruire una dieta.
     *
     * @return array<string, mixed>|null
     */
    public function targetsFor(User $user, GiornoLocale $giorno): ?array
    {
        /*
         * 🚨 **Il target NON comprende piu' le bruciate** — FASE 11.5.
         *
         * ⚠️ Prima era `piano + bruciate`, e l'app sapeva di non doverle
         * risommare. Adesso il server le bruciate non le conosce, quindi manda
         * il numero del piano **e basta**: la somma la fa `TargetDelGiorno`
         * nell'app, che e' l'unico posto dove quella regola vive.
         *
         * 💡 E' anche piu' semplice di prima: il doppio conteggio non e' piu'
         * possibile, perche' non c'e' piu' nessun altro che possa sommare.
         */
        $bruciate = 0;

        $piano = NutritionPlan::activeFor($user, $giorno);

        if ($piano !== null && $piano->target_kcal !== null) {
            return [
                'kcal' => $piano->target_kcal + $bruciate,
                'kcal_base' => $piano->target_kcal,
                'protein_g' => $piano->target_protein_g,
                'carbs_g' => $piano->target_carbs_g,
                'fat_g' => $piano->target_fat_g,
                'source' => 'plan',
                'plan_id' => $piano->id,
            ];
        }

        $calcolati = $user->profile?->computedTargets();

        if ($calcolati === null) {
            return null;
        }

        return [
            'kcal' => $calcolati['kcal'] + $bruciate,
            'kcal_base' => $calcolati['kcal'],
            'protein_g' => $calcolati['protein_g'],
            'carbs_g' => $calcolati['carbs_g'],
            'fat_g' => $calcolati['fat_g'],
            'source' => 'profile',
            'bmr' => $calcolati['bmr'],
            'tdee' => $calcolati['tdee'],
        ];
    }

    /**
     * Le voci raggruppate per pasto, nell'ordine della giornata.
     *
     * I pasti vuoti ci sono lo stesso: l'app deve poter disegnare sei sezioni
     * senza inventarsi quelle che mancano, e «colazione: niente» e' un'informazione.
     *
     * @param  iterable<FoodEntry>  $voci
     * @return list<array<string, mixed>>
     */
    private function perPasto(iterable $voci): array
    {
        $perTipo = [];

        foreach ($voci as $v) {
            $perTipo[$v->meal->value][] = $v;
        }

        $out = [];

        foreach (MealType::ordered() as $pasto) {
            $righe = $perTipo[$pasto->value] ?? [];

            $out[] = [
                'meal' => $pasto->value,
                'label' => $pasto->label(),
                'entries' => array_map(fn (FoodEntry $v): array => $this->voce($v), $righe),
                'totals' => FoodEntry::totals($righe),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function voce(FoodEntry $v): array
    {
        return [
            'id' => $v->id,
            'description' => $v->description,
            'eaten_at' => $v->eaten_at?->toIso8601String(),
            'meal' => $v->meal->value,
            'grams' => $v->grams,
            'qty' => $v->qty,
            'unit' => $v->unit,
            'kcal' => $v->kcal,
            'protein' => $v->protein,
            'carbs' => $v->carbs,
            'fat' => $v->fat,

            /*
             * I valori per 100 g. Servono all'app per **sapere** se cambiando
             * la quantità i macro si aggiorneranno da soli: quando mancano —
             * una voce scritta a mano senza riferimento — il modulo deve dirlo,
             * invece di lasciar credere a un ricalcolo che non avverrà.
             *
             * ⚠️ Il ricalcolo lo fa comunque il server: questi numeri non sono
             * lì perché l'app li usi per contare.
             */
            'kcal_100' => $v->kcal_100,
            'protein_100' => $v->protein_100,
            'carbs_100' => $v->carbs_100,
            'fat_100' => $v->fat_100,

            'source' => $v->source->value,
        ];
    }
}
