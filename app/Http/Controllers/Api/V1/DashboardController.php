<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->forToday($request->user())]);
    }
}
