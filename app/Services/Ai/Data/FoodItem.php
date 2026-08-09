<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Un alimento riconosciuto dal modello.
 *
 * 🚨 **`grams` arriva dal modello e vince sulla tabella di `FoodUnit`.** Un
 * cucchiaio d'olio pesa 14 g, uno di miele 21: la tabella deterministica ne
 * conosce uno solo perche' non sa di che alimento si parla, il modello si'.
 * Percio' qui i grammi sono un campo a se' e non una derivazione di qty × unit.
 */
final readonly class FoodItem
{
    public function __construct(
        public string $name,
        public ?float $qty,
        public ?string $unit,
        public ?float $grams,
        public ?float $kcal,
        public ?float $protein,
        public ?float $carbs,
        public ?float $fat,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? 'Alimento'),
            qty: isset($data['qty']) ? (float) $data['qty'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            grams: isset($data['grams']) ? (float) $data['grams'] : null,
            kcal: isset($data['kcal']) ? (float) $data['kcal'] : null,
            protein: isset($data['protein']) ? (float) $data['protein'] : null,
            carbs: isset($data['carbs']) ? (float) $data['carbs'] : null,
            fat: isset($data['fat']) ? (float) $data['fat'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'grams' => $this->grams,
            'kcal' => $this->kcal,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
        ];
    }
}
