<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il branding della palestra, prima del login.
 *
 * È l'**unico** endpoint pubblico che espone dati di una palestra, e serve a una
 * cosa sola: l'app deve potersi vestire dei colori giusti nella schermata di
 * accesso, quando ancora non c'è nessuna sessione.
 *
 * 🚨 **Perché 404 e non un errore descrittivo.**
 * Codice inesistente e palestra sospesa danno la stessa identica risposta. Se
 * fossero distinguibili, chiunque potrebbe provare codici a tappeto e ricavare
 * l'elenco dei clienti: quali palestre esistono, quali hanno smesso di pagare e
 * quando. Sono informazioni commerciali che non riguardano chi non è iscritto.
 *
 * 🚨 **Nel corpo va SOLO il branding.** Niente id, niente conteggi, niente stato,
 * niente email di contatto. Ogni campo in più è un campo che qualcuno userà.
 */
class BrandingController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->query('code')));

        // Il formato si controlla prima di interrogare il database: un codice
        // malformato non deve nemmeno diventare una query, altrimenti il tempo
        // di risposta distingue «codice valido ma inesistente» da «spazzatura».
        if (! preg_match('/^[A-Z0-9]{8}$/', $code)) {
            return $this->notFound();
        }

        $tenant = Tenant::where('join_code', $code)->first();

        if ($tenant === null || ! $tenant->isActive()) {
            return $this->notFound();
        }

        return response()->json(['data' => $tenant->branding()]);
    }

    /**
     * L'unica risposta negativa possibile.
     *
     * Un solo metodo perché non ci sia modo di introdurre per distrazione due
     * varianti leggermente diverse, che è esattamente come nascono gli oracoli.
     */
    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => __('Codice palestra non valido.'),
            'code' => 'gym_not_found',
        ], 404);
    }
}
