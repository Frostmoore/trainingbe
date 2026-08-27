<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\PianoTrascritto;
use App\Services\Ai\Data\WorkoutAiContext;
use App\Services\Ai\Prompts;
use Closure;
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

    /**
     * Cosa succede **mentre** il modello sta «pensando» — FASE 2-septies.
     *
     * 🚨 Serve a provare una **corsa** senza avere due processi. La corsa vera
     * e' questa: mentre la nostra richiesta e' ferma dentro `dailyAdvice()`,
     * un'altra richiesta identica finisce e scrive la riga. ⚠️ Senza un gancio
     * qui, quel momento non e' riproducibile in un test — e un difetto che non
     * si riesce a riprodurre e' un difetto che torna.
     */
    private ?Closure $duranteIlConsiglio = null;

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

    /** @var list<array{id: int, andamento: string, riga: string}>|null */
    private ?array $nextProgresso = null;

    private ?string $nextRiassunto = null;

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

    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx, string $extra = ''): FoodEstimate
    {
        // ⚠️ `extra` si registra: e' l'unico modo per provare che il retry lo
        // manda davvero, e che finisce nel messaggio e non nel sistema.
        $this->record('foodFromImage', ['path' => $absolutePath, 'mime' => $mimeType, 'extra' => $extra], $ctx);

        /*
         * 🚨 **Il doppio deve produrre dati che il validatore accetta.**
         *
         * Qui c'era `unit => 'porzione'`, che e' una delle unita' **vietate**:
         * il doppio insegnava ai test una forma che in produzione sarebbe un
         * errore grave. Un doppio che mente e' peggio di nessun doppio, perche'
         * i test restano verdi mentre provano la cosa sbagliata.
         */
        return $this->nextFood ?? FoodEstimate::fromArray([
            'items' => [[
                'name' => 'Piatto riconosciuto', 'qty' => 350, 'unit' => 'g', 'grams' => 350,
                'basis' => 'per_100g', 'state' => 'cotto', 'declared' => false,
                'kcal' => 620, 'protein_g' => 35, 'carbs_g' => 55, 'fat_g' => 28,
                'alcohol_g' => 0, 'confidence' => 0.72,
            ]],
            'confidence' => 0.72,
        ]);
    }

    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int
    {
        $this->record('workoutCalories', $context->toArray(), $ctx);

        return $this->nextKcal ?? 420;
    }

    /**
     * Fa succedere qualcosa **durante** la generazione del consiglio.
     *
     * 💡 Si spara una volta sola: una corsa e' un evento, non uno stato. Un
     * gancio che resta armato farebbe scrivere la riga a ogni chiamata, e il
     * test proverebbe un'altra cosa senza dirlo.
     */
    public function duranteIlConsiglio(?Closure $f): self
    {
        $this->duranteIlConsiglio = $f;

        return $this;
    }

    public function dailyAdvice(array $context, AiCallContext $ctx): string
    {
        $this->record('dailyAdvice', $context, $ctx);

        if ($this->duranteIlConsiglio !== null) {
            $f = $this->duranteIlConsiglio;
            $this->duranteIlConsiglio = null;

            $f($context);
        }

        return $this->nextAdvice ?? 'Ti mancano circa 30 g di proteine per arrivare al target di oggi.';
    }

    /**
     * Le righe che il finto fornitore restituira' alla prossima analisi.
     *
     * @param  list<array{id: int, andamento: string, riga: string}>  $righe
     */
    public function willReturnProgresso(array $righe): self
    {
        $this->nextProgresso = $righe;

        return $this;
    }

    public function willReturnRiassunto(?string $riassunto): self
    {
        $this->nextRiassunto = $riassunto;

        return $this;
    }

    /**
     * 🚨 **Passa dal setaccio come i fornitori veri.** ⛔ Se il Fake tornasse le
     * righe cosi' come gliele hanno date, il test che prova «una riga che
     * prescrive non arriva al telefono» proverebbe soltanto che il Fake ubbidisce
     * — cioe' non proverebbe niente.
     */
    public function progressoScheda(array $context, AiCallContext $ctx): array
    {
        $this->record('progressoScheda', $context, $ctx);

        return [
            'riassunto' => Prompts::ripulisciRiassunto(
                $this->nextRiassunto ?? 'Cresci sulle spinte, fermo sulle trazioni da un mese.',
            ),
            'esercizi' => Prompts::ripulisciProgresso($this->nextProgresso ?? [
                ['id' => 1, 'andamento' => 'in_salita', 'riga' => 'Il carico piu\' alto da quando fai questo esercizio.'],
            ]),
        ];
    }

    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan
    {
        $this->record('parseWorkoutPdf', ['path' => $absolutePath, 'model' => $forceModel], $ctx);

        return $this->nextPlan ?? ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda importata',
            'notes' => null,
            'exercises' => [
                ['name' => 'Panca piana', 'sets' => 4, 'reps' => '8-12', 'rest_sec' => 90, 'muscle_group' => 'chest', 'secondary_muscles' => ['triceps', 'shoulders'], 'confidence' => 0.95],
                ['name' => 'Lat machine', 'sets' => 3, 'reps' => '10', 'rest_sec' => 60, 'muscle_group' => 'back', 'secondary_muscles' => ['biceps'], 'confidence' => 0.9],
            ],
        ]);
    }

    public function trascriviPianoAlimentare(
        string $absolutePath,
        AiCallContext $ctx,
        ?string $forceModel = null,
    ): PianoTrascritto {
        $this->record('trascriviPianoAlimentare', ['path' => $absolutePath, 'model' => $forceModel], $ctx);

        /*
         * 💡 La trascrizione finta porta **un dubbio**, di proposito.
         *
         * ⚠️ Un finto che risponde sempre perfetto fa scrivere test che non
         * attraversano mai il caso interessante — cioe' quello in cui il
         * modello dice «qui non ero sicuro», che e' l'unico motivo per cui la
         * revisione riga per riga esiste.
         */
        if ($this->prossimoErrore !== null) {
            throw $this->prossimoErrore;
        }

        return $this->prossimoPiano ?? PianoTrascritto::daArray([
            'nome' => 'Piano importato',
            'confidenza' => 0.9,
            'dubbi' => ['Il grammaggio del pranzo del giorno 2 e\' poco leggibile.'],
            'giorni' => [[
                'nome' => 'Giorno 1',
                'pasti' => [[
                    'tipo' => 'lunch',
                    'alimenti' => [
                        ['descrizione' => 'Petto di pollo', 'grammi' => 200],
                        ['descrizione' => 'Riso basmati', 'grammi' => 80],
                    ],
                ]],
            ]],
        ]);
    }

    /** Quello che il prossimo `trascriviPianoAlimentare` restituira'. */
    public ?PianoTrascritto $prossimoPiano = null;

    /**
     * Quello che il prossimo `trascriviPianoAlimentare` lancera' invece di
     * rispondere.
     *
     * 💡 Serve a provare che **un'importazione fallita non si paga**: il
     * caso non e' raggiungibile altrimenti, perche' il finto per definizione non
     * ha disservizi.
     */
    public ?Throwable $prossimoErrore = null;

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
