<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FoodEntry;
use App\Services\Nutrition\Catalogo\CatalogoAlimenti;
use Illuminate\Support\Facades\Log;

/**
 * Ogni voce di diario alimenta il catalogo — 17/08/2026.
 *
 * ── 🚨 Perche' un osservatore e non quattro chiamate ───────────────────────
 *
 * Perche' le voci di diario si scrivono in **quattro punti diversi**:
 * `AiController` (dopo una stima), `DiaryController` (a mano), `FoodFavorite`
 * (dai preferiti) e `NutritionPlanItem` (da un piano). ⚠️ Agganciare ognuno
 * vorrebbe dire dimenticarne uno al primo punto di scrittura nuovo — e non
 * fallirebbe niente: semplicemente quegli alimenti non entrerebbero nel
 * catalogo, e non se ne accorgerebbe nessuno.
 *
 * ── 🚨 E perche' NIENTE qui puo' rompere il diario ─────────────────────────
 *
 * Il catalogo e' una funzione **accessoria**. Perdere quello che una persona
 * ha appena registrato perche' e' fallito un calcolo di contorno sarebbe il
 * difetto peggiore possibile in questa parte del prodotto.
 *
 * Per questo:
 * - `$afterCommit` — si lavora quando la voce di diario e' **gia' salvata**;
 * - tutto in `try/catch`, con il guasto scritto nel registro e non davanti
 *   alla persona;
 * - `saveQuietly()` per non richiamare questo stesso osservatore.
 */
class FoodEntryObserver
{
    /**
     * 🚨 Dopo il commit, sempre.
     *
     * ⚠️ Senza, dentro una transazione l'osservatore girerebbe **prima** che la
     * voce esista davvero: il conteggio delle conferme leggerebbe una riga che
     * potrebbe non essere mai scritta, e un rollback lascerebbe il catalogo con
     * un uso in piu' per un pasto che non e' successo.
     */
    public bool $afterCommit = true;

    public function created(FoodEntry $voce): void
    {
        try {
            $this->collega($voce);
        } catch (\Throwable $e) {
            // 💡 Si registra e si tira dritto: la voce di diario e' gia' salva,
            // ed e' l'unica cosa che non si puo' perdere.
            Log::warning('Catalogo alimenti: non si e\' potuta collegare la voce di diario.', [
                'voce' => $voce->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }

    private function collega(FoodEntry $voce): void
    {
        $utente = $voce->user;

        if ($utente === null) {
            return;
        }

        $per100 = $this->per100($voce);

        if ($per100 === null) {
            return;
        }

        $alimento = app(CatalogoAlimenti::class)->registra(
            (string) $voce->description,
            null,
            $per100,
            (string) ($voce->source === 'ai' ? 'ai' : 'manuale'),
            $utente,
        );

        if ($alimento === null) {
            return;
        }

        // 🚨 `saveQuietly` o questo osservatore si richiamerebbe da solo — non
        // all'infinito (e' `created`, non `updated`), ma sarebbe un `updated`
        // in piu' che nessuno si aspetta.
        $voce->forceFill(['food_id' => $alimento->getKey()])->saveQuietly();
    }

    /**
     * I valori per 100 g della voce, se ci sono o se si possono ricavare.
     *
     * ── 💡 Perche' si prova anche a ricavarli ─────────────────────────────
     *
     * Le colonne `*_100` esistono gia' su `food_entries`, ma non sempre sono
     * compilate: chi scrive un pasto a mano inserisce spesso **i totali della
     * porzione** e i grammi. ⚠️ Buttare via quelle voci vorrebbe dire perdere
     * proprio gli inserimenti manuali completi, che sono i migliori che
     * abbiamo.
     *
     * 🚨 Senza i grammi non si ricava niente e si lascia perdere: dividere per
     * una quantita' che non si conosce e' il modo di riempire il catalogo di
     * numeri inventati.
     *
     * @return array{kcal_100: ?float, protein_100: ?float, carbs_100: ?float, fat_100: ?float}|null
     */
    private function per100(FoodEntry $voce): ?array
    {
        if ($voce->kcal_100 !== null) {
            return [
                'kcal_100' => (float) $voce->kcal_100,
                'protein_100' => $voce->protein_100 !== null ? (float) $voce->protein_100 : null,
                'carbs_100' => $voce->carbs_100 !== null ? (float) $voce->carbs_100 : null,
                'fat_100' => $voce->fat_100 !== null ? (float) $voce->fat_100 : null,
            ];
        }

        $grammi = $voce->grams !== null ? (float) $voce->grams : null;

        if ($grammi === null || $grammi <= 0 || $voce->kcal === null) {
            return null;
        }

        $per100 = static fn (mixed $totale): ?float => $totale === null
            ? null
            : round((float) $totale * 100 / $grammi, 2);

        return [
            'kcal_100' => $per100($voce->kcal),
            'protein_100' => $per100($voce->protein),
            'carbs_100' => $per100($voce->carbs),
            'fat_100' => $per100($voce->fat),
        ];
    }
}
