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

            /*
             * Il nome utente.
             *
             * 🚨 **Unico su tutta la piattaforma**, non per palestra — al
             * contrario dell'email. È voluto: serve proprio a essere un
             * identificativo che non ha bisogno del codice palestra per essere
             * disambiguato, ed è quello che permette il login dai pannelli,
             * dove un `join_code` non c'è.
             *
             * ⚠️ Qui `unique` è legittimo e non è un oracolo come lo sarebbe
             * sull'email: sapere che un nome utente è già preso non dice a
             * quale palestra appartenga né che esista una persona con
             * quell'indirizzo. È la stessa informazione che dà qualunque sito
             * al momento della registrazione.
             *
             * Il formato è ristretto apposta: niente `@`, così un nome utente
             * non può mai essere scambiato per un'email al momento del login,
             * e niente maiuscole (le normalizza il modello) perché `Marco` e
             * `marco` non diventino due account che all'occhio sono lo stesso.
             */
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/',
                'unique:users,username',
            ],

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

        // Il nome utente si normalizza **prima** della validazione, non dopo:
        // altrimenti `Marco` fallirebbe la regola sul formato pur essendo un
        // nome utente perfettamente legittimo, e l'utente non capirebbe perché.
        if (is_string($this->username)) {
            $this->merge(['username' => strtolower(trim($this->username))]);
        }
    }
}
