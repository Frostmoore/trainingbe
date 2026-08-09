<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Quello che il modello ha bisogno di sapere per stimare le calorie di un
 * allenamento.
 *
 * 🚨 **Non contiene ne' il nome della persona ne' quello della palestra.** Non
 * per pudore: il prompt di sistema del cibo e' cachato, e qualunque dato
 * variabile infilato nel prefisso stabile invaliderebbe la cache a ogni
 * richiesta, azzerando il risparmio senza dare nessun errore. La stessa
 * disciplina si applica qui per coerenza, e perche' un peso e una durata bastano
 * a rispondere.
 */
final readonly class WorkoutAiContext
{
    /**
     * @param  list<array{name: string, sets: int, reps: ?int, weight: ?float}>  $exercises
     */
    public function __construct(
        public int $durationMinutes,
        public float $bodyweightKg,
        public array $exercises,
        public ?string $planName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'duration_minutes' => $this->durationMinutes,
            'bodyweight_kg' => $this->bodyweightKg,
            'plan_name' => $this->planName,
            'exercises' => $this->exercises,
        ];
    }
}
