<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Una scheda letta da un PDF, ancora da rivedere.
 *
 * 🚨 **Non e' una `WorkoutPlan`**, ed e' importante che i due tipi restino
 * diversi: finche' il risultato dell'AI e' un DTO, non c'e' nessun percorso
 * accidentale che lo faccia finire in mano a un iscritto. Diventa una scheda
 * vera solo quando una persona lo pubblica dalla pagina di revisione (B7.4).
 */
final readonly class ParsedWorkoutPlan
{
    /** @param list<ParsedWorkoutExercise> $exercises */
    public function __construct(
        public string $name,
        public ?string $notes,
        public array $exercises,
        public float $confidence,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $esercizi = array_map(
            static fn (array $e): ParsedWorkoutExercise => ParsedWorkoutExercise::fromArray($e),
            array_values($data['exercises'] ?? []),
        );

        return new self(
            name: (string) ($data['name'] ?? 'Scheda importata'),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            exercises: $esercizi,
            confidence: isset($data['confidence'])
                ? (float) $data['confidence']
                : self::mediaDelleRighe($esercizi),
        );
    }

    /**
     * La confidenza complessiva quando il modello non la dichiara.
     *
     * Media delle righe e non minimo: un solo esercizio illeggibile in una
     * scheda da venti non deve far scattare l'escalation su un modello piu' caro
     * (B7.5) — quella riga la corregge la persona in dieci secondi.
     *
     * @param  list<ParsedWorkoutExercise>  $esercizi
     */
    private static function mediaDelleRighe(array $esercizi): float
    {
        if ($esercizi === []) {
            return 0.0;
        }

        $somma = array_sum(array_map(
            static fn (ParsedWorkoutExercise $e): float => $e->confidence,
            $esercizi,
        ));

        return round($somma / count($esercizi), 3);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'notes' => $this->notes,
            'confidence' => $this->confidence,
            'exercises' => array_map(
                static fn (ParsedWorkoutExercise $e): array => $e->toArray(),
                $this->exercises,
            ),
        ];
    }
}
