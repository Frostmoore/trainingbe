<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\SocialProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accesso con Google o Apple.
 *
 * ⛔ **Niente più `join_code`** — F11, 24/09/2026: al primo accesso l'identita'
 * si collega all'account con la stessa email verificata, o nasce uno spazio
 * personale (`SocialAuthController::primoAccessoSenzaPalestra()`).
 */
class SocialLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * 🚨 `Rule::enum` e non `'string'`.
             *
             * E' la trappola di C0.5, che era costata un cibo nel pasto
             * sbagliato: una stringa libera su un valore chiuso passa la
             * validazione e poi si perde da qualche parte piu' avanti. Qui
             * finirebbe per creare identita' con un `provider` che nessuna
             * query ritrovera' — cioe' un account irraggiungibile.
             */
            'provider' => ['required', Rule::enum(SocialProvider::class)],

            // Il token d'identita' (`id_token` per Google, `identityToken` per
            // Apple). Lungo: un JWT con le rivendicazioni di Apple supera
            // tranquillamente i mille caratteri.
            'id_token' => ['required', 'string', 'max:8192'],

            /*
             * ⛔ **Il codice palestra non esiste più** — F11, 24/09/2026.
             *
             * 🚨 `prohibited` e non «si ignora»: chi manda un codice pensa di
             * entrare in una palestra, e ignorarlo lo farebbe nascere **fuori**
             * senza dirglielo. Meglio un 422 che spiega dove si entra (il link
             * d'invito). ⚠️ Il vuoto passa: le app vecchie mandano il campo
             * sempre, anche quando l'utente non ha scritto niente
             * (`prepareForValidation()` lo porta a `null`).
             */
            'join_code' => ['prohibited'],

            /*
             * 🚨 **Lo sbarramento dei maggiorenni, anche di qui — S9.2.**
             *
             * ⚠️ `exclude_without:join_code` e non `required`: il `join_code`
             * arriva **solo al primo accesso**, ed e' quello il momento in cui
             * ci si iscrive. Chiederlo a ogni accesso vorrebbe dire far
             * ridichiarare la maggiore eta' a ogni apertura dell'app — e
             * trasformerebbe una dichiarazione in un tasto che si preme senza
             * leggere.
             *
             * 💡 **Questa e' la porta che si dimentica**: chi entra con Google
             * non passa da `RegisterRequest`. Metterlo solo sul modulo di
             * registrazione lascerebbe scoperta meta' delle iscrizioni.
             *
             * ── 🚨 F3 ha rotto il presupposto di questa regola ──────────────
             *
             * `exclude_without:join_code` si reggeva su un'equivalenza che da
             * F3 **non vale piu'**: *«niente codice ⇒ non e' un primo accesso»*.
             * Da F3 il codice mancante significa anche **«primo accesso senza
             * palestra»**, cioe' l'iscrizione di un utente gratuito.
             *
             * ⚠️ Lasciata com'era, questa riga avrebbe fatto **saltare lo
             * sbarramento 18+ a tutte le iscrizioni con Google senza codice** —
             * che nella Parte B sono la maggioranza. Nessun errore, nessun test
             * rosso: solo una casella che smette di essere chiesta.
             *
             * 💡 La regola qui resta **permissiva di proposito**, perche' questo
             * modulo non puo' sapere se l'identita' e' gia' nota: lo si scopre
             * solo dopo aver verificato il token. Il controllo vero e' quindi in
             * `SocialAuthController::pretendiIConsensi()`, chiamato **all'inizio
             * di ogni primo accesso**, con la palestra o senza.
             */
            /*
             * ✅ F11 (24/09/2026): il codice non arriva più, e le due regole
             * `exclude_without:join_code` sarebbero state sempre escluse. Tolte:
             * il controllo è **solo** `SocialAuthController::pretendiIConsensi()`,
             * a ogni primo accesso — com'era già da F3.
             */

            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Vedi `RegisterRequest`: il vuoto è «nessun codice».
        if (is_string($this->join_code) && trim($this->join_code) === '') {
            $this->merge(['join_code' => null]);
        }
    }

    public function fornitore(): SocialProvider
    {
        return SocialProvider::from($this->string('provider')->toString());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'join_code.prohibited' => __('I codici palestra non esistono più: in una palestra si entra con il link d\'invito.'),
        ];
    }
}
