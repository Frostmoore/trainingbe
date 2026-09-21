<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\EventoDiPromozione;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Promozioni\GliEventi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 🤝 **Porta un amico** — 24/09/2026, P6 di `plan_promozioni.md` (opzione 27).
 *
 * | Rotta | Cosa fa |
 * |---|---|
 * | `GET account/amico` | il mio codice, il link da condividere, quanti amici ho portato |
 * | `POST account/amico` | «mi ha portato lui»: collega chi arriva a chi l'ha invitato |
 *
 * ══ 🚨 PERCHÉ UN ENDPOINT E NON UN CAMPO DELLA REGISTRAZIONE ═════════════
 *
 * Ci si registra da cinque strade (app, social, sito, invito di palestra, invito
 * di trainer). ⛔ Un campo in ognuna sono cinque posti da tenere allineati, e la
 * sesta strada lo dimenticherebbe. 💡 L'app chiama questo endpoint **subito
 * dopo** la registrazione, se è stata aperta da un link amico.
 *
 * ⛔ **Una volta sola, e solo nei primi 7 giorni**: altrimenti chiunque potrebbe
 * «farsi portare» da un amico a distanza di mesi per incassare il premio, o
 * cambiare padrino ogni volta che conviene.
 */
class AmicoController extends Controller
{
    /** Quanto tempo ha chi si è appena iscritto per dire chi l'ha portato. */
    public const GIORNI_PER_COLLEGARSI = 7;

    public function show(Request $request): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();
        $codice = $utente->codiceAmico();

        return response()->json(['data' => [
            'codice' => $codice,
            'link' => rtrim((string) config('app.url'), '/').'/amico/'.$codice,
            'amici' => User::withoutGlobalScopes()->where('invitato_da_id', $utente->getKey())->count(),
            'invitato_da' => $utente->invitato_da_id !== null,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate(['codice' => ['required', 'string', 'max:16']]);

        /** @var User $utente */
        $utente = $request->user();

        if ($utente->invitato_da_id !== null) {
            throw ValidationException::withMessages(['codice' => __('Hai già indicato chi ti ha invitato.')]);
        }

        if ($utente->created_at === null || $utente->created_at->lt(now()->subDays(self::GIORNI_PER_COLLEGARSI))) {
            throw ValidationException::withMessages(['codice' => __('Il codice amico si può inserire solo nei primi giorni dopo l\'iscrizione.')]);
        }

        $invitante = User::withoutGlobalScopes()
            ->where('codice_amico', mb_strtoupper(trim($dati['codice'])))
            ->first();

        // ⛔ Un solo messaggio per «non esiste» e «sei tu»: niente indizi a chi prova i codici.
        if ($invitante === null || $invitante->is($utente)) {
            throw ValidationException::withMessages(['codice' => __('Questo codice non è valido.')]);
        }

        $utente->forceFill(['invitato_da_id' => $invitante->getKey()])->save();

        app(GliEventi::class)->succede(EventoDiPromozione::AmicoArrivato, $utente, (string) $utente->getKey(), [
            'quando' => 'registrazione',
            'invitante' => $invitante,
        ]);

        return response()->json(['data' => ['collegato' => true]], 201);
    }
}
