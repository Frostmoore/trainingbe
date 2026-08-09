<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Un esercizio letto da un PDF, prima della riconciliazione con la libreria.
 *
 * `confidence` e' **per riga** e non solo per scheda: in un PDF impaginato male
 * tre esercizi su venti sono illeggibili e diciassette sono perfetti. Con una
 * sola confidenza complessiva o si butta via tutto o si accetta tutto; con
 * quella per riga la pagina di revisione (B7.4) puo' evidenziare esattamente le
 * tre righe da guardare.
 */
final readonly class ParsedWorkoutExercise
{
    public function __construct(
        public string $name,
        public ?int $sets,
        public ?string $reps,
        public ?int $restSec,
        public ?float $targetWeight,
        public ?string $notes,
        public float $confidence,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            sets: isset($data['sets']) ? (int) $data['sets'] : null,
            reps: isset($data['reps']) ? (string) $data['reps'] : null,
            restSec: isset($data['rest_sec']) ? (int) $data['rest_sec'] : null,
            targetWeight: isset($data['target_weight']) ? (float) $data['target_weight'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            confidence: (float) ($data['confidence'] ?? 0.0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'sets' => $this->sets,
            'reps' => $this->reps,
            'rest_sec' => $this->restSec,
            'target_weight' => $this->targetWeight,
            'notes' => $this->notes,
            'confidence' => $this->confidence,
        ];
    }
}
