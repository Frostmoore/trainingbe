<?php

declare(strict_types=1);

namespace Tests\Unit\Nutrition;

use App\Services\Nutrition\FoodUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B5.3 — la conversione in grammi.
 *
 * Test unitario puro: nessun database, nessuna applicazione Laravel. E' logica
 * senza dipendenze e va provata come tale — se un giorno servisse un contenitore
 * per provarla, vorrebbe dire che ha smesso di essere pura.
 */
class FoodUnitTest extends TestCase
{
    #[Test]
    #[DataProvider('conversioni')]
    public function it_converts_to_grams(float $qty, string $unit, float $atteso): void
    {
        $this->assertSame($atteso, FoodUnit::toGrams($qty, $unit));
    }

    /** @return array<string, array{float, string, float}> */
    public static function conversioni(): array
    {
        return [
            'grammi restano grammi' => [150.0, 'g', 150.0],
            'chili' => [1.5, 'kg', 1500.0],
            'ettogrammi' => [2.0, 'hg', 200.0],
            'milligrammi' => [500.0, 'mg', 0.5],
            'millilitri 1:1' => [200.0, 'ml', 200.0],
            'litri' => [1.0, 'l', 1000.0],
            'decilitri' => [2.5, 'dl', 250.0],
            'centilitri' => [33.0, 'cl', 330.0],
            'un bicchiere' => [1.0, 'bicchiere', 200.0],
            'due cucchiai' => [2.0, 'cucchiaio', 30.0],
            'un cucchiaino' => [1.0, 'cucchiaino', 5.0],
            'una tazza' => [1.0, 'tazza', 240.0],
            'uno scoop' => [1.0, 'scoop', 30.0],
        ];
    }

    #[Test]
    #[DataProvider('sinonimi')]
    public function it_recognises_synonyms(string $scritto, string $atteso): void
    {
        $this->assertSame($atteso, FoodUnit::valid($scritto));
    }

    /** @return array<string, array{string, string}> */
    public static function sinonimi(): array
    {
        return [
            'maiuscole' => ['G', 'g'],
            'spazi' => ['  kg  ', 'kg'],
            'punto finale' => ['gr.', 'g'],
            'italiano esteso' => ['grammi', 'g'],
            'plurale' => ['cucchiai', 'cucchiaio'],
            'misurino' => ['misurino', 'scoop'],
            'inglese tbsp' => ['tbsp', 'cucchiaio'],
            'inglese cup' => ['cup', 'tazza'],
            'etti' => ['etti', 'hg'],
        ];
    }

    /**
     * 🚨 Un'unita' sconosciuta NON diventa grammi.
     *
     * Indovinare produrrebbe un numero plausibile e sbagliato, che entra nei
     * totali e non lo controlla piu' nessuno. Meglio `null`, che costringe chi
     * chiama a decidere.
     */
    #[Test]
    public function it_refuses_to_guess_an_unknown_unit(): void
    {
        $this->assertNull(FoodUnit::valid('manciata'));
        $this->assertNull(FoodUnit::toGrams(2.0, 'manciata'));
        $this->assertNull(FoodUnit::toGrams(2.0, null));
        $this->assertNull(FoodUnit::toGrams(null, 'g'));
    }

    #[Test]
    public function from_grams_is_the_inverse_of_to_grams(): void
    {
        foreach (['g', 'kg', 'ml', 'cucchiaio', 'tazza'] as $unita) {
            $grammi = FoodUnit::toGrams(3.0, $unita);

            $this->assertNotNull($grammi);
            $this->assertSame(3.0, FoodUnit::fromGrams($grammi, $unita), "Andata e ritorno rotto su «{$unita}».");
        }
    }

    #[Test]
    public function the_dropdown_starts_with_the_units_people_actually_use(): void
    {
        $this->assertSame(['g', 'kg', 'ml'], array_slice(FoodUnit::ORDER, 0, 3));

        // Ogni unita' dell'ordine deve esistere fra i fattori: un'incoerenza
        // qui darebbe un menu con una voce che poi non converte.
        foreach (FoodUnit::ORDER as $u) {
            $this->assertArrayHasKey($u, FoodUnit::FACTORS, "«{$u}» e' nell'ordine ma non ha un fattore.");
        }

        $this->assertCount(count(FoodUnit::FACTORS), FoodUnit::ORDER, 'Un\'unita\' convertibile non compare nel menu.');
    }
}
