<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Iscrizione di un nuovo membro tramite il codice della palestra.
 *
 * Registra SEMPRE e SOLO un iscritto: il ruolo non è un dato in ingresso.
 * Se lo fosse, chiunque conosca un codice palestra potrebbe farsi amministratore
 * — e sarebbe un campo che nessuno nota finché non viene sfruttato.
 * Trainer e amministratori si creano dal pannello (B3).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'join_code' => ['required', 'string', 'size:8'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()->min(8)],
            'phone' => ['nullable', 'string', 'max:32'],

            // Etichetta del dispositivo, mostrata poi in /devices perché
            // l'utente possa riconoscere e revocare la sessione giusta.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * L'unicità dell'email NON si valida qui.
     *
     * È unica **per palestra**, e la palestra si conosce solo dopo aver
     * risolto il `join_code`. Una regola `unique:users,email` sarebbe:
     * - sbagliata, perché vieterebbe la stessa email in due palestre diverse;
     * - e un oracolo, perché direbbe a chiunque se un indirizzo è già iscritto.
     *
     * Il controllo vero lo fa il controller dentro il contesto del tenant, e il
     * vincolo `UNIQUE(tenant_id, email)` del database è la rete di sicurezza.
     */
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
