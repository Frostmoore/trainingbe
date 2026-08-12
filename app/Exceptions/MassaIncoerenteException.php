<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Un alimento dichiara piu' macronutrienti di quanto pesa.
 *
 * 🚨 **E' un'impossibilita' fisica, non un'imprecisione**, ed e' la ragione per
 * cui questa e' l'unica guardia del sistema che **blocca** invece di limitarsi
 * ad abbassare la confidenza. Cento grammi di prodotto non possono contenere
 * centoventi grammi di macronutrienti: non c'e' nessuna interpretazione, nessuna
 * tabella e nessun margine di stima in cui quel numero abbia senso.
 *
 * ⚠️ La guardia sulla coerenza **energetica** e' invece morbida di proposito: la
 * fibra rende 2 kcal/g invece di 4, i polioli 2,4, e gli arrotondamenti su
 * porzioni piccole valgono piu' dello scarto cercato. La' un numero fuori banda
 * puo' essere giusto; qui no.
 *
 * 💡 Ha il proprio `render()` perche' arrivi al client come **422 leggibile** e
 * non come 500: e' un dato rifiutato, non un guasto del server.
 */
final class MassaIncoerenteException extends RuntimeException
{
    public function __construct(
        public readonly string $alimento,
        public readonly float $grammi,
        public readonly float $macroTotali,
    ) {
        parent::__construct(sprintf(
            '«%s» dichiara %.1f g di macronutrienti in %.1f g di alimento.',
            $alimento,
            $macroTotali,
            $grammi,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'macros_exceed_mass',
            'message' => __(
                'I valori di «:alimento» non sono possibili: :macro g di proteine, carboidrati e grassi in :grammi g di alimento. Correggili prima di salvare.',
                [
                    'alimento' => $this->alimento,
                    'macro' => round($this->macroTotali, 1),
                    'grammi' => round($this->grammi, 1),
                ],
            ),
            'meta' => [
                'food' => $this->alimento,
                'grams' => round($this->grammi, 2),
                'macros_g' => round($this->macroTotali, 2),
            ],
        ], 422);
    }
}
