<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🍽️ **Il diario non sta sul server** — A1 di `plan_blocco_A.md`, 22/09/2026.
 *
 * Il diario alimentare e i preferiti vivono sul telefono dal 03/09/2026. Il
 * 22/09 si è scoperto che sul server erano rimaste le tabelle e le rotte, mentre
 * l'informativa diceva a tutti che *«le tabelle che un tempo li contenevano sono
 * state cancellate»*. 🚨 Questi test esistono perché quella frase resti vera.
 */
final class IlDiarioNonStaSulServerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ROTTE_TOLTE = [
        'api/v1/diary',
        'api/v1/targets',
        'api/v1/nutrition-plan',
        'api/v1/food-entries',
        'api/v1/food-entries/{entry}',
        'api/v1/food-entries/{entry}/favorite',
        'api/v1/food-favorites',
        'api/v1/food-favorites/meal',
        'api/v1/food-favorites/{favorite}/add',
        'api/v1/food-favorites/{favorite}',
        'api/v1/series',
        'api/v1/trasloco/diario',
    ];

    #[Test]
    public function le_tabelle_non_ci_sono(): void
    {
        $this->assertFalse(Schema::hasTable('food_entries'));
        $this->assertFalse(Schema::hasTable('food_favorites'));
    }

    #[Test]
    public function le_rotte_non_ci_sono(): void
    {
        $uri = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => $r->uri())->all();

        foreach (self::ROTTE_TOLTE as $rotta) {
            $this->assertNotContains($rotta, $uri, "La rotta {$rotta} è tornata.");
        }
    }

    /** ⚠️ I commenti non contano: raccontano perché il diario non c'è più. */
    #[Test]
    public function nessuno_li_nomina_fuori_dai_commenti(): void
    {
        $colpevoli = [];

        foreach ([app_path(), base_path('routes'), database_path('seeders'), database_path('factories')] as $cartella) {
            foreach (File::allFiles($cartella) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativo = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                // ⚠️ `/u`: senza, `\R` spezza la riga sul byte 0x85 che sta dentro ✅ (E2 9C 85).
                foreach (preg_split('/\R/u', $file->getContents()) as $n => $riga) {
                    $pulita = ltrim($riga);

                    // ⚠️ Anche `|`: è lo stile dei blocchi di commento grandi (`/* | … */`).
                    if (str_starts_with($pulita, '*') || str_starts_with($pulita, '/*') || str_starts_with($pulita, '//') || str_starts_with($pulita, '|')) {
                        continue;
                    }

                    if (preg_match('/food_entries|food_favorites|FoodEntry|FoodFavorite/', $riga) === 1) {
                        $colpevoli[] = $relativo.':'.($n + 1).'  '.trim($riga);
                    }
                }
            }
        }

        $this->assertSame([], $colpevoli, "Il diario è tornato sul server:\n".implode("\n", $colpevoli));
    }
}
