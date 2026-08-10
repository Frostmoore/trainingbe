<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La scheda che l'iscritto si scrive da solo — C2.2.
 *
 * L'autorizzazione non sta qui: `store()` crea sempre una scheda propria, e
 * `update()` passa da `WorkoutPlanPolicy`, che è il posto dove vive la
 * distinzione fra «scheda mia» e «scheda del trainer».
 */
class WorkoutPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Il tetto non è pignoleria: `syncRows()` scrive una riga per
            // elemento, e senza limite una richiesta malformata (o un ciclo
            // nell'app) può generare migliaia di righe in una transazione sola.
            'exercises' => ['sometimes', 'array', 'max:60'],

            // 🚨 Si manda il NOME, non l'id. L'app non deve conoscere la
            // libreria per salvare una scheda: la riconciliazione è compito di
            // `ExerciseMatcher`, che sa che «panca piana bilanciere» e «Bench
            // press» sono la stessa cosa. Un id obbligatorio costringerebbe
            // l'app a creare l'esercizio prima, in due richieste che possono
            // fallire a metà.
            'exercises.*.name' => ['required', 'string', 'max:160'],

            'exercises.*.sets' => ['nullable', 'integer', 'min:1', 'max:30'],

            /*
             * 🚨 `reps` è una STRINGA e deve restarlo.
             *
             * «8-12», «cedimento», «max», «10+10» sono prescrizioni legittime e
             * frequentissime. Chi la converte in intero credendo di correggere
             * una svista rompe metà delle schede reali — ed è lo stesso motivo
             * per cui la regola 3 del prompt di importazione PDF lo dice
             * esplicitamente.
             */
            'exercises.*.reps' => ['nullable', 'string', 'max:32'],

            'exercises.*.rest_sec' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'exercises.*.duration_sec' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'exercises.*.target_weight' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'exercises.*.notes' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Dai un nome alla scheda.'),
            'exercises.*.name.required' => __('Ogni esercizio deve avere un nome.'),
            'exercises.max' => __('Una scheda non può avere più di 60 esercizi.'),
        ];
    }

    /**
     * Le righe già ripulite delle voci senza nome.
     *
     * L'editor dell'app tiene volentieri una riga vuota in fondo, pronta da
     * compilare: rifiutare il salvataggio per quella sarebbe un attrito
     * gratuito, quindi si scarta prima di validare.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(): array
    {
        $righe = $this->validated()['exercises'] ?? [];

        return array_values(array_filter(
            $righe,
            static fn (array $r): bool => trim((string) ($r['name'] ?? '')) !== '',
        ));
    }

    protected function prepareForValidation(): void
    {
        $righe = $this->input('exercises');

        if (! is_array($righe)) {
            return;
        }

        $this->merge([
            'exercises' => array_values(array_filter(
                $righe,
                static fn (mixed $r): bool => is_array($r) && trim((string) ($r['name'] ?? '')) !== '',
            )),
        ]);
    }
}
