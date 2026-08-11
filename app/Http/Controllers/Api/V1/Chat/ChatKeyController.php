<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatKey;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Il registro delle chiavi pubbliche — S6.3.
 *
 * ── Perche' non e' un elenco aperto ────────────────────────────────────────
 *
 * Le chiavi sono pubbliche, quindi in teoria potrebbero stare in una rubrica
 * consultabile da chiunque. ⚠️ **Ma «chi ha una chiave in questo sistema» e'
 * gia' un'informazione**: dice chi e' iscritto e chi ha aperto l'app. Per
 * questo si prende **una chiave alla volta**, e solo attraverso una
 * conversazione a cui si partecipa — le stesse due serrature che proteggono i
 * messaggi.
 */
class ChatKeyController extends Controller
{
    /**
     * Pubblica la propria chiave.
     *
     * 🚨 **Sostituire la propria chiave e' un'operazione pesante e silenziosa**:
     * da quel momento i messaggi ricevuti prima non si aprono piu', perche' sono
     * cifrati verso una chiave che non c'e' piu'. Succede legittimamente quando
     * si perde la chiave maestra — ma l'app deve arrivarci **solo** dopo aver
     * provato il ripristino, mai come primo tentativo.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            // base64 di 32 byte X25519: 44 caratteri con il riempimento.
            'public_key' => ['required', 'string', 'size:44'],
        ]);

        $chiave = ChatKey::updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            ['public_key' => $dati['public_key']],
        );

        return response()->json(['data' => [
            'public_key' => $chiave->public_key,
            'updated_at' => $chiave->updated_at?->toIso8601String(),
        ]], 201);
    }

    /**
     * La chiave pubblica dell'altra persona in questa conversazione.
     *
     * 🚨 **`updated_at` non e' un dettaglio di comodo.** E' il dato con cui
     * l'app si accorge che la chiave dell'altro e' **cambiata** rispetto a
     * quella vista l'ultima volta — l'unico segnale visibile di un server che
     * prova a mettersi in mezzo, e insieme il segnale (molto piu' frequente) che
     * l'altra persona ha rifatto l'account.
     *
     * ⚠️ **404 anche quando la chiave non c'e' ancora**: significa che l'altra
     * persona non ha mai aperto l'app dopo questa versione. L'app deve dirlo
     * («non puoi ancora scrivergli»), non provare a cifrare verso il nulla.
     */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $c = Conversation::query()
            ->forUser($request->user())
            ->find($conversation);

        if ($c === null || Gate::denies('view', $c)) {
            return response()->json(['message' => __('Conversazione non trovata.')], 404);
        }

        $altro = $c->otherParty($request->user());

        $chiave = $altro === null
            ? null
            : ChatKey::query()->where('user_id', $altro->getKey())->first();

        if ($chiave === null) {
            return response()->json([
                'message' => __('Questa persona non ha ancora una chiave.'),
            ], 404);
        }

        return response()->json(['data' => [
            'user_id' => $altro->getKey(),
            'public_key' => $chiave->public_key,
            'updated_at' => $chiave->updated_at?->toIso8601String(),
        ]]);
    }
}
