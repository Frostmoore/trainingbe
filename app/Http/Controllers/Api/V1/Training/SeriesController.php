<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Services\Training\SeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le serie per i grafici della dashboard — C3.
 *
 * Un endpoint solo per due metriche, con la **stessa forma di risposta**: così
 * l'app ha un parser unico. Due endpoint con due forme diverse sono due
 * occasioni di sbagliare, e la seconda si scopre sempre più tardi.
 */
class SeriesController extends Controller
{
    /** Le finestre che l'app offre. Un valore fuori elenco è un errore, non un default. */
    private const GIORNI_AMMESSI = [0, 7, 30, 90, 365];

    public function __construct(private readonly SeriesService $serie) {}

    public function index(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'metric' => ['required', 'in:weight,calories'],

            // `0` è «tutto lo storico»: è il valore che manda il pulsante
            // «Tutto», non un caso limite.
            'days' => ['nullable', 'integer', 'in:'.implode(',', self::GIORNI_AMMESSI)],

            // Con `days = 0` non ha senso scorrere: non c'è niente prima di
            // tutto. Il servizio lo ignora, ma qui si tiene un tetto perché un
            // offset enorme farebbe girare a vuoto un ciclo su dieci anni.
            'offset' => ['nullable', 'integer', 'min:0', 'max:520'],
        ]);

        $utente = $request->user();
        $giorni = (int) ($dati['days'] ?? 7);
        $offset = (int) ($dati['offset'] ?? 0);

        $risultato = $dati['metric'] === 'weight'
            ? $this->serie->weight($utente, $giorni)
            : $this->serie->calories($utente, $giorni, $offset);

        return response()->json(['data' => array_merge($risultato, [
            'days' => $giorni,
            'offset' => $offset,
            // Dice all'app se il pulsante «periodo precedente» ha senso: senza,
            // dovrebbe sapere che `days = 0` non scorre, cioè conoscere una
            // regola del server.
            'can_go_back' => $giorni > 0,
        ])]);
    }
}
