<?php

declare(strict_types=1);

namespace App\Services\Nutrition;

use App\Models\NutritionPlan;
use App\Models\User;
use App\Support\Tempo\GiornoLocale;

/**
 * Il target alimentare di una persona in un giorno.
 *
 * ⛔ **Qui c'era tutta la giornata alimentare** (`forDate()`, `voce()`), letta da
 * `food_entries`. Il 22/09/2026 (A1 di `plan_blocco_A.md`) il diario ha lasciato il
 * server: è sul telefono dal 03/09, e la tabella non c'è più. Resta solo il
 * target, che non legge il diario: lo usano il consiglio del giorno
 * (`AiController::contestoConsiglio()`) e il cruscotto (`DashboardService`).
 */
class DiaryService
{
    /**
     * I target di oggi.
     *
     * Ordine: il piano alimentare attivo vince sul calcolo automatico — e'
     * stato scritto da un professionista che conosce la persona, mentre la
     * formula conosce solo quattro numeri. Se non c'e' piano, si calcola dal
     * profilo. Se manca anche quello, `null`: meglio niente che un target
     * inventato su cui costruire una dieta.
     *
     * @return array<string, mixed>|null
     */
    public function targetsFor(User $user, GiornoLocale $giorno): ?array
    {
        /*
         * 🚨 **Il target NON comprende piu' le bruciate** — FASE 11.5.
         *
         * ⚠️ Prima era `piano + bruciate`, e l'app sapeva di non doverle
         * risommare. Adesso il server le bruciate non le conosce, quindi manda
         * il numero del piano **e basta**: la somma la fa `TargetDelGiorno`
         * nell'app, che e' l'unico posto dove quella regola vive.
         *
         * 💡 E' anche piu' semplice di prima: il doppio conteggio non e' piu'
         * possibile, perche' non c'e' piu' nessun altro che possa sommare.
         */
        $bruciate = 0;

        $piano = NutritionPlan::activeFor($user, $giorno);

        if ($piano !== null && $piano->target_kcal !== null) {
            return [
                'kcal' => $piano->target_kcal + $bruciate,
                'kcal_base' => $piano->target_kcal,
                'protein_g' => $piano->target_protein_g,
                'carbs_g' => $piano->target_carbs_g,
                'fat_g' => $piano->target_fat_g,
                'source' => 'plan',
                'plan_id' => $piano->id,
            ];
        }

        /*
         * 🆕 ⛔ Qui c'era il target calcolato dal profilo (`computedTargets()`):
         * senza peso sul server rispondeva gia' sempre `null`, e dal 19/09/2026
         * il profilo non c'e' piu'. Il target lo calcola l'app.
         */
        return null;
    }

    /**
     * Le voci raggruppate per pasto, nell'ordine della giornata.
     *
     * I pasti vuoti ci sono lo stesso: l'app deve poter disegnare sei sezioni
     * senza inventarsi quelle che mancano, e «colazione: niente» e' un'informazione.
     *
     * @param  iterable<FoodEntry>  $voci
     * @return list<array<string, mixed>>
     */
}
