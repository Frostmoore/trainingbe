<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MealType;
use App\Models\Profile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'aggiornamento del profilo dell'iscritto — C1.2.
 *
 * 🚨 **Gli elenchi chiusi si validano contro `Profile::ACTIVITY_LEVELS` e
 * `Profile::GOALS`, non contro una lista scritta qui.** Copiarla creerebbe un
 * quarto posto da tenere allineato: chi aggiunge un livello al modello lo
 * vedrebbe rifiutato da un punto che non c'entra niente, e la ricerca del
 * perche' partirebbe dal posto sbagliato.
 *
 * ⚠️ Ogni campo e' `sometimes`: e' una PATCH. Chi manda solo l'altezza non deve
 * vedersi azzerare l'obiettivo, e un `nullable` senza `sometimes` farebbe
 * esattamente questo su ogni campo non inviato.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La rotta e' gia' dietro auth:sanctum + tenant + tenant.active, e il
        // profilo che si tocca e' sempre e solo il proprio (il controller non
        // accetta un id): non c'e' niente di piu' da autorizzare qui.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sex' => ['sometimes', 'nullable', 'in:m,f'],

            // `before:today` e non `before_or_equal`: una data di nascita di oggi
            // darebbe eta' 0, e la formula di Mifflin-St Jeor su un neonato non
            // ha senso. Il limite inferiore evita i refusi di battitura (1088).
            'birthdate' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],

            'height_cm' => ['sometimes', 'nullable', 'integer', 'min:80', 'max:250'],
            'activity_level' => ['sometimes', 'nullable', Rule::in(array_keys(Profile::ACTIVITY_LEVELS))],
            'goal' => ['sometimes', 'nullable', Rule::in(array_keys(Profile::GOALS))],
            'target_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:30', 'max:400'],

            'meal_hours' => ['sometimes', 'nullable', 'array'],

            // 🚨 I VALORI si validano, le chiavi no (vedi prepareForValidation).
            // Un `H:i` malformato salvato qui non darebbe errore: farebbe
            // sbagliare il pasto a ogni inserimento di cibo, in silenzio, e
            // nessuno collegherebbe le due cose.
            'meal_hours.*' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Scarta le chiavi di `meal_hours` che non sono pasti conosciuti.
     *
     * ⚠️ **Si scartano, non si rifiutano.** Le chiavi sono i `MealType`, e
     * un'app di una versione precedente che ne manda una in piu' non deve
     * vedersi rifiutare il salvataggio dell'intero profilo — altezza, obiettivo
     * e data di nascita comprese — per un campo secondario che sa ignorare.
     */
    protected function prepareForValidation(): void
    {
        $orari = $this->input('meal_hours');

        if (! is_array($orari)) {
            return;
        }

        $ammesse = array_column(MealType::cases(), 'value');

        $this->merge([
            'meal_hours' => array_intersect_key($orari, array_flip($ammesse)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meal_hours.*.date_format' => __('Gli orari dei pasti vanno scritti come 07:30.'),
            'birthdate.before' => __('La data di nascita deve essere nel passato.'),
        ];
    }
}
