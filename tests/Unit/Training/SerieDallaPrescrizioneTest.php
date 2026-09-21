<?php

declare(strict_types=1);

namespace Tests\Unit\Training;

use App\Services\Ai\Data\ParsedWorkoutExercise;
use App\Support\Training\SerieDallaPrescrizione;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 📋 Da una prescrizione scritta alle righe di serie — 23/09/2026.
 *
 * 📌 *«Se una scheda dice hack squat 5x8 non deve diventare "serie 5"
 * "ripetizioni 8" ma deve aggiungere 5 righe di serie con 8 ripetizioni»*.
 *
 * 💡 I casi vengono dalle nove schede di prova (`memory/schede-di-prova/`):
 * sono le forme che un foglio vero usa davvero.
 */
final class SerieDallaPrescrizioneTest extends TestCase
{
    /** @return array<string, array{0: ?int, 1: ?string, 2: list<?int>, 3: ?int}> */
    public static function casi(): array
    {
        return [
            '5x8' => [5, '8', [8, 8, 8, 8, 8], null],
            'piramide su 5 serie' => [5, '12-10-8-6-4', [12, 10, 8, 6, 4], null],
            'piramide senza serie' => [null, '12-10-8', [12, 10, 8], null],
            'piramide coi punti' => [3, '10.8.6', [10, 8, 6], null],
            'piramide con una serie ripetuta' => [4, '10-8-6-6', [10, 8, 6, 6], null],
            'due serie decrescenti' => [2, '10-8', [10, 8], null],
            'intervallo: il più basso' => [4, '8-12', [8, 8, 8, 8], null],
            'per lato' => [3, '10+10', [10, 10, 10], null],
            'per gamba' => [4, '10 per gamba', [10, 10, 10, 10], null],
            'il tempo fra parentesi non conta' => [3, '10-12 (2.0.2)', [10, 10, 10], null],
            'a cedimento' => [3, 'cedimento', [null, null, null], null],
            'max' => [3, 'max', [null, null, null], null],
            'secondi' => [3, '45 secondi', [null, null, null], 45],
            'virgolette' => [3, '30"', [null, null, null], 30],
            'minuti' => [null, '10 min', [null], 600],
            'primi e secondi' => [2, "1'30", [null, null], 90],
            'niente di niente' => [null, null, [null], null],
        ];
    }

    #[Test]
    #[DataProvider('casi')]
    public function una_prescrizione_diventa_righe(?int $serie, ?string $scritto, array $attese, ?int $secondi): void
    {
        $righe = SerieDallaPrescrizione::espandi($serie, $scritto, 90, 40.0);

        $this->assertSame($attese, array_column($righe, 'reps'));
        $this->assertSame(array_fill(0, count($attese), $secondi), array_column($righe, 'iso_sec'));

        // Recupero e peso scendono su ogni riga.
        $this->assertSame(array_fill(0, count($attese), 90), array_column($righe, 'rest_sec'));
        $this->assertSame(array_fill(0, count($attese), 40.0), array_column($righe, 'weight'));
    }

    /** ⛔ Mai più di 30 serie: oltre è un errore di lettura, non una scheda. */
    #[Test]
    public function mai_piu_di_trenta_righe(): void
    {
        $this->assertCount(SerieDallaPrescrizione::MASSIMO, SerieDallaPrescrizione::espandi(500, '10'));
    }

    /**
     * 🚨 **La riga letta dal modello ha le serie**: dalla risposta nuova le
     * tiene, dal formato vecchio le espande — e non esce mai un «6-10».
     */
    #[Test]
    public function la_riga_letta_ha_sempre_le_serie(): void
    {
        $nuova = ParsedWorkoutExercise::fromArray([
            'name' => 'Hack squat',
            'serie' => [
                ['reps' => 12, 'weight' => 60, 'iso_sec' => null, 'rest_sec' => 90],
                ['reps' => 10, 'weight' => 70, 'iso_sec' => null, 'rest_sec' => 90],
            ],
            'carico' => 'peso',
        ]);

        $this->assertSame([12, 10], array_column($nuova->serie, 'reps'));
        $this->assertSame([60.0, 70.0], array_column($nuova->serie, 'weight'));

        $vecchia = ParsedWorkoutExercise::fromArray(['name' => 'Hack squat', 'sets' => 5, 'reps' => '6-10']);
        $this->assertSame([6, 6, 6, 6, 6], array_column($vecchia->serie, 'reps'));

        // Il riassunto per chi legge il formato vecchio: ricalcolato dalle righe.
        $this->assertSame(5, $vecchia->toArray()['sets']);
        $this->assertSame('6', $vecchia->toArray()['reps']);
    }

    /** ⏱️ Un esercizio a tempo diventa isometria da solo, se il modello non lo dice. */
    #[Test]
    public function il_tempo_diventa_isometria(): void
    {
        $plank = ParsedWorkoutExercise::fromArray(['name' => 'Plank', 'sets' => 3, 'reps' => '40 sec']);

        $this->assertSame('iso', $plank->carico);
        $this->assertSame([40, 40, 40], array_column($plank->serie, 'iso_sec'));
    }
}
