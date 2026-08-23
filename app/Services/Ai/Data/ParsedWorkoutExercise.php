<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Enums\MuscleGroup;

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

        /**
         * 🆕 3b-A.3.4 — i muscoli, come li ha detti il modello.
         *
         * 🚨 Gia' **validati contro l'enum** in `fromArray`: quello che arriva e'
         * testo di un modello, e un `pettorali` scritto in italiano finirebbe
         * dritto nella libreria come gruppo muscolare inesistente.
         *
         * ⚠️ `null` vuol dire «non lo so», e resta diverso da un elenco vuoto.
         */
        public ?MuscleGroup $muscleGroup,

        /** @var list<string> */
        public array $secondaryMuscles,

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
            muscleGroup: isset($data['muscle_group'])
                ? MuscleGroup::tryFrom((string) $data['muscle_group'])
                : null,

            // ⛔ I valori che l'enum non conosce si buttano, non si tengono: una
            // libreria con dentro `pettorali` non si colora e non si filtra.
            secondaryMuscles: array_values(array_filter(array_map(
                static fn (mixed $v): ?string => MuscleGroup::tryFrom((string) $v)?->value,
                (array) ($data['secondary_muscles'] ?? []),
            ))),

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
            'muscle_group' => $this->muscleGroup?->value,
            'secondary_muscles' => $this->secondaryMuscles,
            'confidence' => $this->confidence,
        ];
    }
}
