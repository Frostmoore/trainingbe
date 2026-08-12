<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Services\Ai\Guardie\CoerenzaEnergetica;

/**
 * Cosa il modello ha visto in un piatto o letto in una frase.
 *
 * `confidence` non e' decorazione: sotto una soglia l'app deve chiedere conferma
 * invece di scrivere nel diario. Una stima sbagliata accettata in silenzio si
 * scopre settimane dopo, quando i totali non tornano e non si sa piu' quale
 * voce fosse sbagliata.
 */
final readonly class FoodEstimate
{
    /**
     * @param  list<FoodItem>  $items
     * @param  array{kcal: ?float, protein: ?float, carbs: ?float, fat: ?float}  $totals
     */
    public function __construct(
        public array $items,
        public array $totals,
        public float $confidence,
        public ?string $note = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            static fn (array $i): FoodItem => FoodItem::fromArray($i),
            array_values($data['items'] ?? []),
        );

        $stima = new self(
            items: $items,
            totals: self::normalizeTotals($data['totals'] ?? null, $items),
            confidence: (float) ($data['confidence'] ?? 0.0),
            note: isset($data['note']) ? (string) $data['note'] : null,
        );

        /*
         * 🚨 **La guardia si applica QUI e non nei provider.**
         *
         * `FoodEstimate::fromArray()` e' l'unica porta da cui passa ogni stima —
         * Anthropic, OpenAI, il doppio dei test e la conferma dall'app. Metterla
         * nei provider vorrebbe dire ripeterla in sei punti e dimenticarla nel
         * settimo: il fornitore aggiunto fra un anno nascerebbe **senza**
         * guardia, e funzionerebbe benissimo.
         *
         * ⚠️ E' la stessa ragione per cui `scriviVoci()` e' un metodo solo.
         */
        return CoerenzaEnergetica::correggi($stima);
    }

    /**
     * I totali: quelli del modello se ci sono, altrimenti la somma delle voci.
     *
     * Ricalcolarli quando mancano evita che un chiamante trovi `null` e sommi a
     * modo suo — che e' il modo in cui due punti del sistema arrivano a due
     * totali diversi per lo stesso pasto.
     *
     * @param  array<string, mixed>|null  $totals
     * @param  list<FoodItem>  $items
     * @return array{kcal: ?float, protein: ?float, carbs: ?float, fat: ?float}
     */
    private static function normalizeTotals(?array $totals, array $items): array
    {
        if (is_array($totals) && isset($totals['kcal'])) {
            return [
                'kcal' => (float) $totals['kcal'],
                'protein' => isset($totals['protein']) ? (float) $totals['protein'] : null,
                'carbs' => isset($totals['carbs']) ? (float) $totals['carbs'] : null,
                'fat' => isset($totals['fat']) ? (float) $totals['fat'] : null,
            ];
        }

        $somma = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($items as $i) {
            $somma['kcal'] += $i->kcal ?? 0;
            $somma['protein'] += $i->protein ?? 0;
            $somma['carbs'] += $i->carbs ?? 0;
            $somma['fat'] += $i->fat ?? 0;
        }

        return array_map(static fn (float $n): float => round($n, 2), $somma);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (FoodItem $i): array => $i->toArray(), $this->items),
            'totals' => $this->totals,
            'confidence' => $this->confidence,
            'note' => $this->note,
        ];
    }
}
