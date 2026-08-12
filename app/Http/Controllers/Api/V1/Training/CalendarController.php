<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Training\CalendarService;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Il calendario — C4.
 *
 * Mese e settimana sono due letture della stessa cosa e restituiscono la
 * **stessa forma** di celle: l'app disegna due layout, non due modelli.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendario) {}

    public function month(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $utente = $request->user();

        // A3: «questo mese» e' quello di chi guarda. Con `Carbon::today()`, il
        // 1º del mese alle 00:30 di Roma il calendario si apriva sul mese prima.
        $mese = $request->filled('month')
            ? $utente->giorno((string) $request->query('month').'-01')
            : $utente->giornoDiOggi();

        return response()->json(['data' => $this->calendario->month($utente, $mese->inizioMese())]);
    }

    public function week(Request $request): JsonResponse
    {
        $request->validate([
            'week' => ['nullable', 'date'],
        ]);

        $utente = $request->user();

        $giorno = $request->filled('week')
            ? $utente->giorno((string) $request->query('week'))
            : $utente->giornoDiOggi();

        return response()->json(['data' => $this->calendario->week($utente, $giorno)]);
    }

    public function day(Request $request, string $date): JsonResponse
    {
        $giorno = $this->giornoValido($request->user(), $date);

        if ($giorno === null) {
            // 422 e non un ripiego su oggi: rispondere con un giorno diverso da
            // quello chiesto è il tipo di correzione silenziosa che fa cercare
            // il difetto nel posto sbagliato.
            return response()->json(['message' => __('Data non valida.')], 422);
        }

        return response()->json(['data' => $this->calendario->day($request->user(), $giorno)]);
    }

    /**
     * La data, solo se è davvero quella scritta.
     *
     * 🚨 **`Carbon::createFromFormat()` non solleva niente sulle date
     * impossibili: trabocca.** `2026-13-45` diventa il 14 febbraio 2027, senza
     * nessun segnale. Un `try/catch` intorno sembra un controllo e non controlla
     * niente — l'app riceverebbe **200** con i dati di un giorno che non ha mai
     * chiesto, e chi cerca il difetto lo cercherebbe nel calendario.
     *
     * La verifica è il giro di andata e ritorno: se riformattando non si
     * riottiene la stringa di partenza, la data non esisteva.
     *
     * ⚠️ Il controllo si fa **prima** di costruire il `GiornoLocale`, e su una
     * `Carbon` senza fuso: qui si sta validando una **stringa**, non un istante.
     * Mescolare le due cose rimetterebbe in circolo il difetto A3 proprio nel
     * metodo che serve a non fidarsi dell'input.
     */
    private function giornoValido(User $utente, string $date): ?GiornoLocale
    {
        try {
            $giorno = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        return $giorno->format('Y-m-d') === $date
            ? $utente->giorno($date)
            : null;
    }
}
