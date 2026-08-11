<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I consensi facoltativi — S9.1.
 *
 * ── 🚨 Perché stanno qui e non nella registrazione ────────────────────────
 *
 * Un consenso richiesto **per potersi iscrivere** non è «liberamente dato» ai
 * sensi dell'art. 7(4) GDPR — e un consenso non liberamente dato **non è
 * consenso**, quindi non è una base giuridica valida.
 *
 * L'app deve funzionare per chi dice di no a tutto: senza il consenso sanitario
 * non si collega Health Connect, senza quello all'AI non c'è il consiglio del
 * giorno. Il resto — diario, allenamenti, chat — continua a funzionare.
 *
 * ── 🚨 Perché sono due caselle e non una ──────────────────────────────────
 *
 * *«Accetto il trattamento dei dati»* in una casella sola **non è consenso
 * esplicito** ai sensi dell'art. 9(2)(a). Tenere i propri dati sanitari **da
 * noi** e mandarli **ad Anthropic, negli Stati Uniti** sono due decisioni
 * diverse, e chi accetta la prima non ha per questo accettato la seconda.
 */
class ConsentController extends Controller
{
    /**
     * Quali colonne si possono toccare da qui, e con che nome pubblico.
     *
     * 🚨 **Un elenco chiuso, non `$request->all()`.** `age_confirmed_at` e
     * `terms_accepted_at` **non ci sono di proposito**: si danno una volta
     * all'iscrizione e non si «revocano» — revocare le condizioni d'uso
     * significa cancellare l'account, che è un'altra porta e un'altra
     * conferma.
     *
     * @var array<string, string>
     */
    private const CONSENSI = [
        'health' => 'health_consent_at',
        'ai' => 'ai_consent_at',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->stato($request->user())]);
    }

    /**
     * Concede o revoca.
     *
     * 🚨 **Revocare costa esattamente quanto concedere** (art. 7(3)): la stessa
     * chiamata, lo stesso campo, `false` invece di `true`. Un consenso che si dà
     * con un tocco e si toglie scrivendo un'email **non è liberamente
     * revocabile** — e quindi, a rigore, non è mai stato valido.
     *
     * ⚠️ **Revocare non cancella ciò che è già stato fatto**, e va detto
     * nell'interfaccia: il trattamento fino a quel momento resta lecito
     * (art. 7(3), terzo periodo). Chi vuole anche la cancellazione ha
     * `DELETE /account`.
     */
    public function update(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'health' => ['sometimes', 'boolean'],
            'ai' => ['sometimes', 'boolean'],
        ]);

        $utente = $request->user();

        foreach (self::CONSENSI as $nome => $colonna) {
            if (array_key_exists($nome, $dati)) {
                $utente->registraConsenso($colonna, (bool) $dati[$nome]);
            }
        }

        return response()->json(['data' => $this->stato($utente->refresh())]);
    }

    /**
     * Lo stato, con **le date**.
     *
     * 💡 L'app le usa per dire *«concesso il 12 agosto»* invece di una spunta
     * muta: chi rilegge la propria schermata dei consensi sta cercando di
     * ricordarsi cosa ha deciso e quando, e una spunta da sola non risponde.
     *
     * @return array<string, mixed>
     */
    private function stato(User $utente): array
    {
        return [
            'health' => $utente->health_consent_at?->toIso8601String(),
            'ai' => $utente->ai_consent_at?->toIso8601String(),
            'age_confirmed_at' => $utente->age_confirmed_at?->toIso8601String(),
            'terms_accepted_at' => $utente->terms_accepted_at?->toIso8601String(),
        ];
    }
}
