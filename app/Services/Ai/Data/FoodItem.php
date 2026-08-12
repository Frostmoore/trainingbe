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
        /**
         * I grammi di **alcol etilico**, che rendono 7 kcal l'uno.
         *
         * 🚨 **Esiste per la guardia sulla coerenza energetica**, non per il
         * diario: senza, un bicchiere di vino sembrerebbe incoerente ogni volta.
         * Il vino ha circa 4 g di carboidrati e 15 g di alcol per 150 ml: Atwater
         * senza l'alcol prevede 16 kcal contro le 125 vere, cioe' l'87% di
         * scarto — e la guardia punirebbe una stima perfettamente giusta.
         *
         * ⚠️ **Non ha una colonna nel database**, ed e' voluto: non entra in
         * nessun totale e nessuna schermata lo mostra. Sopravvive comunque in
         * `food_entries.ai_raw`, che e' JSON — cosi' il giorno che servisse
         * davvero il dato storico c'e' gia'.
         */
        public ?float $alcohol = null,
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
            alcohol: isset($data['alcohol']) ? (float) $data['alcohol'] : null,
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
            'alcohol' => $this->alcohol,
        ];
    }
}
