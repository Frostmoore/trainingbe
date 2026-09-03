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
    /**
     * @param  list<ParsedWorkoutExercise>  $exercises
     * @param  list<string>  $dayNames
     */
    public function __construct(
        public string $name,
        public ?string $notes,
        public array $exercises,
        public float $confidence,

        /**
         * 🆕 I titoli dei giorni, **come sono scritti sul foglio** — K2.
         *
         * 💡 «Push», «Lunedi'», «Giorno A». ⚠️ Servono a chi rivede per
         * ritrovarsi sul documento, e diventano il nome delle schede quando una
         * multiday si divide (K2-bis).
         *
         * ⛔ Puo' essere piu' corta di [quantiGiorni]: un modello che numera i
         * giorni e non li intitola e' un caso normale, non un errore.
         */
        public array $dayNames = [],
    ) {}

    /**
     * Quanti giorni ha questa scheda.
     *
     * 🚨 **Si contano dagli esercizi, e non si chiedono al modello.** Due sedi
     * dello stesso numero divergono, e qui divergerebbero **in silenzio**: una
     * scheda che dichiara tre giorni e ha esercizi solo sul primo sembrerebbe
     * completa a chiunque legga il numero invece delle righe.
     *
     * 💡 `max` e non `count(unique)`: se il modello salta il giorno 2, i giorni
     * restano tre — il secondo e' vuoto, e chi rivede lo vede vuoto invece di
     * vederselo sparire.
     */
    public function quantiGiorni(): int
    {
        if ($this->exercises === []) {
            return 1;
        }

        return max(array_map(
            static fn (ParsedWorkoutExercise $e): int => $e->day,
            $this->exercises,
        ));
    }

    /** 💡 La domanda che serve a K2-bis, detta in una parola. */
    public function eMultiday(): bool
    {
        return $this->quantiGiorni() > 1;
    }

    /**
     * Gli esercizi di un giorno, nell'ordine del documento.
     *
     * @return list<ParsedWorkoutExercise>
     */
    public function esercizoDelGiorno(int $giorno): array
    {
        return array_values(array_filter(
            $this->exercises,
            static fn (ParsedWorkoutExercise $e): bool => $e->day === $giorno,
        ));
    }

    /**
     * Come si chiama il giorno `n`, contando da 1.
     *
     * 💡 Il titolo che c'era sul foglio se c'e', altrimenti «Giorno n». ⛔ Non
     * si inventa: «Giorno 2» dice quello che sappiamo, «Pull» direbbe qualcosa
     * che nessuno ha scritto.
     */
    public function nomeDelGiorno(int $giorno): string
    {
        $titolo = trim($this->dayNames[$giorno - 1] ?? '');

        return $titolo !== '' ? $titolo : 'Giorno '.$giorno;
    }

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
            dayNames: array_values(array_map(
                static fn (mixed $v): string => trim((string) $v),
                (array) ($data['day_names'] ?? []),
            )),
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
            'day_names' => $this->dayNames,
            'giorni' => $this->quantiGiorni(),
            'exercises' => array_map(
                static fn (ParsedWorkoutExercise $e): array => $e->toArray(),
                $this->exercises,
            ),
        ];
    }
}
