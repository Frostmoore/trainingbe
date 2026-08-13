<?php

declare(strict_types=1);

namespace App\Services\Nutrition;

use App\Models\NutritionPlan;
use App\Models\NutritionPlanDay;
use App\Models\NutritionPlanItem;
use App\Models\NutritionPlanMeal;

/**
 * I totali di un piano alimentare, a ogni livello — G5.10 (D14).
 *
 * ── 🚨 Si calcolano, non si memorizzano ───────────────────────────────────
 *
 * La verita' sono gli alimenti. Un totale scritto in tabella e' una copia che,
 * alla prima modifica di un alimento, diventa **falsa senza dare errore** — ed
 * e' la forma di errore che questo progetto ha gia' incontrato cinque volte.
 *
 * ⚠️ **Ma nella busta cifrata i totali ci viaggiano dentro** (§5.11.2 del
 * piano): se il telefono li ricalcolasse per conto suo, due schermate
 * potrebbero dire due numeri diversi. L'unica cosa peggiore di un totale
 * sbagliato e' un totale sbagliato **in un posto solo**. Il servizio che li
 * calcola e' questo, e il suo risultato finisce sia nell'API sia nella busta.
 *
 * ── 🚨 Come si contano le alternative ─────────────────────────────────────
 *
 * Il totale di un livello somma **solo i principali**. Sommare anche le
 * alternative gonfierebbe ogni piano: un pranzo con due alternative varrebbe
 * tre pranzi.
 *
 * 💡 E **ogni alternativa porta il proprio totale accanto a se'**, calcolato
 * come se sostituisse l'originale. E' l'unica ragione per cui un'alternativa
 * serve a qualcosa: il trainer deve vedere subito se regge il conto.
 */
class TotaliDelPiano
{
    /** @var array{kcal: float, protein: float, carbs: float, fat: float} */
    private const ZERO = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

    /**
     * Il «totale» di un alimento e' l'alimento stesso.
     *
     * 💡 Esiste per simmetria: chi disegna l'interfaccia chiede il totale a
     * ogni livello senza dover sapere che a uno dei quattro la domanda e'
     * banale.
     *
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    public function perAlimento(NutritionPlanItem $item): array
    {
        return $this->arrotonda([
            'kcal' => (float) ($item->kcal ?? 0),
            'protein' => (float) ($item->protein ?? 0),
            'carbs' => (float) ($item->carbs ?? 0),
            'fat' => (float) ($item->fat ?? 0),
        ]);
    }

    /** @return array{kcal: float, protein: float, carbs: float, fat: float} */
    public function perPasto(NutritionPlanMeal $pasto): array
    {
        $totale = self::ZERO;

        // 🚨 `items()` esclude gia' le alternative (vedi la relazione), ma il
        // filtro e' ripetuto qui di proposito: questo servizio deve dare la
        // risposta giusta anche se un giorno qualcuno «semplificasse» la
        // relazione. Un totale sbagliato non si vede.
        foreach ($pasto->items()->soloPrincipali()->get() as $item) {
            $totale = $this->somma($totale, $this->perAlimento($item));
        }

        return $this->arrotonda($totale);
    }

    /** @return array{kcal: float, protein: float, carbs: float, fat: float} */
    public function perGiorno(NutritionPlanDay $giorno): array
    {
        $totale = self::ZERO;

        foreach ($giorno->meals()->soloPrincipali()->get() as $pasto) {
            $totale = $this->somma($totale, $this->perPasto($pasto));
        }

        return $this->arrotonda($totale);
    }

    /**
     * Il totale del piano e' la **media** dei suoi giorni principali.
     *
     * ⚠️ **Media e non somma, ed e' una scelta.** La somma di sette giorni non
     * vuol dire niente per chi legge: nessuno mangia una settimana in una volta.
     * Il numero che serve a un trainer e' «quante calorie al giorno prescrive
     * questo piano», e quella e' la media.
     *
     * 💡 Un piano a un solo giorno da' lo stesso numero in entrambi i modi, che
     * e' il caso piu' comune — ed e' il motivo per cui la differenza puo'
     * passare inosservata finche' qualcuno non scrive un piano a piu' giorni.
     *
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    public function perPiano(NutritionPlan $piano): array
    {
        $giorni = $piano->days()->soloPrincipali()->get();

        if ($giorni->isEmpty()) {
            return self::ZERO;
        }

        $totale = self::ZERO;

        foreach ($giorni as $giorno) {
            $totale = $this->somma($totale, $this->perGiorno($giorno));
        }

        $quanti = $giorni->count();

        return $this->arrotonda(array_map(
            static fn (float $n): float => $n / $quanti,
            $totale,
        ));
    }

    /**
     * L'albero completo dei totali, come lo vogliono l'API e la busta cifrata.
     *
     * 🚨 **Le alternative ci sono, ognuna col proprio totale**, sotto la chiave
     * `alternative`. Il trainer deve poter confrontare a colpo d'occhio il
     * pranzo con il pranzo-bis: e' l'unica cosa che rende utile un'alternativa.
     *
     * @return array<string, mixed>
     */
    public function albero(NutritionPlan $piano): array
    {
        $giorni = [];

        foreach ($piano->days()->soloPrincipali()->get() as $giorno) {
            $giorni[] = $this->alberoDelGiorno($giorno);
        }

        return [
            'piano' => $this->perPiano($piano),
            'giorni' => $giorni,
        ];
    }

    /** @return array<string, mixed> */
    private function alberoDelGiorno(NutritionPlanDay $giorno): array
    {
        $pasti = [];

        foreach ($giorno->meals()->soloPrincipali()->get() as $pasto) {
            $pasti[] = [
                'id' => $pasto->getKey(),
                'totali' => $this->perPasto($pasto),
                'alimenti' => $pasto->items()->soloPrincipali()->get()
                    ->map(fn (NutritionPlanItem $i): array => [
                        'id' => $i->getKey(),
                        'totali' => $this->perAlimento($i),
                        'alternative' => $i->alternative->map(
                            fn (NutritionPlanItem $a): array => [
                                'id' => $a->getKey(),
                                'totali' => $this->perAlimento($a),
                            ],
                        )->values()->all(),
                    ])->values()->all(),
                'alternative' => $pasto->alternative->map(
                    fn (NutritionPlanMeal $a): array => [
                        'id' => $a->getKey(),
                        'totali' => $this->perPasto($a),
                    ],
                )->values()->all(),
            ];
        }

        return [
            'id' => $giorno->getKey(),
            'totali' => $this->perGiorno($giorno),
            'pasti' => $pasti,
            'alternative' => $giorno->alternative->map(
                fn (NutritionPlanDay $a): array => [
                    'id' => $a->getKey(),
                    'totali' => $this->perGiorno($a),
                ],
            )->values()->all(),
        ];
    }

    /**
     * @param  array{kcal: float, protein: float, carbs: float, fat: float}  $a
     * @param  array{kcal: float, protein: float, carbs: float, fat: float}  $b
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    private function somma(array $a, array $b): array
    {
        foreach ($a as $chiave => $valore) {
            $a[$chiave] = $valore + $b[$chiave];
        }

        return $a;
    }

    /**
     * @param  array{kcal: float, protein: float, carbs: float, fat: float}  $t
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    private function arrotonda(array $t): array
    {
        return array_map(static fn (float $n): float => round($n, 2), $t);
    }
}
