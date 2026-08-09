<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\WorkoutAiContext;
use Throwable;

/**
 * Il fornitore finto usato dai test.
 *
 * 🚨 **Regola non negoziabile: nessun test tocca la rete.** Un test che chiama
 * un modello vero e' lento, costa, e fallisce quando il fornitore ha un
 * disservizio — cioe' proprio quando serve sapere se il *nostro* codice
 * funziona. Con questo doppio si prova tutto cio' che e' nostro: il conteggio
 * dei token, le quote, la traduzione degli errori, la scrittura delle voci di
 * diario.
 *
 * Sta in `app/` e non in `tests/` di proposito: serve anche in sviluppo, quando
 * non si ha una chiave, e serve come implementazione di riferimento del
 * contratto. E' registrabile con `AI_DRIVER=fake`.
 *
 * **Registra comunque il consumo** con numeri finti: cosi' i test possono
 * verificare che il metering funzioni davvero, invece di verificare che il
 * finto non lo faccia.
 */
class FakeAiProvider implements AiProvider
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    private ?Throwable $throw = null;

    private ?FoodEstimate $nextFood = null;

    private ?ParsedWorkoutPlan $nextPlan = null;

    private ?int $nextKcal = null;

    private ?string $nextAdvice = null;

    public int $fakeInputTokens = 500;

    public int $fakeOutputTokens = 120;

    public function __construct(
        private readonly AiUsageRecorder $recorder,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    // ───────────────────────── pilotaggio dai test ─────────────────────────

    public function willThrow(?Throwable $e): self
    {
        $this->throw = $e;

        return $this;
    }

    public function willReturnFood(FoodEstimate $estimate): self
    {
        $this->nextFood = $estimate;

        return $this;
    }

    public function willReturnPlan(ParsedWorkoutPlan $plan): self
    {
        $this->nextPlan = $plan;

        return $this;
    }

    public function willReturnKcal(int $kcal): self
    {
        $this->nextKcal = $kcal;

        return $this;
    }

    public function willReturnAdvice(string $advice): self
    {
        $this->nextAdvice = $advice;

        return $this;
    }

    // ───────────────────────── il contratto ─────────────────────────

    public function foodFromText(string $text, AiCallContext $ctx): FoodEstimate
    {
        $this->record('foodFromText', ['text' => $text], $ctx);

        return $this->nextFood ?? FoodEstimate::fromArray([
            'items' => [[
                'name' => $text !== '' ? $text : 'Alimento',
                'qty' => 100, 'unit' => 'g', 'grams' => 100,
                'kcal' => 250, 'protein' => 10, 'carbs' => 30, 'fat' => 8,
            ]],
            'confidence' => 0.8,
        ]);
    }

    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx): FoodEstimate
    {
        $this->record('foodFromImage', ['path' => $absolutePath, 'mime' => $mimeType], $ctx);

        return $this->nextFood ?? FoodEstimate::fromArray([
            'items' => [[
                'name' => 'Piatto riconosciuto', 'qty' => 1, 'unit' => 'porzione', 'grams' => 350,
                'kcal' => 620, 'protein' => 35, 'carbs' => 55, 'fat' => 28,
            ]],
            'confidence' => 0.72,
        ]);
    }

    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int
    {
        $this->record('workoutCalories', $context->toArray(), $ctx);

        return $this->nextKcal ?? 420;
    }

    public function dailyAdvice(array $context, AiCallContext $ctx): string
    {
        $this->record('dailyAdvice', $context, $ctx);

        return $this->nextAdvice ?? 'Ti mancano circa 30 g di proteine per arrivare al target di oggi.';
    }

    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan
    {
        $this->record('parseWorkoutPdf', ['path' => $absolutePath, 'model' => $forceModel], $ctx);

        return $this->nextPlan ?? ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda importata',
            'notes' => null,
            'exercises' => [
                ['name' => 'Panca piana', 'sets' => 4, 'reps' => '8-12', 'rest_sec' => 90, 'confidence' => 0.95],
                ['name' => 'Lat machine', 'sets' => 3, 'reps' => '10', 'rest_sec' => 60, 'confidence' => 0.9],
            ],
        ]);
    }

    // ───────────────────────── interni ─────────────────────────

    /** @param array<string, mixed> $args */
    private function record(string $method, array $args, AiCallContext $ctx): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        $this->recorder->record(
            $ctx,
            $this->name(),
            'fake-model',
            $this->fakeInputTokens,
            $this->fakeOutputTokens,
            0,
            5,
            success: $this->throw === null,
            errorCode: $this->throw !== null ? 'fake_error' : null,
        );

        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}
