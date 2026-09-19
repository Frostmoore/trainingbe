<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Il profilo dell'app — C1. 🆕 **Dal 19/09/2026 non sta più qui.**
 *
 * ══ 📱 IL PROFILO STA SUL TELEFONO ════════════════════════════════════════
 *
 * 📌 Il committente: *«assicurati che non ci scriva niente di personale
 * (esattamente come peso altezza e data di nascita)»*. Sesso, data di nascita,
 * altezza, livello di attivita', obiettivo, peso obiettivo e orari dei pasti
 * stanno in `ProfiloSulTelefono`, nell'app; le colonne sono state tolte
 * (`2026_09_19_150000_il_profilo_non_ha_piu_i_dati_del_corpo`).
 *
 * 💡 **Le tre rotte restano, e servono al trasloco** (`TraslocoDelProfilo`, app
 * `v18.1.0`): un telefono che non l'ha ancora fatto chiede `GET`, trova un
 * profilo vuoto, chiede `DELETE` e segna il trasloco come fatto. Senza le rotte
 * riproverebbe a ogni accesso.
 */
class ProfileController extends Controller
{
    /**
     * Un profilo **vuoto**, nella forma che l'app sa leggere.
     *
     * ⚠️ Non si toglie: un telefono con l'app `v18.1.0` che non ha ancora fatto il
     * trasloco lo chiede, e deve trovare «niente da portare».
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'sex' => null,
            'birthdate' => null,
            'age' => null,
            'height_cm' => null,
            'activity_level' => null,
            'goal' => null,
            'target_weight_kg' => null,
            'meal_hours' => null,
            'weight_kg' => null,
            'derived' => null,
            'missing' => ['weight_kg', 'sex', 'birthdate', 'height_cm'],
        ]]);
    }

    /**
     * ⛔ **Il profilo non si scrive qui.** 410 e non un «ok» che ignora i campi:
     * un'app vecchia che salva crederebbe di aver salvato, e alla prossima
     * apertura ritroverebbe i campi vuoti senza sapere perche'.
     */
    public function update(): JsonResponse
    {
        return response()->json([
            'message' => __('Il profilo adesso resta sul tuo telefono: aggiorna l\'app.'),
            'code' => 'profilo_sul_telefono',
        ], 410);
    }

    /**
     * 💡 Chiude il trasloco dell'app. Non c'e' piu' niente da cancellare: le
     * colonne non esistono. Resta per non far riprovare l'app a ogni accesso.
     */
    public function destroy(): JsonResponse
    {
        return response()->json(['message' => __('Sul server non c\'è niente.')]);
    }
}
