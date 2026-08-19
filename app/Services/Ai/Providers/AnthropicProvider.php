<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Enums\AiFeature;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\PianoTrascritto;
use App\Services\Ai\Data\WorkoutAiContext;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\ImagePreparer;
use App\Services\Ai\Prompts;
use Throwable;

/**
 * Il fornitore di riferimento — B6.2.
 *
 * ⚠️ **L'SDK PHP usa named argument in camelCase** (`maxTokens`, non
 * `max_tokens`), ma le chiavi **annidate** dentro gli array mantengono la forma
 * documentata caso per caso. Convertire in blocco e' l'errore che sembra
 * funzionare finche' il fornitore non rifiuta la richiesta con un messaggio che
 * non dice quale campo.
 *
 * 🚨 **Tre cose che questa classe non deve mai smettere di fare:**
 *  1. il `cacheControl` sul prompt di sistema — e' il risparmio piu' grande
 *     della piattaforma (§9.3 del piano);
 *  2. controllare `stopReason === 'refusal'` **prima** di leggere `content`: il
 *     rifiuto arriva con HTTP 200, e leggere `content[0]` senza guardare fa
 *     esplodere il codice su una risposta valida;
 *  3. registrare il consumo anche quando la chiamata fallisce.
 */
class AnthropicProvider implements AiProvider
{
    private ?Client $client = null;

    public function __construct(
        private readonly AiManager $manager,
        private readonly AiUsageRecorder $recorder,
        private readonly ImagePreparer $images,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    // ───────────────────────── cibo ─────────────────────────

    public function foodFromText(string $text, AiCallContext $ctx): FoodEstimate
    {
        $dati = $this->call(
            $ctx,
            $this->manager->modelFor(AiFeature::FoodText, 'anthropic'),
            Prompts::FOOD_SYSTEM,
            [['role' => 'user', 'content' => $text]],
            Prompts::foodSchema(),
            maxTokens: 2048,
        );

        return FoodEstimate::fromArray($dati);
    }

    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx, string $extra = ''): FoodEstimate
    {
        $img = $this->images->prepare($absolutePath, $mimeType);

        $dati = $this->call(
            $ctx,
            $this->manager->modelFor(AiFeature::FoodPhoto, 'anthropic'),
            Prompts::FOOD_SYSTEM,
            [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $img['mime'],
                            'data' => $img['data'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => Prompts::DOMANDA_FOTO.$extra,
                    ],
                ],
            ]],
            Prompts::foodSchema(),
            maxTokens: 2048,
        );

        return FoodEstimate::fromArray($dati);
    }

    // ───────────────────────── allenamento ─────────────────────────

    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int
    {
        $dati = $this->call(
            $ctx,
            $this->manager->modelFor(AiFeature::WorkoutKcal, 'anthropic'),
            Prompts::WORKOUT_KCAL_SYSTEM,
            [['role' => 'user', 'content' => json_encode($context->toArray(), JSON_UNESCAPED_UNICODE) ?: '{}']],
            Prompts::workoutKcalSchema(),
            maxTokens: 256,
        );

        return max(0, (int) ($dati['kcal'] ?? 0));
    }

    // ───────────────────────── consiglio ─────────────────────────

    public function dailyAdvice(array $context, AiCallContext $ctx): string
    {
        $messaggio = $this->rawCall(
            $ctx,
            $this->manager->modelFor(AiFeature::DailyAdvice, 'anthropic'),
            Prompts::ADVICE_SYSTEM,
            [['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{}']],
            schema: null,
            maxTokens: 512,
        );

        return trim($messaggio);
    }

    // ───────────────────────── PDF ─────────────────────────

    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan
    {
        if (! is_readable($absolutePath)) {
            throw new AiUnavailableException('ai_pdf_unreadable', 'PDF non leggibile.');
        }

        $bytes = (int) filesize($absolutePath);

        if ($bytes > (int) config('ai.pdf.max_bytes')) {
            throw new AiUnavailableException('ai_pdf_too_large', 'Il PDF supera il limite di dimensione.');
        }

        $dati = $this->call(
            $ctx,
            $forceModel ?? $this->manager->modelFor(AiFeature::PdfImport, 'anthropic'),
            Prompts::PDF_SYSTEM,
            [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode((string) file_get_contents($absolutePath)),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Estrai la scheda di allenamento contenuta in questo documento.',
                    ],
                ],
            ]],
            Prompts::workoutPlanSchema(),
            maxTokens: 8192,
        );

        return ParsedWorkoutPlan::fromArray($dati);
    }

    public function trascriviPianoAlimentare(
        string $absolutePath,
        AiCallContext $ctx,
        ?string $forceModel = null,
    ): PianoTrascritto {
        if (! is_readable($absolutePath)) {
            throw new AiUnavailableException('ai_pdf_unreadable', 'PDF non leggibile.');
        }

        if ((int) filesize($absolutePath) > (int) config('ai.pdf.max_bytes')) {
            throw new AiUnavailableException('ai_pdf_too_large', 'Il PDF supera il limite di dimensione.');
        }

        $dati = $this->call(
            $ctx,
            $forceModel ?? $this->manager->modelFor(AiFeature::NutritionPdfImport, 'anthropic'),
            Prompts::PIANO_ALIMENTARE_SYSTEM,
            [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode((string) file_get_contents($absolutePath)),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Ricopia il piano alimentare contenuto in questo documento. Non correggere niente.',
                    ],
                ],
            ]],
            Prompts::pianoAlimentareSchema(),
            /*
             * 💡 16k e non 8k come le schede: un piano alimentare settimanale
             * con alternative e' parecchio piu' lungo di una scheda, e una
             * risposta troncata a meta' non da' errore — da' un piano con tre
             * giorni su sette, che sembra completo.
             */
            maxTokens: 16384,
        );

        return PianoTrascritto::daArray($dati);
    }

    // ───────────────────────── il motore ─────────────────────────

    /**
     * Una chiamata con uscita strutturata, decodificata.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function call(
        AiCallContext $ctx,
        string $model,
        string $system,
        array $messages,
        array $schema,
        int $maxTokens,
    ): array {
        $testo = $this->rawCall($ctx, $model, $system, $messages, $schema, $maxTokens);

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
     * La chiamata vera, con misurazione e traduzione degli errori.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $schema
     */
    private function rawCall(
        AiCallContext $ctx,
        string $model,
        string $system,
        array $messages,
        ?array $schema,
        int $maxTokens,
    ): string {
        $inizio = microtime(true);

        try {
            $parametri = [
                'model' => $model,
                'maxTokens' => $maxTokens,
                'messages' => $messages,
                // 🚨 Il prompt di sistema come blocco con cacheControl: e' cio'
                // che rende il prefisso cachabile. Passarlo come stringa
                // semplice funzionerebbe, e costerebbe dieci volte tanto.
                'system' => [[
                    'type' => 'text',
                    'text' => $system,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
            ];

            if ($schema !== null) {
                $parametri['outputConfig'] = [
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ];
            }

            $message = $this->client()->messages->create(...$parametri);

            $durata = (int) round((microtime(true) - $inizio) * 1000);

            $this->recorder->record(
                $ctx,
                $this->name(),
                $model,
                $message->usage->inputTokens,
                $message->usage->outputTokens,
                $message->usage->cacheReadInputTokens ?? 0,
                $durata,
                success: true,
                // 🚨 La voce più cara delle tre: 1,25x l'input. Fino al
                // 13/08/2026 non la guardava nessuno, e un prompt da cinquemila
                // token risultava costato dodici.
                cacheCreationTokens: $message->usage->cacheCreationInputTokens ?? 0,
            );

            // 🚨 Il rifiuto arriva con HTTP 200: va guardato PRIMA di leggere
            // il contenuto, che in quel caso non ha la forma attesa.
            if ($message->stopReason === 'refusal') {
                throw new AiUnavailableException(
                    'ai_refused',
                    'Il modello ha rifiutato di rispondere a questa richiesta.',
                );
            }

            return $this->extractText($message->content);
        } catch (RateLimitException $e) {
            $this->recordFailure($ctx, $model, $inizio, 'rate_limited');

            throw new AiRateLimitedException(previous: $e);
        } catch (APIConnectionException|APIStatusException $e) {
            $this->recordFailure($ctx, $model, $inizio, 'api_error');

            throw new AiUnavailableException(previous: $e);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->recordFailure($ctx, $model, $inizio, 'unexpected');

            throw new AiUnavailableException(previous: $e);
        }
    }

    private function recordFailure(AiCallContext $ctx, string $model, float $inizio, string $codice): void
    {
        // I token consumati non li conosciamo — la risposta non e' arrivata — ma
        // la riga si scrive comunque: e' cio' che permette di vedere che un
        // modello sta fallendo, invece di vedere solo che nessuno lo usa.
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

    /** @param array<int, mixed> $content */
    private function extractText(array $content): string
    {
        foreach ($content as $blocco) {
            $tipo = is_object($blocco) ? ($blocco->type ?? null) : ($blocco['type'] ?? null);

            if ($tipo !== 'text') {
                continue;
            }

            return (string) (is_object($blocco) ? ($blocco->text ?? '') : ($blocco['text'] ?? ''));
        }

        throw new AiUnavailableException('ai_empty_response', 'Il modello non ha restituito testo.');
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $chiave = config('ai.providers.anthropic.key');

        if (! is_string($chiave) || $chiave === '') {
            throw new AiUnavailableException(
                'ai_not_configured',
                'La chiave del fornitore AI non e\' configurata.',
            );
        }

        return $this->client = new Client(apiKey: $chiave);
    }
}
