<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiFeature;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\WorkoutAiContext;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\ImagePreparer;
use App\Services\Ai\Prompts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Il driver alternativo — B6.3.
 *
 * 🚨 **Parla HTTP direttamente invece di usare un SDK, ed e' una scelta.**
 * L'obiettivo di questo driver e' poter puntare il sistema a un endpoint
 * **compatibile con OpenAI** — un modello gia' in uso altrove, un proxy, un
 * servizio self-hosted — con una riga di `.env` (`OPENAI_BASE_URL`). Un SDK
 * aggiunge una dipendenza, impone la sua idea di autenticazione e spesso si
 * rifiuta di parlare con base URL non ufficiali: esattamente cio' che qui serve
 * fare.
 *
 * Il costo di questa scelta e' che le eccezioni vanno tradotte a mano leggendo i
 * codici HTTP. E' poco codice e sta tutto in `rawCall()`.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AiUsageRecorder $recorder,
        private readonly ImagePreparer $images,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function foodFromText(string $text, AiCallContext $ctx): FoodEstimate
    {
        return FoodEstimate::fromArray($this->json(
            $ctx,
            $this->manager->modelFor(AiFeature::FoodText, 'openai'),
            Prompts::FOOD_SYSTEM,
            [['type' => 'text', 'text' => $text]],
            Prompts::foodSchema(),
            'food_estimate',
            2048,
        ));
    }

    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx, string $extra = ''): FoodEstimate
    {
        $img = $this->images->prepare($absolutePath, $mimeType);

        return FoodEstimate::fromArray($this->json(
            $ctx,
            $this->manager->modelFor(AiFeature::FoodPhoto, 'openai'),
            Prompts::FOOD_SYSTEM,
            [
                ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:'.$img['mime'].';base64,'.$img['data'],
                ]],
                ['type' => 'text', 'text' => Prompts::DOMANDA_FOTO.$extra],
            ],
            Prompts::foodSchema(),
            'food_estimate',
            2048,
        ));
    }

    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int
    {
        $dati = $this->json(
            $ctx,
            $this->manager->modelFor(AiFeature::WorkoutKcal, 'openai'),
            Prompts::WORKOUT_KCAL_SYSTEM,
            [['type' => 'text', 'text' => json_encode($context->toArray(), JSON_UNESCAPED_UNICODE) ?: '{}']],
            Prompts::workoutKcalSchema(),
            'workout_kcal',
            256,
        );

        return max(0, (int) ($dati['kcal'] ?? 0));
    }

    public function dailyAdvice(array $context, AiCallContext $ctx): string
    {
        return trim($this->rawCall(
            $ctx,
            $this->manager->modelFor(AiFeature::DailyAdvice, 'openai'),
            Prompts::ADVICE_SYSTEM,
            [['type' => 'text', 'text' => json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{}']],
            null,
            null,
            512,
        ));
    }

    /**
     * 🚨 Questo driver **non** legge PDF nativamente.
     *
     * Fingere di poterlo fare — per esempio estraendo il testo con un parser e
     * mandandolo come stringa — produrrebbe schede senza struttura tabellare,
     * cioe' esattamente il caso in cui l'import PDF serve. Meglio un errore
     * chiaro: chi ha bisogno dell'import usa il driver Anthropic, che e' il
     * default.
     */
    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan
    {
        throw new AiUnavailableException(
            'ai_pdf_unsupported',
            'L\'import da PDF richiede il driver Anthropic.',
        );
    }

    // ───────────────────────── il motore ─────────────────────────

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function json(
        AiCallContext $ctx,
        string $model,
        string $system,
        array $content,
        array $schema,
        string $schemaName,
        int $maxTokens,
    ): array {
        $testo = $this->rawCall($ctx, $model, $system, $content, $schema, $schemaName, $maxTokens);

        $decoded = json_decode($testo, true);

        if (! is_array($decoded)) {
            throw new AiUnavailableException(
                'ai_invalid_response',
                'La risposta del modello non e\' nel formato atteso.',
            );
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $schema
     */
    private function rawCall(
        AiCallContext $ctx,
        string $model,
        string $system,
        array $content,
        ?array $schema,
        ?string $schemaName,
        int $maxTokens,
    ): string {
        $chiave = config('ai.providers.openai.key');

        if (! is_string($chiave) || $chiave === '') {
            throw new AiUnavailableException('ai_not_configured', 'La chiave del fornitore AI non e\' configurata.');
        }

        $payload = [
            'model' => $model,
            'max_completion_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName ?? 'risposta',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ];
        }

        $inizio = microtime(true);

        try {
            $risposta = Http::withToken($chiave)
                ->timeout(120)
                ->acceptJson()
                ->post(rtrim((string) config('ai.providers.openai.base_url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            $this->recordFailure($ctx, $model, $inizio, 'connection');

            throw new AiUnavailableException(previous: $e);
        } catch (Throwable $e) {
            $this->recordFailure($ctx, $model, $inizio, 'unexpected');

            throw new AiUnavailableException(previous: $e);
        }

        $durata = (int) round((microtime(true) - $inizio) * 1000);

        if ($risposta->status() === 429) {
            $this->recordFailure($ctx, $model, $inizio, 'rate_limited');

            throw new AiRateLimitedException(
                retryAfterSeconds: (int) ($risposta->header('Retry-After') ?: 30),
            );
        }

        if ($risposta->failed()) {
            $this->recordFailure($ctx, $model, $inizio, 'http_'.$risposta->status());

            throw new AiUnavailableException;
        }

        $dati = $risposta->json();

        $this->recorder->record(
            $ctx,
            $this->name(),
            $model,
            (int) data_get($dati, 'usage.prompt_tokens', 0),
            (int) data_get($dati, 'usage.completion_tokens', 0),
            (int) data_get($dati, 'usage.prompt_tokens_details.cached_tokens', 0),
            $durata,
            success: true,
        );

        $motivo = data_get($dati, 'choices.0.finish_reason');

        if ($motivo === 'content_filter') {
            throw new AiUnavailableException('ai_refused', 'Il modello ha rifiutato di rispondere a questa richiesta.');
        }

        $testo = data_get($dati, 'choices.0.message.content');

        if (! is_string($testo) || $testo === '') {
            throw new AiUnavailableException('ai_empty_response', 'Il modello non ha restituito testo.');
        }

        return $testo;
    }

    private function recordFailure(AiCallContext $ctx, string $model, float $inizio, string $codice): void
    {
        $this->recorder->record(
            $ctx,
            $this->name(),
            $model,
            0,
            0,
            0,
            (int) round((microtime(true) - $inizio) * 1000),
            success: false,
            errorCode: $codice,
        );
    }
}
