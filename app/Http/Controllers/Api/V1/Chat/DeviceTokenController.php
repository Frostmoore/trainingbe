<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I token dei telefoni per le notifiche push — B8.5.
 *
 * L'invio vero e' B10.6: qui si raccolgono soltanto. Raccoglierli prima serve a
 * non dover chiedere il permesso alle notifiche una seconda volta quando l'invio
 * sara' pronto — e la seconda richiesta e' quella che la gente nega.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:android,ios,web'],
        ]);

        $utente = $request->user();

        // UPSERT sul vincolo unico: la stessa app che si riavvia manda di nuovo
        // lo stesso token, e due righe uguali significano notifiche doppie —
        // il modo piu' rapido per far disattivare i permessi all'utente.
        $riga = DeviceToken::updateOrCreate(
            ['user_id' => $utente->getKey(), 'token' => $dati['token']],
            [
                'tenant_id' => $utente->tenant_id,
                'platform' => $dati['platform'],
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => ['id' => $riga->id]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $dati = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::query()
            ->where('user_id', $request->user()->getKey())
            ->where('token', $dati['token'])
            ->delete();

        return response()->json(null, 204);
    }
}
