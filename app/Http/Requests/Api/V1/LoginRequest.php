<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accesso di un iscritto: email **o** nome utente, e password.
 *
 * ⛔ **Niente più `join_code`** — F11, 24/09/2026. 📌 *«è da rimuovere da tutto,
 * non esiste più»*. ⚠️ Un'app vecchia che lo manda ancora **non viene
 * respinta**: il campo semplicemente non si legge. All'accesso respingerla non
 * proteggerebbe niente, e chiuderebbe fuori chi ha le credenziali giuste.
 *
 * 💡 L'email è unica per palestra e non per piattaforma: se due account hanno
 * lo stesso indirizzo si rifiuta invece di indovinare, e si entra col nome
 * utente, che è unico ovunque (`AuthController::login()`).
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
