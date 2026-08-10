<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\SocialProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accesso con Google o Apple.
 *
 * ⚠️ **`join_code` e' facoltativo, e non e' una svista.** Serve solo la
 * **prima** volta, per sapere di quale palestra si sta parlando: l'email e'
 * unica per palestra, quindi senza codice non c'e' modo di dire a chi
 * appartenga un'identita' mai vista. Dagli accessi successivi la riga in
 * `social_identities` dice gia' chi e' la persona, e chiedere di nuovo il
 * codice sarebbe far ridigitare qualcosa che sappiamo.
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

            'join_code' => ['nullable', 'string', 'size:8'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->join_code)) {
            $this->merge(['join_code' => strtoupper(trim($this->join_code))]);
        }
    }

    public function fornitore(): SocialProvider
    {
        return SocialProvider::from($this->string('provider')->toString());
    }
}
