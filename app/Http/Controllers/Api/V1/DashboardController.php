<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Il riepilogo della schermata principale — D4.
 *
 * 🚨 **Una chiamata sola.** La dashboard mostra calorie, allenamenti recenti,
 * peso, sonno, HRV e battito: con sei richieste separate basta che una sia lenta
 * perché la schermata compaia a pezzi, e su rete mobile succede sempre.
 *
 * Il consiglio del giorno resta separato (`GET /ai/advice`): è l'unica parte che
 * può costare una chiamata a un modello, e legarla al riepilogo significherebbe
 * far aspettare tutta la schermata per la cosa meno urgente.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * 🆕 **Accetta `?date=` per sfogliare i giorni indietro** — 3b-O.1b.2.
     *
     * 📌 Serve alle frecce nell'intestazione di «Oggi»: *«una data con le
     * freccette per passare ai giorni precedenti o successivi»*.
     *
     * 💡 `forToday()` accettava già un istante: qui non si aggiunge una
     * strada nuova, si smette di passargli sempre «adesso». ⚠️ **La fine del
     * giorno** e non l'inizio: la percentuale di giornata trascorsa serve a dire
     * «sei in linea con l'ora», e per un giorno passato l'ora è finita.
     *
     * ⛔ **Mai nel futuro.** Il diario non si compila in anticipo, e una data
     * avanti darebbe una giornata vuota che sembra un guasto — oltre a poter
     * essere usata per far calcolare al server qualcosa che non esiste.
     *
     * 🚨 Il fuso è quello **dell'utente**: `2026-08-20` per chi vive a Roma
     * finisce alle 21:59 UTC, e prendere la fine del giorno in UTC gli darebbe
     * due ore del giorno dopo.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->query('date');
        $adesso = null;

        if (is_string($data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $scelto = Carbon::createFromFormat(
                'Y-m-d',
                $data,
                $request->user()->fusoOrario(),
            )->endOfDay();

            $adesso = $scelto->isFuture() ? null : $scelto->utc();
        }

        return response()->json([
            'data' => $this->dashboard->forToday($request->user(), $adesso),
        ]);
    }
}
