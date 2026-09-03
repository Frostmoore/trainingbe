<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Enums\AiFeature;
use App\Models\ImportazioneDaDocumento;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\PianoTrascritto;
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

    // ───────────────────────── progressione ─────────────────────────

    public function progressoScheda(array $context, AiCallContext $ctx): array
    {
        $dati = $this->call(
            $ctx,
            $this->manager->modelFor(AiFeature::PlanProgress, 'anthropic'),
            Prompts::PROGRESSO_SYSTEM,
            [['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{}']],
            Prompts::progressoSchema(),
            maxTokens: 2048,
        );

        return [
            'riassunto' => Prompts::ripulisciRiassunto($dati['riassunto'] ?? null),
            'esercizi' => Prompts::ripulisciProgresso($dati['esercizi'] ?? []),
        ];
    }

    // ─────────────────────── documenti e fotografie ───────────────────────

    /**
     * I documenti da leggere, trasformati in blocchi per il modello — K1.
     *
     * ══ 🚨 UN POSTO SOLO PER DUE STRADE, E NON E' PIGRIZIA ════════════════
     *
     * Le schede e i piani alimentari arrivano dallo stesso posto — un foglio
     * scannerizzato o fotografato — e ⛔ due sanificatori per lo stesso genere
     * di dato divergono: quello che diverge per primo e' sempre la copia, cioe'
     * quella meno provata.
     *
     * ══ ⚠️ UN PDF E' UN `document`, UNA FOTO E' UN'`image` ════════════════
     *
     * Sono due blocchi diversi nel protocollo, e mandare l'uno per l'altro non
     * da' un errore comprensibile: da' un rifiuto del fornitore che, letto dal
     * nostro `catch`, diventa *«l'AI non e' disponibile»*.
     *
     * 🚨 **L'ordine si conserva**, e per le fotografie e' l'informazione
     * principale: la seconda pagina letta per prima da una scheda che comincia
     * da meta'.
     *
     * @param  list<string>  $percorsi
     * @return list<array<string, mixed>>
     */
    private function blocchiDelDocumento(array $percorsi): array
    {
        if ($percorsi === []) {
            throw new AiUnavailableException('ai_pdf_unreadable', 'Nessun documento da leggere.');
        }

        $blocchi = [];
        $totale = 0;

        foreach ($percorsi as $percorso) {
            if (! is_readable($percorso)) {
                throw new AiUnavailableException('ai_pdf_unreadable', 'Documento non leggibile.');
            }

            /*
             * 🚨 **Il tetto e' sulla SOMMA, non sul singolo file.** Cinque
             * immagini da sette mega ciascuna sono trentacinque mega dentro una
             * richiesta sola: controllarle una per una le lascerebbe passare
             * tutte.
             */
            $totale += (int) filesize($percorso);

            if ($totale > (int) config('ai.pdf.max_bytes')) {
                throw new AiUnavailableException('ai_pdf_too_large', 'I documenti superano il limite di dimensione.');
            }

            $mime = $this->tipoDi($percorso);
            $dati = base64_encode((string) file_get_contents($percorso));

            $blocchi[] = $mime === 'application/pdf'
                ? [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $dati],
                ]
                : [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $dati],
                ];
        }

        return $blocchi;
    }

    /**
     * Che cos'e' questo file, **guardandolo**.
     *
     * ⛔ Non dall'estensione, e non dal `mime` dichiarato al caricamento: quello
     * lo sceglie chi carica. 🚨 Qui si decide se mandare un `document` o
     * un'`image`, e sbagliare vuol dire una richiesta rifiutata dal fornitore
     * che arriva a chi guarda come *«l'AI non e' disponibile»*.
     */
    private function tipoDi(string $percorso): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($percorso);

        if (! in_array($mime, ImportazioneDaDocumento::MIME_AMMESSI, true)) {
            throw new AiUnavailableException(
                'ai_pdf_unsupported',
                'Questo tipo di documento non si puo\' leggere.',
            );
        }

        return $mime;
    }

    /** @param  list<string>  $percorsi */
    public function parseWorkoutPdf(array $percorsi, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan
    {
        $dati = $this->call(
            $ctx,
            $forceModel ?? $this->manager->modelFor(AiFeature::PdfImport, 'anthropic'),
            Prompts::PDF_SYSTEM,
            [[
                'role' => 'user',
                'content' => [
                    ...$this->blocchiDelDocumento($percorsi),
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

    /** @param  list<string>  $percorsi */
    public function trascriviPianoAlimentare(
        array $percorsi,
        AiCallContext $ctx,
        ?string $forceModel = null,
    ): PianoTrascritto {
        $dati = $this->call(
            $ctx,
            $forceModel ?? $this->manager->modelFor(AiFeature::NutritionPdfImport, 'anthropic'),
            Prompts::PIANO_ALIMENTARE_SYSTEM,
            [[
                'role' => 'user',
                'content' => [
                    ...$this->blocchiDelDocumento($percorsi),
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

            /*
             * ══ 🚨 E ANCHE LA RISPOSTA TRONCATA ARRIVA CON HTTP 200 — K7 ═══
             *
             * ⛔ Fino al 03/09/2026 questo caso non era guardato. Un piano
             * alimentare lungo esauriva `max_tokens` e la risposta arrivava
             * **tagliata a meta'**: un JSON incompleto, che `json_decode`
             * rifiuta, e chi guardava leggeva *«la risposta del modello non e'
             * nel formato atteso»*.
             *
             * 🚨 **Con cinquanta gettoni gia' scalati e nessun modo di capire
             * perche'.** E la diagnosi conta: «troncata» si risolve alzando il
             * tetto o dividendo il documento, «formato atteso» non si risolve
             * affatto, perche' non dice niente.
             *
             * ⚠️ **E' stato trovato solo con un documento vero** (K7, un piano
             * settimanale su cinque pagine): la suite passa da `FakeAiProvider`,
             * che un tetto di token non ce l'ha.
             */
            if ($message->stopReason === 'max_tokens') {
                throw new AiUnavailableException(
                    'ai_response_truncated',
                    'Il documento e\' troppo lungo: la risposta si e\' interrotta a meta\'. '
                    .'Prova a caricarne una parte per volta.',
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
