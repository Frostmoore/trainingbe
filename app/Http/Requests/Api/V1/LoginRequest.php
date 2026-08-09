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
     * ⚠️ `email` accetta anche il **nome utente**, quindi non ha la regola
     * `email`: metterla rifiuterebbe `marco.rossi` prima ancora di provare ad
     * autenticarlo. Il nome del campo resta `email` per non rompere l'app, che
     * lo invia già così.
     */
    public function rules(): array
    {
        return [
            'join_code' => ['required', 'string', 'size:8'],
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->join_code)) {
            $this->merge(['join_code' => strtoupper(trim($this->join_code))]);
        }
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }
}
