<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accesso di un iscritto.
 *
 * Serve il `join_code` oltre alle credenziali, perché **l'email è unica per
 * palestra e non per piattaforma**: senza, `mario@esempio.it` sarebbe ambiguo
 * fra due palestre e il sistema dovrebbe indovinare. L'app lo ha già, perché lo
 * usa per scaricare il branding prima ancora di mostrare la schermata di login.
 *
 * ⚠️ Questo NON viola ADR-04 («il tenant non arriva mai dal client»). Il
 * `join_code` serve solo a **trovare** l'utente candidato; una volta autenticato,
 * il tenant si legge da `$user->tenant_id`. Indicare il codice di un'altra
 * palestra non dà accesso a nulla: semplicemente le credenziali non
 * corrisponderanno a nessun utente di quella palestra.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * 🚨 **Il campo si chiama `login` e accetta email O nome utente.**
     *
     * Non ha la regola `email`: metterla rifiuterebbe `marco.rossi` prima
     * ancora di provare ad autenticarlo. E non si chiama più `email` perché il
     * nome mentiva — un campo chiamato `email` che accetta anche altro è la
     * ragione per cui, prima o poi, qualcuno ci rimette una validazione
     * `email` credendo di sistemare una dimenticanza.
     *
     * ⚠️ `email` resta accettato come sinonimo: l'app in circolazione lo invia
     * ancora così, e un rilascio del backend non deve rompere le installazioni
     * già sui telefoni. Vedi `prepareForValidation()`.
     */
    public function rules(): array
    {
        return [
            /*
             * 🆕 **Facoltativo da F3.** Senza codice si accede al proprio
             * account **personale** (`TenantKind::Personal`).
             *
             * 🚨 Non era nel piano, ed è stato aggiunto perché senza F3 sarebbe
             * stata una funzione rotta: la registrazione libera avrebbe creato
             * persone che **non riescono più a entrare**. Il dettaglio in
             * `AuthController::login()`.
             *
             * ⚠️ Come in `RegisterRequest`, il vuoto diventa `null` in
             * `prepareForValidation()`: `nullable` non basta, perché l'app manda
             * il campo sempre e `''` non è `null`.
             */
            'join_code' => ['nullable', 'string', 'size:8'],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * L'identificativo, comunque sia stato chiamato.
     *
     * Serve al controller per non dover sapere che esistono due nomi.
     */
    public function identifier(): string
    {
        return $this->string('login')->toString();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->join_code)) {
            $codice = strtoupper(trim($this->join_code));

            // Vedi la nota gemella in `RegisterRequest`: `nullable` salta le
            // regole solo su `null`, e l'app manda il campo anche quando è vuoto.
            $this->merge(['join_code' => $codice === '' ? null : $codice]);
        }

        // 🚨 Compatibilità all'indietro: le versioni dell'app già installate
        // mandano `email`. Si accetta, e da qui in poi esiste solo `login`.
        $identificativo = $this->input('login') ?? $this->input('email');

        if (is_string($identificativo)) {
            $this->merge(['login' => strtolower(trim($identificativo))]);
        }
    }

    /**
     * @return array<string, string>
     *
     * Senza questo, un `login` vuoto darebbe «Il campo login è obbligatorio»,
     * che a un utente non dice niente.
     */
    public function messages(): array
    {
        return [
            'login.required' => __('Inserisci la tua email o il tuo nome utente.'),
        ];
    }

    /**
     * @return array<string, string>
     *
     * L'errore torna sotto la chiave `login`, e l'app deve poterlo mostrare
     * sotto il campo giusto anche quando lo ha inviato come `email`.
     */
    public function attributes(): array
    {
        return ['login' => __('email o nome utente')];
    }
}
