<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Services\Nutrition\Catalogo\RicercaAlimenti;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I suggerimenti mentre si scrive — 17/08/2026.
 *
 * 🚨 **Restituisce solo quello che serve a compilare un pasto**, e la nota di
 * provenienza. ⚠️ Non restituisce `conferme` ne' `usi`: sono numeri interni,
 * e pubblicarli direbbe a chiunque quante persone hanno mangiato una certa
 * cosa — un'informazione che non serve a nessuno e che non c'e' motivo di dare.
 */
class CatalogoAlimentiController extends Controller
{
    public function search(Request $request, RicercaAlimenti $ricerca): JsonResponse
    {
        $dati = $request->validate([
            'q' => ['required', 'string', 'min:'.RicercaAlimenti::MINIMO_CARATTERI, 'max:80'],
            'limite' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $trovati = $ricerca->cerca(
            $dati['q'],
            $request->user(),
            (int) ($dati['limite'] ?? 20),
        );

        return response()->json([
            'data' => $trovati->map(fn (Food $a): array => [
                'id' => $a->id,
                'nome' => $a->nome,
                'marca' => $a->marca,
                'kcal_100' => $a->kcal_100,
                'protein_100' => $a->protein_100,
                'carbs_100' => $a->carbs_100,
                'fat_100' => $a->fat_100,
                'basis' => $a->basis,
                'codice_a_barre' => $a->codice_a_barre,
                'immagine_url' => $a->immagine_piccola_url ?? $a->immagine_url,

                /*
                 * 🚨 **La nota e' l'attribuzione, e va mostrata.**
                 *
                 * CREA chiede «una chiara indicazione della fonte originale»,
                 * Open Food Facts chiede l'attribuzione ODbL. ⚠️ Tenerla solo
                 * in banca dati e' meta' del lavoro: la licenza chiede che la
                 * veda **chi usa il dato**, e chi usa il dato e' la persona
                 * davanti allo schermo.
                 */
                'note' => $a->note,
            ])->all(),
        ]);
    }
}
